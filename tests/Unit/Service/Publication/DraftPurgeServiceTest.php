<?php

/**
 * Tests for the permanent delete of a never released draft.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use OCA\OpenCatalogi\Contract\OpenRegisterServicesInterface;
use OCA\OpenCatalogi\Exception\DraftPurgeFailedException;
use OCA\OpenCatalogi\Exception\DraftPurgeRefusedException;
use OCA\OpenCatalogi\Service\Publication\DraftPurgeService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Prove, list, remove, then log once.
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\DraftPurgeService
 * @covers \OCA\OpenCatalogi\Exception\DraftPurgeRefusedException
 * @covers \OCA\OpenCatalogi\Exception\DraftPurgeFailedException
 */
class DraftPurgeServiceTest extends TestCase {

	/** @var array<string, mixed> The publication's properties. */
	private array $publication = ['id' => 'pub-1', 'title' => 'Draft decision', 'status' => 'draft'];

	/** @var array<string, array<int, array<string, mixed>>> Search results by schema config key. */
	private array $rows = [];

	/** @var array<int, array{id: int, name: string}> The files on the publication. */
	private array $files = [['id' => 11, 'name' => 'decision.pdf'], ['id' => 12, 'name' => 'annex.pdf']];

	/** @var array<int, mixed> The audit trail of the publication. */
	private array $trail = [];

	/** Whether reading the audit trail throws. */
	private bool $trailUnreadable = false;

	/** Whether listing the files throws. */
	private bool $filesUnreadable = false;

	/** Whether writing the audit entry throws. */
	private bool $auditFails = false;

	/** The file id whose removal fails, 0 for none. */
	private int $failingFile = 0;

	/** @var array<int, string> Every removal, in order, as `kind:id`. */
	private array $removals = [];

	/** @var array<int, array<string, mixed>> Every audit entry written. */
	private array $audits = [];

	/** @var array<string, string> The configured keys; a missing key is unconfigured. */
	private array $configured = [];

	protected function setUp(): void {
		parent::setUp();
		foreach (['publication_register', 'publication_schema', 'document_reference_schema', 'publication_process_schema', 'metadata_suggestion_schema', 'depublication_schema', 'woo_register', 'woo_assessment_schema'] as $key) {
			$this->configured[$key] = $key;
		}

		$this->rows = ['document_reference_schema' => [['id' => 'ref-1', 'title' => 'Case file']]];
	}//end setUp()

	/**
	 * Record a removal; false when it is the one set to fail.
	 *
	 * @param string $entry `kind:id`.
	 * @param bool $fails Whether it fails.
	 *
	 * @return bool
	 */
	public function recordRemoval(string $entry, bool $fails = false): bool {
		if ($fails === true) {
			return false;
		}

		$this->removals[] = $entry;
		return true;
	}//end recordRemoval()

	/**
	 * Record an audit entry.
	 *
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return void
	 */
	public function recordAudit(array $entry): void {
		if ($this->auditFails === true) {
			throw new RuntimeException('audit down');
		}

		$this->audits[] = $entry;
	}//end recordAudit()

	/**
	 * The search answer for a query.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return array<string, mixed>
	 */
	public function search(array $query): array {
		$schema = (string)($query['@self']['schema'] ?? '');
		if ($schema === 'woo_assessment_schema') {
			return ['results' => ($this->rows['assessment:' . $query['documentReference']] ?? [])];
		}

		if (($this->rows[$schema] ?? null) === 'throw') {
			throw new RuntimeException('search down');
		}

		return ['results' => ($this->rows[$schema] ?? [])];
	}//end search()

	/**
	 * The audit trail answer.
	 *
	 * @return array<int, mixed>
	 */
	public function trail(): array {
		if ($this->trailUnreadable === true) {
			throw new RuntimeException('audit trail down');
		}

		return $this->trail;
	}//end trail()

	/**
	 * Whether a file id is the one set to fail.
	 *
	 * @param int $id The file id.
	 *
	 * @return bool
	 */
	public function fileFails(int $id): bool {
		return $id === $this->failingFile;
	}//end fileFails()

	/**
	 * The files on the publication as node doubles.
	 *
	 * @return array<int, object>
	 */
	public function nodes(): array {
		if ($this->filesUnreadable === true) {
			throw new RuntimeException('files down');
		}

		return array_map(
			static fn (array $file): object => new class ($file['id'], $file['name']) {
				public function __construct(private readonly int $id, private readonly string $name) {
				}

				public function getId(): int {
					return $this->id;
				}

				public function getName(): string {
					return $this->name;
				}
			},
			$this->files
		);
	}//end nodes()

