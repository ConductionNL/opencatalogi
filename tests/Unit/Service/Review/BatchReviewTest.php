<?php

/**
 * BatchReviewTest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/woo-review-surface/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service\Review;

use OCA\OpenCatalogi\Contract\TransparencyWorkflowServiceInterface;
use OCA\OpenCatalogi\Service\Portal\PublicationLinker;
use OCA\OpenCatalogi\Service\Review\BatchNotInReviewException;
use OCA\OpenCatalogi\Service\Woo\BatchPublicationWriter;
use OCA\OpenCatalogi\Service\Review\BatchReview;
use OCA\OpenCatalogi\Service\Review\PublicView;
use OCA\OpenRegister\Db\TaskSequence;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The reviewer sees each document as the public will, and approves only after opening every one.
 *
 * @covers \OCA\OpenCatalogi\Service\Review\BatchReview
 * @covers \OCA\OpenCatalogi\Service\Review\PublicView
 * @uses   \OCA\OpenCatalogi\Service\Woo\BatchPublicationWriter
 * @uses   \OCA\OpenCatalogi\Service\ReadsObjectProperties
 */
final class BatchReviewTest extends TestCase {

	private TransparencyWorkflowServiceInterface&MockObject $woo;

	/**
	 * The objects OpenRegister holds, by id; saves land in $saved.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $objects = [];

	/**
	 * Every saveObject() call: the object and its options.
	 *
	 * @var array<int, array{object: array<string, mixed>, options: mixed}>
	 */
	private array $saved = [];

	protected function setUp(): void {
		$this->objects = [
			'batch-1' => [
				'id' => 'batch-1',
				'title' => 'Woo-besluit wegaanleg',
				'caseReference' => 'WOO-2026-7',
				'wooCategory' => 'nonexistent',
				'status' => 'ready_for_review',
				'createdBy' => 'anna',
				'documents' => ['doc-open', 'doc-part', 'doc-closed'],
			],
			'doc-open' => ['id' => 'doc-open', 'fileName' => 'Besluit.pdf', 'assessment' => 'openbaar', 'documentReference' => '11'],
			'doc-part' => [
				'id' => 'doc-part',
				'fileName' => 'Bijlage.pdf',
				'assessment' => 'deels_openbaar',
				'documentReference' => '12',
				'anonymizedDocument' => '13',
				'redactionStatus' => 'verified',
				'redactionLog' => [['entityType' => 'PERSON', 'grounds' => ['5.1.2e']]],
			],
			'doc-closed' => ['id' => 'doc-closed', 'fileName' => 'Intern.pdf', 'assessment' => 'niet_openbaar', 'documentReference' => '14'],
		];
		$this->saved = [];

		$test = $this;
		$objectService = new class($test) {
			public function __construct(private readonly BatchReviewTest $test) {
			}

			public function find(string $id): array {
				return $this->test->object($id);
			}

			public function saveObject(array $object, mixed $options, bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->test->store($object, $options);
			}
		};

		$this->woo = $this->createMock(TransparencyWorkflowServiceInterface::class);
		$this->woo->method('getObjectService')->willReturn($objectService);
		$this->woo->method('getRegister')->willReturn('7');
		$this->woo->method('getBatchSchema')->willReturn('8');
		$this->woo->method('getAssessmentSchema')->willReturn('9');
		$this->woo->method('getBatch')->willReturnCallback(fn (string $batchId): array => $this->object($batchId));

	}//end setUp()

	/**
	 * Read an object as OpenRegister would.
	 *
	 * @param string $id The id.
	 *
	 * @return array<string, mixed>
	 */
	public function object(string $id): array {
		if (isset($this->objects[$id]) === false) {
			throw new RuntimeException('not found ' . $id);
		}

		return $this->objects[$id];

	}//end object()

	/**
	 * Store an object as OpenRegister would.
	 *
	 * @param array<string, mixed> $object  The object.
	 * @param mixed                $options The save options.
	 *
	 * @return array<string, mixed>
	 */
	public function store(array $object, mixed $options): array {
		$this->saved[] = ['object' => $object, 'options' => $options];
		$this->objects[(string)$object['id']] = $object;

		return $object;

	}//end store()

