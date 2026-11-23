<?php

/**
 * Unit tests for PublishTransition: which transition publishes from which stored state, the date passed in the
 * same call, and the refusal before anything is written.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-publication-is-public-only-in-the-published-state-and-every-publish-path-moves-the-lifecycle-req-plc-003
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use OCA\OpenCatalogi\Service\Publication\EmbargoGuard;
use OCA\OpenCatalogi\Service\Publication\PublicationDocumentCount;
use OCA\OpenCatalogi\Service\Publication\PublicationReviewPolicy;
use OCA\OpenCatalogi\Service\Publication\PublishRefusedException;
use OCA\OpenCatalogi\Service\Publication\PublishTransition;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\PublishTransition
 * @covers \OCA\OpenCatalogi\Service\Publication\PublishRefusedException
 * @uses \OCA\OpenCatalogi\Service\Publication\PublicationReviewPolicy
 */
class PublishTransitionTest extends TestCase {

	/** The document counter the subject gets, or null for a plain double. */
	private ?PublicationDocumentCount $documents = null;

	/**
	 * The transitions the engine double ran.
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public array $runs = [];

	/**
	 * A PublishTransition over an engine double, with the review setting given.
	 *
	 * @param string $reviewRequired The stored `publication_review_required` value.
	 *
	 * @return PublishTransition The subject.
	 */
	private function subject(string $reviewRequired): PublishTransition {
		$engine = new class ($this) {
			public function __construct(private readonly PublishTransitionTest $test) {
			}

			public function transition(string $objectId, string $action, array $data = []): object {
				$this->test->runs[] = [$objectId, $action, $data];
				return (object)['id' => $objectId];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(PublishTransition::ENGINE)->willReturn($engine);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($reviewRequired);

		return new PublishTransition(
			container: $container,
			review: new PublicationReviewPolicy(appConfig: $config),
			embargo: new EmbargoGuard(
				config: $config,
				groups: $this->createMock(originalClassName: IGroupManager::class),
				logger: $this->createMock(originalClassName: LoggerInterface::class)
			),
			documents: $this->documents ?? $this->createMock(originalClassName: PublicationDocumentCount::class)
		);
	}//end subject()

	/**
	 * REQ-PLC-003: approved publishes with `publish`; a draft only while review is off; nothing else publishes.
	 *
	 * @return void
	 */
	public function testTheTransitionFollowsTheStoredState(): void {
		$required = $this->subject(reviewRequired: 'true');
		$this->assertSame('publish', $required->actionFor(stored: 'approved'));
		$this->assertNull($required->actionFor(stored: 'draft'));
		$this->assertNull($required->actionFor(stored: ''));
		$this->assertNull($required->actionFor(stored: 'in_review'));
		$this->assertNull($required->actionFor(stored: 'archived'));

		$off = $this->subject(reviewRequired: 'false');
		$this->assertSame('publishWithoutReview', $off->actionFor(stored: ' draft '));
		$this->assertSame('publishWithoutReview', $off->actionFor(stored: ''));
		$this->assertSame('publish', $off->actionFor(stored: 'approved'));
		$this->assertNull($off->actionFor(stored: 'in_review'));
	}//end testTheTransitionFollowsTheStoredState()

	/**
	 * REQ-PLC-003: the publication date goes in the same call, and a depublication date is cleared.
	 *
	 * @return void
	 */
	public function testPublishPassesTheDateInTheSameCall(): void {
		$answer = $this->subject(reviewRequired: 'true')->publish(id: 'pub-1', stored: 'approved', publicationDate: '2026-11-17T10:00:00+00:00');

		$this->assertSame('pub-1', $answer->id);
		$this->assertSame([['pub-1', 'publish', ['publicationDate' => '2026-11-17T10:00:00+00:00', 'depublicationDate' => null]]], $this->runs);
	}//end testPublishPassesTheDateInTheSameCall()

	/**
	 * REQ-PLC-003: a refused publish names the state and never reaches the engine.
	 *
	 * @return void
	 */
	public function testARefusedPublishNeverReachesTheEngine(): void {
		try {
			$this->subject(reviewRequired: 'true')->publish(id: 'pub-2', stored: '', publicationDate: '2026-11-17T10:00:00+00:00');
			$this->fail('A draft must not be published while review is required.');
		} catch (PublishRefusedException $e) {
			$this->assertSame('draft', $e->state);
			$this->assertSame('The transition publish is not allowed from the state draft.', $e->getMessage());
		}

		$this->assertSame([], $this->runs);
	}//end testARefusedPublishNeverReachesTheEngine()

	/**
	 * REQ-PSG-001: publishing now a publication dated ahead is vetoed with the embargo moment; one dated in the past is not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-1.2
	 */
	public function testTheEmbargoVetoesPublishingAScheduledPublicationNow(): void {
		$transition = $this->subject(reviewRequired: 'false');
		$caller = $this->createMock(originalClassName: IUser::class);
		$until = (new \DateTimeImmutable(datetime: 'tomorrow 09:00'))->format(format: DATE_ATOM);
		$now = (new \DateTimeImmutable())->format(format: DATE_ATOM);

		$this->assertSame(
			expected: ['code' => 'embargo-holds', 'property' => 'publicationDate', 'embargoUntil' => $until],
			actual: $transition->embargoVeto(stored: ['publicationDate' => $until], record: ['publicationDate' => $now], caller: $caller)
		);
		$this->assertNull(
			actual: $transition->embargoVeto(stored: ['publicationDate' => '2026-09-01T09:00:00+00:00'], record: ['publicationDate' => $now], caller: $caller)
		);
		$this->assertSame(expected: [], actual: $this->runs);
	}//end testTheEmbargoVetoesPublishingAScheduledPublicationNow()
	/**
	 * REQ-PSG-004: the document count is the counter's, for the publication it is given.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-4.1
	 */
	public function testTheDocumentCountIsAskedOfTheCounter(): void {
		$publication = new \stdClass();
		$this->documents = $this->createMock(originalClassName: PublicationDocumentCount::class);
		$this->documents->expects($this->once())->method('count')->with('p1', $publication)->willReturn(2);

		$this->assertSame(expected: 2, actual: $this->subject(reviewRequired: 'true')->documentCount(id: 'p1', publication: $publication));
	}//end testTheDocumentCountIsAskedOfTheCounter()

}//end class