	/**
	 * The service wired to the doubles.
	 *
	 * @return DraftPurgeService
	 */
	private function service(): DraftPurgeService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->configured[$key] ?? $default)
		);

		$test = $this;
		$objects = new class ($test) {
			public function __construct(private readonly DraftPurgeServiceTest $test) {
			}

			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->test->search(query: $query);
			}

			public function deleteObject(object $deletion, bool $_rbac = true, bool $_multitenancy = true): bool {
				if ($deletion->permanent !== true || $_rbac !== false) {
					throw new RuntimeException('not a permanent system delete');
				}

				return $this->test->recordRemoval(entry: $deletion->target->schema . ':' . $deletion->uuid);
			}
		};
		$files = new class ($test) {
			public function __construct(private readonly DraftPurgeServiceTest $test) {
			}

			public function getFiles(object $object): array {
				return $this->test->nodes();
			}

			public function deleteFile(int $file, ?object $object = null): bool {
				return $this->test->recordRemoval(entry: 'file:' . $file, fails: $this->test->fileFails(id: $file));
			}
		};
		$audit = new class ($test) {
			public function __construct(private readonly DraftPurgeServiceTest $test) {
			}

			public function findByObjectUntil(string $objectUuid): array {
				return $this->test->trail();
			}

			public function createAuditTrail(?object $old = null, ?object $new = null, ?string $action = null, ?array $cascadeContext = null): object {
				$this->test->recordAudit(entry: ['old' => $old, 'new' => $new, 'action' => $action, 'context' => $cascadeContext]);
				return new \stdClass();
			}
		};

		/** @var OpenRegisterServicesInterface&MockObject $openRegister */
		$openRegister = $this->createMock(OpenRegisterServicesInterface::class);
		$openRegister->method('installedObjectService')->willReturn($objects);
		$openRegister->method('installedService')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\OpenRegister\Service\FileService' => $files,
				'OCA\OpenRegister\Db\AuditTrailMapper' => $audit,
			}
		);

		return new DraftPurgeService(config: $config, openRegister: $openRegister);
	}//end service()

	/**
	 * The publication as OpenRegister answers it.
	 *
	 * @return object
	 */
	private function publicationObject(): object {
		return new class ($this->publication) implements \JsonSerializable {
			public function __construct(private readonly array $data) {
			}

			public function jsonSerialize(): array {
				return $this->data;
			}
		};
	}//end publicationObject()

	/**
	 * Run the purge expecting a refusal, and return it.
	 *
	 * @return DraftPurgeRefusedException
	 */
	private function refusal(): DraftPurgeRefusedException {
		try {
			$this->service()->purge(publication: $this->publicationObject());
		} catch (DraftPurgeRefusedException $e) {
			$this->assertSame([], $this->removals, 'A refusal removes nothing.');
			$this->assertSame([], $this->audits, 'A refusal writes no audit entry.');
			return $e;
		}

		$this->fail('The purge was not refused.');
	}//end refusal()

	public function testADraftAndEverythingOnItGoes(): void {
		$this->rows['assessment:11'] = [['id' => 'assess-1']];

		$removed = $this->service()->purge(publication: $this->publicationObject());

		$this->assertSame(
			['document_reference_schema:ref-1', 'woo_assessment_schema:assess-1', 'file:11', 'file:12', 'publication_schema:pub-1'],
			$this->removals
		);
		$this->assertSame('publication', $removed[4]['kind']);
		$this->assertSame(['kind' => 'documentReference', 'id' => 'ref-1', 'name' => 'Case file'], $removed[0]);
		$this->assertCount(1, $this->audits, 'One summary audit entry.');
		$this->assertSame(DraftPurgeService::ACTION, $this->audits[0]['action']);
		$this->assertNull($this->audits[0]['new']);
		$this->assertSame(['id' => 'pub-1', 'title' => 'Draft decision'], $this->audits[0]['context']['publication']);
		$this->assertSame($removed, $this->audits[0]['context']['removed']);
	}//end testADraftAndEverythingOnItGoes()

	public function testADraftInReviewGoesToo(): void {
		$this->publication['status'] = 'in_review';
		$this->files = [];
		$this->configured = array_diff_key($this->configured, ['document_reference_schema' => true]);

		$removed = $this->service()->purge(publication: $this->publicationObject());

		$this->assertSame(['publication_schema:pub-1'], $this->removals, 'An unconfigured side schema holds nothing to remove.');
		$this->assertCount(1, $removed);
	}//end testADraftInReviewGoesToo()

	public function testAPublicationThatWasEverPublishedIsRefused(): void {
		$this->trail = [
			['changed' => ['title' => ['old' => 'a', 'new' => 'b']], 'created' => '2026-10-01T10:00:00+00:00'],
			['changed' => ['status' => ['old' => 'published', 'new' => 'draft']], 'created' => '2026-10-20T10:00:00+00:00'],
		];

		$refusal = $this->refusal();

		$this->assertSame(DraftPurgeRefusedException::PUBLISHED_IN_TRAIL, $refusal->reason);
		$this->assertSame('2026-10-20T10:00:00+00:00', $refusal->evidence, 'The refusal names the earlier release.');
	}//end testAPublicationThatWasEverPublishedIsRefused()

	public function testATrailEntityRecordingAPublishedCreateIsRefused(): void {
		$this->trail = [
			new class implements \JsonSerializable {
				public function getChanged(): array {
					return ['object' => ['new' => ['status' => 'published']]];
				}

				public function jsonSerialize(): array {
					return ['created' => new \DateTimeImmutable('2026-09-01T08:00:00+00:00')];
				}
			},
		];

		$refusal = $this->refusal();

		$this->assertSame(DraftPurgeRefusedException::PUBLISHED_IN_TRAIL, $refusal->reason);
		$this->assertSame('2026-09-01T08:00:00+00:00', $refusal->evidence);
	}//end testATrailEntityRecordingAPublishedCreateIsRefused()

	public function testAFirstReleaseStampIsRefused(): void {
		$this->publication['firstReleasedAt'] = '2026-10-01T09:00:00+00:00';

		$refusal = $this->refusal();

		$this->assertSame(DraftPurgeRefusedException::RELEASED, $refusal->reason);
		$this->assertSame('2026-10-01T09:00:00+00:00', $refusal->evidence);
	}//end testAFirstReleaseStampIsRefused()

	public function testADepublicationNamingItIsRefused(): void {
		$this->rows['depublication_schema'] = [['id' => 'dep-7']];

		$refusal = $this->refusal();

		$this->assertSame(DraftPurgeRefusedException::WITHDRAWN, $refusal->reason);
		$this->assertSame('dep-7', $refusal->evidence);
	}//end testADepublicationNamingItIsRefused()

	public function testAPublishedStateIsRefused(): void {
		$this->publication['status'] = 'published';

		$refusal = $this->refusal();

		$this->assertSame(DraftPurgeRefusedException::NOT_A_DRAFT, $refusal->reason);
		$this->assertSame('published', $refusal->evidence);
	}//end testAPublishedStateIsRefused()

	public function testAnUnreadableAuditTrailRefuses(): void {
		$this->trailUnreadable = true;

		$refusal = $this->refusal();

		$this->assertSame(DraftPurgeRefusedException::UNVERIFIABLE, $refusal->reason);
		$this->assertSame('audit-trail', $refusal->evidence);
	}//end testAnUnreadableAuditTrailRefuses()

	public function testAnAuditEntryThatCannotBeReadRefuses(): void {
		$this->trail = ['not an entry'];

		$this->assertSame('audit-trail', $this->refusal()->evidence);
	}//end testAnAuditEntryThatCannotBeReadRefuses()

	public function testADepublicationListThatCannotBeReadRefuses(): void {
		$this->rows['depublication_schema'] = 'throw';

		$this->assertSame('depublication', $this->refusal()->evidence);
	}//end testADepublicationListThatCannotBeReadRefuses()

	public function testASideRecordListThatCannotBeReadRefuses(): void {
		$this->rows['publication_process_schema'] = 'throw';

		$refusal = $this->refusal();

		$this->assertSame(DraftPurgeRefusedException::UNVERIFIABLE, $refusal->reason);
		$this->assertSame('publicationProcess', $refusal->evidence);
	}//end testASideRecordListThatCannotBeReadRefuses()

	public function testAListAtTheLimitRefusesBecauseItMayBeIncomplete(): void {
		$this->rows['metadata_suggestion_schema'] = array_fill(0, 500, ['id' => 'sug']);

		$this->assertSame('metadataSuggestion', $this->refusal()->evidence);
	}//end testAListAtTheLimitRefusesBecauseItMayBeIncomplete()

	public function testFilesThatCannotBeListedRefuse(): void {
		$this->filesUnreadable = true;

		$this->assertSame('files', $this->refusal()->evidence);
	}//end testFilesThatCannotBeListedRefuse()

	public function testAPublicationWithoutAnIdRefuses(): void {
		$this->publication['id'] = '';

		$this->assertSame('id', $this->refusal()->evidence);
	}//end testAPublicationWithoutAnIdRefuses()

	public function testAFailurePartWayKeepsThePublicationAndNamesWhatWent(): void {
		$this->failingFile = 12;

		try {
			$this->service()->purge(publication: $this->publicationObject());
			$this->fail('The purge did not stop.');
		} catch (DraftPurgeFailedException $e) {
			$this->assertSame(['document_reference_schema:ref-1', 'file:11'], $this->removals);
			$this->assertSame(['ref-1', '11'], array_column($e->removed, 'id'), 'The answer names what went.');
			$this->assertSame(['kind' => 'file', 'id' => '12', 'name' => 'annex.pdf'], $e->failedOn);
			$this->assertTrue($e->publicationKept);
			$this->assertNotContains('publication_schema:pub-1', $this->removals, 'The publication is kept.');
			$this->assertSame([], $this->audits);
		}
	}//end testAFailurePartWayKeepsThePublicationAndNamesWhatWent()

	public function testAnAuditEntryThatCannotBeWrittenIsReportedWithEverythingRemoved(): void {
		$this->auditFails = true;

		try {
			$this->service()->purge(publication: $this->publicationObject());
			$this->fail('The failed audit write was not reported.');
		} catch (DraftPurgeFailedException $e) {
			$this->assertFalse($e->publicationKept);
			$this->assertSame('audit', $e->failedOn['kind']);
			$this->assertCount(4, $e->removed);
		}
	}//end testAnAuditEntryThatCannotBeWrittenIsReportedWithEverythingRemoved()
}//end class