	/**
	 * The review as reviewer `$uid`, with files 11 and 13 resolvable.
	 *
	 * @param string|null $uid The reviewer.
	 *
	 * @return BatchReview
	 */
	private function review(?string $uid = 'rita'): BatchReview {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getFirstNodeById')->willReturnCallback(
			function (int $id): ?File {
				if (in_array($id, [11, 12, 13, 14], true) === false) {
					return null;
				}

				$file = $this->createMock(File::class);
				$file->method('getId')->willReturn($id);
				return $file;
			}
		);
		$writer = new BatchPublicationWriter(
			$root,
			$this->createMock(ContainerInterface::class),
			$this->createMock(PublicationLinker::class),
			$this->createMock(IL10N::class)
		);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $params): string => $route . ':' . $params['fileid']
		);

		return new BatchReview($this->woo, new PublicView($writer, $urls), $session, $this->createMock(LoggerInterface::class));

	}//end review()

	/**
	 * The preview names, per document, the file the public gets and the public fields.
	 *
	 * @return void
	 */
	public function testThePreviewCarriesThePublishedFileAndThePublicFields(): void {
		$preview = $this->review()->publicPreview('batch-1');
		$byId = array_column($preview['documents'], null, 'documentId');

		$this->assertSame('ready_for_review', $preview['status']);
		$this->assertSame('files.View.showFile:11', $byId['doc-open']['viewerUrl']);
		// The redacted file, never the original (12).
		$this->assertSame('files.View.showFile:13', $byId['doc-part']['viewerUrl']);
		$this->assertSame(
			['title', 'wooCategory', 'publicationKind', 'publicationDate', 'caseReference', 'documentTitle'],
			array_keys($byId['doc-open']['publicMetadata'])
		);
		$this->assertSame('Woo-besluit wegaanleg', $byId['doc-open']['publicMetadata']['title']);
		$this->assertSame('WOO-2026-7', $byId['doc-open']['publicMetadata']['caseReference']);
		$this->assertSame('Bijlage.pdf', $byId['doc-part']['publicMetadata']['documentTitle']);
		$this->assertSame([['entityType' => 'PERSON', 'grounds' => ['5.1.2e']]], $byId['doc-part']['redactionLog']);
		$this->assertArrayNotHasKey('status', $byId['doc-open']['publicMetadata']);
		$this->assertSame(['doc-open', 'doc-part'], array_column($preview['unseen'], 'documentId'));

	}//end testThePreviewCarriesThePublishedFileAndThePublicFields()

	/**
	 * A withheld document has no viewer and no public metadata, and needs no preview.
	 *
	 * @return void
	 */
	public function testANietOpenbaarDocumentHasNoViewer(): void {
		$preview = $this->review()->publicPreview('batch-1');
		$byId = array_column($preview['documents'], null, 'documentId');

		$this->assertNull($byId['doc-closed']['viewerUrl']);
		$this->assertNull($byId['doc-closed']['publicMetadata']);
		$this->assertNotContains('doc-closed', array_column($preview['unseen'], 'documentId'));

	}//end testANietOpenbaarDocumentHasNoViewer()

	/**
	 * A partly public document whose redaction is not verified shows no file at all.
	 *
	 * @return void
	 */
	public function testAnUnverifiedRedactionHasNoViewer(): void {
		$this->objects['doc-part']['redactionStatus'] = 'detected';

		$byId = array_column($this->review()->publicPreview('batch-1')['documents'], null, 'documentId');

		$this->assertNull($byId['doc-part']['viewerUrl']);

	}//end testAnUnverifiedRedactionHasNoViewer()

	/**
	 * The scenario: one of two previews opened, approve is refused naming the other.
	 *
	 * @return void
	 */
	public function testApproveIsRefusedUntilEveryPreviewWasSeen(): void {
		$review = $this->review();
		$review->markPreviewSeen('batch-1', 'doc-open');
		$this->woo->expects($this->never())->method('runBatchTransition');

		try {
			$review->approveBatch('batch-1');
			$this->fail('approve was accepted with an unseen preview');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('Bijlage.pdf', $e->getMessage());
			$this->assertStringNotContainsString('Besluit.pdf', $e->getMessage());
		}

	}//end testApproveIsRefusedUntilEveryPreviewWasSeen()

	/**
	 * Once the reviewer opened both, approve runs the transition.
	 *
	 * @return void
	 */
	public function testApproveRunsTheTransitionOnceEveryPreviewWasSeen(): void {
		$review = $this->review();
		$review->markPreviewSeen('batch-1', 'doc-open');
		$review->markPreviewSeen('batch-1', 'doc-part');
		$this->woo->expects($this->once())
			->method('runBatchTransition')
			->with('batch-1', 'approve')
			->willReturn(['status' => 'approved']);

		$this->assertSame(['status' => 'approved'], $review->approveBatch('batch-1'));

	}//end testApproveRunsTheTransitionOnceEveryPreviewWasSeen()

	/**
	 * What one reviewer opened does not count for another.
	 *
	 * @return void
	 */
	public function testPreviewsSeenArePerReviewer(): void {
		$this->review('rita')->markPreviewSeen('batch-1', 'doc-open');
		$this->review('rita')->markPreviewSeen('batch-1', 'doc-part');

		$this->assertSame(['rita' => ['doc-open', 'doc-part']], $this->objects['batch-1']['previewsSeen']);
		$this->assertSame([], $this->review()->unseenPreviews($this->objects['batch-1'], 'rita'));
		$this->assertCount(2, $this->review()->unseenPreviews($this->objects['batch-1'], 'piet'));
		$this->assertCount(2, $this->review()->unseenPreviews($this->objects['batch-1'], ''));
		$this->assertSame('8', $this->saved[0]['options']->target->schema);

	}//end testPreviewsSeenArePerReviewer()

	/**
	 * A document of another batch, or a batch out of review, records nothing.
	 *
	 * @return void
	 */
	public function testPreviewSeenOutsideTheBatchOrTheReviewIsRefused(): void {
		try {
			$this->review()->markPreviewSeen('batch-1', 'doc-elsewhere');
			$this->fail('a foreign document was recorded');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('doc-elsewhere', $e->getMessage());
		}

		$this->objects['batch-1']['status'] = 'in_progress';
		$this->expectException(BatchNotInReviewException::class);
		try {
			$this->review()->markPreviewSeen('batch-1', 'doc-open');
		} finally {
			$this->assertSame([], $this->saved);
		}

	}//end testPreviewSeenOutsideTheBatchOrTheReviewIsRefused()

	/**
	 * Send-back resets the assessment and keeps the reason with who and when.
	 *
	 * @return void
	 */
	public function testSendBackResetsTheAssessmentWithTheReason(): void {
		$result = $this->review('rita')->sendBack('batch-1', 'doc-part', ' Naam op pagina 4 niet gelakt ');

		$this->assertSame('te_beoordelen', $result['assessment']);
		$this->assertSame('Naam op pagina 4 niet gelakt', $result['sentBackReason']['reason']);
		$this->assertSame('rita', $result['sentBackReason']['by']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $result['sentBackReason']['at']);
		$this->assertSame('9', $this->saved[0]['options']->target->schema);
		$this->assertSame('doc-part', $this->saved[0]['options']->uuid);

	}//end testSendBackResetsTheAssessmentWithTheReason()

	/**
	 * Send-back is allowed only while the batch is in review, and needs a reason.
	 *
	 * @return void
	 */
	public function testSendBackOutsideReviewIsRefused(): void {
		try {
			$this->review()->sendBack('batch-1', 'doc-part', '  ');
			$this->fail('a send-back without a reason was accepted');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('reason', $e->getMessage());
		}

		$this->objects['batch-1']['status'] = 'approved';
		try {
			$this->review()->sendBack('batch-1', 'doc-part', 'Nog een BSN');
			$this->fail('a send-back outside review was accepted');
		} catch (BatchNotInReviewException $e) {
			$this->assertSame('The batch is not in review', $e->getMessage());
		}

		$this->assertSame([], $this->saved);

	}//end testSendBackOutsideReviewIsRefused()

	/**
	 * Reject runs the transition with the trimmed reason, and refuses an empty one.
	 *
	 * @return void
	 */
	public function testRejectRunsTheTransitionWithTheReason(): void {
		$this->woo->expects($this->once())
			->method('runBatchTransition')
			->with('batch-1', 'reject', ['reason' => 'Bijlage 3 bevat nog een BSN'])
			->willReturn(['status' => 'in_progress']);

		$this->assertSame(['status' => 'in_progress'], $this->review()->rejectBatch('batch-1', ' Bijlage 3 bevat nog een BSN '));

		$this->expectException(RuntimeException::class);
		$this->review()->rejectBatch('batch-1', '');

	}//end testRejectRunsTheTransitionWithTheReason()

	/**
	 * Only the `batchReview` chain is a batch review; an unreadable sequence is not.
	 *
	 * @return void
	 */
	public function testOnlyTheBatchReviewChainCounts(): void {
		$review = new TaskSequence();
		$review->setChainKey('batchReview');
		$other = new TaskSequence();
		$other->setChainKey('budgetApproval');
		$mapper = new class($review, $other) {
			public function __construct(private readonly TaskSequence $review, private readonly TaskSequence $other) {
			}

			public function findByUuid(string $uuid): TaskSequence {
				return match ($uuid) {
					'seq-review' => $this->review,
					'seq-other' => $this->other,
					default => throw new RuntimeException('missing'),
				};
			}
		};
		$this->woo->method('getTaskSequenceMapper')->willReturn($mapper);

		$this->assertTrue($this->review()->isBatchReviewSequence('seq-review'));
		$this->assertFalse($this->review()->isBatchReviewSequence('seq-other'));
		$this->assertFalse($this->review()->isBatchReviewSequence('seq-gone'));
		$this->assertFalse($this->review()->isBatchReviewSequence(''));

	}//end testOnlyTheBatchReviewChainCounts()

	/**
	 * Nobody signed in: no preview is recorded and nothing is approved.
	 *
	 * @return void
	 */
	public function testAReviewNeedsAPerson(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Not logged in');

		$this->review(null)->markPreviewSeen('batch-1', 'doc-open');

	}//end testAReviewNeedsAPerson()
}//end class
