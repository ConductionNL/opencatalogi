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
use OCA\OpenCatalogi\Service\Publication\DraftPurgeProof;
use OCA\OpenCatalogi\Service\Publication\DraftPurgeRecords;
use OCA\OpenCatalogi\Service\Publication\DraftPurgeService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Prove, list, remove, then log once.
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\DraftPurgeService
 * @covers \OCA\OpenCatalogi\Service\Publication\DraftPurgeRecords
 * @covers \OCA\OpenCatalogi\Service\Publication\DraftPurgeProof
 * @covers \OCA\OpenCatalogi\Exception\DraftPurgeRefusedException
 * @covers \OCA\OpenCatalogi\Exception\DraftPurgeFailedException
 */
class DraftPurgeServiceTest extends TestCase {

	/**
	 * mixed> The publication's properties.
	 *
	 * @var array<string,
	 */
	private array $publication = ['id' => 'pub-1', 'title' => 'Draft decision', 'status' => 'draft'];

	/**
	 * array<int, array<string, mixed>>> Search results by schema config key.
	 *
	 * @var array<string,
	 */
	private array $rows = [];

	/**
	 * array{id: int, name: string}> The files on the publication.
	 *
	 * @var array<int,
	 */
	private array $files = [['id' => 11, 'name' => 'decision.pdf'], ['id' => 12, 'name' => 'annex.pdf']];

	/**
	 * mixed> The audit trail of the publication.
	 *
	 * @var array<int,
	 */
	private array $trail = [];

	/**
	 * Whether reading the audit trail throws.
	 *
	 * @var bool
	 */
	private bool $trailUnreadable = false;

	/**
	 * Whether listing the files throws.
	 *
	 * @var bool
	 */
	private bool $filesUnreadable = false;

	/**
	 * Whether writing the audit entry throws.
	 *
	 * @var bool
	 */
	private bool $auditFails = false;

	/**
	 * The file id whose removal fails, 0 for none.
	 *
	 * @var int
	 */
	private int $failingFile = 0;

	/**
	 * string> Every removal, in order, as `kind:id`.
	 *
	 * @var array<int,
	 */
	private array $removals = [];

	/**
	 * array<string, mixed>> Every audit entry written.
	 *
	 * @var array<int,
	 */
	private array $audits = [];

	/**
	 * string> The configured keys; a missing key is unconfigured.
	 *
	 * @var array<string,
	 */
	private array $configured = [];

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$keys = [
			'publication_register',
			'publication_schema',
			'document_reference_schema',
			'publication_process_schema',
			'metadata_suggestion_schema',
			'depublication_schema',
			'woo_register',
			'woo_assessment_schema',
		];
		foreach ($keys as $key) {
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
	 * What find() answers: the publication for pub-1 read with RBAC on in the configured register, and throws otherwise.
	 *
	 * @param string $id The id.
	 * @param bool $rbac Whether RBAC is on.
	 * @param string $register The register.
	 *
	 * @return object|null
	 */
	public function found(string $id, bool $rbac, string $register): ?object {
		if ($id === 'gone') {
			throw new DoesNotExistException('gone');
		}

		if ($id === 'down') {
			throw new RuntimeException('database down');
		}

		if ($rbac === true && $register === 'reg') {
			return $this->publicationObject();
		}

		return null;
	}//end found()

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
				/**
				 * Constructor.
				 *
				 * @param int $id The id.
				 * @param string $name The name.
				 */
				public function __construct(private readonly int $id, private readonly string $name) {
				}

				/**
				 * Get id.
				 *
				 * @return int
				 */
				public function getId(): int {
					return $this->id;
				}

				/**
				 * Get name.
				 *
				 * @return string
				 */
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
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->configured[$key] ?? $default)
		);

		$test = $this;
		$objects = new class ($test) {
			/**
			 * Constructor.
			 *
			 * @param DraftPurgeServiceTest $test The test.
			 */
			public function __construct(private readonly DraftPurgeServiceTest $test) {
			}

			/**
			 * Find.
			 *
			 * @param string $id The id.
			 * @param ?object $options The options.
			 * @param bool $_rbac The _rbac.
			 *
			 * @return ?object
			 */
			public function find(string $id, ?object $options = null, bool $_rbac = true): ?object {
				return $this->test->found(id: $id, rbac: $_rbac, register: (string)$options?->register);
			}

			/**
			 * Search objects paginated.
			 *
			 * @param array $query The query.
			 * @param bool $_rbac The _rbac.
			 * @param bool $_multitenancy The _multitenancy.
			 *
			 * @return array
			 */
			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->test->search(query: $query);
			}

			/**
			 * Delete object.
			 *
			 * @param object $deletion The deletion.
			 * @param bool $_rbac The _rbac.
			 * @param bool $_multitenancy The _multitenancy.
			 *
			 * @return bool
			 */
			public function deleteObject(object $deletion, bool $_rbac = true, bool $_multitenancy = true): bool {
				if ($deletion->permanent !== true || $_rbac !== false) {
					throw new RuntimeException('not a permanent system delete');
				}

				return $this->test->recordRemoval(entry: $deletion->target->schema . ':' . $deletion->uuid);
			}
		};
		$files = new class ($test) {
			/**
			 * Constructor.
			 *
			 * @param DraftPurgeServiceTest $test The test.
			 */
			public function __construct(private readonly DraftPurgeServiceTest $test) {
			}

			/**
			 * Get files.
			 *
			 * @param object $object The object.
			 *
			 * @return array
			 */
			public function getFiles(object $object): array {
				return $this->test->nodes();
			}

			/**
			 * Delete file.
			 *
			 * @param int $file The file.
			 * @param ?object $object The object.
			 *
			 * @return bool
			 */
			public function deleteFile(int $file, ?object $object = null): bool {
				return $this->test->recordRemoval(entry: 'file:' . $file, fails: $this->test->fileFails(id: $file));
			}
		};
		$audit = new class ($test) {
			/**
			 * Constructor.
			 *
			 * @param DraftPurgeServiceTest $test The test.
			 */
			public function __construct(private readonly DraftPurgeServiceTest $test) {
			}

			/**
			 * Find by object until.
			 *
			 * @param string $objectUuid The object uuid.
			 *
			 * @return array
			 */
			public function findByObjectUntil(string $objectUuid): array {
				return $this->test->trail();
			}

			/**
			 * Create audit trail.
			 *
			 * @param ?object $old The old.
			 * @param ?object $new The new.
			 * @param ?string $action The action.
			 * @param ?array $cascadeContext The cascade context.
			 *
			 * @return object
			 */
			public function createAuditTrail(?object $old = null, ?object $new = null, ?string $action = null, ?array $cascadeContext = null): object {
				$this->test->recordAudit(entry: ['old' => $old, 'new' => $new, 'action' => $action, 'context' => $cascadeContext]);
				return new \stdClass();
			}
		};

		$openRegister = $this->createMock(originalClassName: OpenRegisterServicesInterface::class);
		$openRegister->method('installedObjectService')->willReturn($objects);
		$openRegister->method('installedService')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\OpenRegister\Service\FileService' => $files,
				'OCA\OpenRegister\Db\AuditTrailMapper' => $audit,
			}
		);

		$openRegister->method('registerConfiguration')->willReturn(['register' => 'reg', 'schema' => 'pub']);

		$records = new DraftPurgeRecords(config: $config, openRegister: $openRegister);

		return new DraftPurgeService(records: $records, proof: new DraftPurgeProof(records: $records));
	}//end service()

	/**
	 * The publication as OpenRegister answers it.
	 *
	 * @return object
	 */
	public function publicationObject(): object {
		return new class ($this->publication) implements \JsonSerializable {
			/**
			 * Constructor.
			 *
			 * @param array $data The data.
			 */
			public function __construct(private readonly array $data) {
			}

			/**
			 * Json serialize.
			 *
			 * @return array
			 */
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
			$this->assertSame(expected: [], actual: $this->removals, message: 'A refusal removes nothing.');
			$this->assertSame(expected: [], actual: $this->audits, message: 'A refusal writes no audit entry.');
			return $e;
		}

		$this->fail(message: 'The purge was not refused.');
	}//end refusal()

	/**
	 * Find reads the publication with the callers rights.
	 *
	 * @return void
	 */
	public function testFindReadsThePublicationWithTheCallersRights(): void {
		$found = $this->service()->find(id: 'pub-1');
		$this->assertSame(expected: ['id' => 'pub-1', 'title' => 'Draft decision', 'status' => 'draft'], actual: $found?->jsonSerialize());
		$this->assertNull(actual: $this->service()->find(id: 'gone'), message: 'An id that is not there, or not readable, reads as absent.');
	}//end testFindReadsThePublicationWithTheCallersRights()

	/**
	 * Find lets an unreachable register throw.
	 *
	 * @return void
	 */
	public function testFindLetsAnUnreachableRegisterThrow(): void {
		$this->expectException(exception: RuntimeException::class);
		$this->service()->find(id: 'down');
	}//end testFindLetsAnUnreachableRegisterThrow()

	/**
	 * Adraft and everything on it goes.
	 *
	 * @return void
	 */
	public function testADraftAndEverythingOnItGoes(): void {
		$this->rows['assessment:11'] = [['id' => 'assess-1']];

		$removed = $this->service()->purge(publication: $this->publicationObject());

		$this->assertSame(
			expected: ['document_reference_schema:ref-1', 'woo_assessment_schema:assess-1', 'file:11', 'file:12', 'publication_schema:pub-1'],
			actual: $this->removals
		);
		$this->assertSame(expected: 'publication', actual: $removed[4]['kind']);
		$this->assertSame(expected: ['kind' => 'documentReference', 'id' => 'ref-1', 'name' => 'Case file'], actual: $removed[0]);
		$this->assertCount(expectedCount: 1, haystack: $this->audits, message: 'One summary audit entry.');
		$this->assertSame(expected: DraftPurgeService::ACTION, actual: $this->audits[0]['action']);
		$this->assertNull(actual: $this->audits[0]['new']);
		$this->assertSame(expected: ['id' => 'pub-1', 'title' => 'Draft decision'], actual: $this->audits[0]['context']['publication']);
		$this->assertSame(expected: $removed, actual: $this->audits[0]['context']['removed']);
	}//end testADraftAndEverythingOnItGoes()

	/**
	 * Adraft in review goes too.
	 *
	 * @return void
	 */
	public function testADraftInReviewGoesToo(): void {
		$this->publication['status'] = 'in_review';
		$this->files = [];
		$this->configured = array_diff_key($this->configured, ['document_reference_schema' => true]);

		$removed = $this->service()->purge(publication: $this->publicationObject());

		$this->assertSame(expected: ['publication_schema:pub-1'], actual: $this->removals, message: 'An unconfigured side schema holds nothing to remove.');
		$this->assertCount(expectedCount: 1, haystack: $removed);
	}//end testADraftInReviewGoesToo()

	/**
	 * Apublication that was ever published is refused.
	 *
	 * @return void
	 */
	public function testAPublicationThatWasEverPublishedIsRefused(): void {
		$this->trail = [
			['changed' => ['title' => ['old' => 'a', 'new' => 'b']], 'created' => '2026-10-01T10:00:00+00:00'],
			['changed' => ['status' => ['old' => 'published', 'new' => 'draft']], 'created' => '2026-10-20T10:00:00+00:00'],
		];

		$refusal = $this->refusal();

		$this->assertSame(expected: DraftPurgeRefusedException::PUBLISHED_IN_TRAIL, actual: $refusal->reason);
		$this->assertSame(expected: '2026-10-20T10:00:00+00:00', actual: $refusal->evidence, message: 'The refusal names the earlier release.');
	}//end testAPublicationThatWasEverPublishedIsRefused()

	/**
	 * Atrail entity recording apublished create is refused.
	 *
	 * @return void
	 */
	public function testATrailEntityRecordingAPublishedCreateIsRefused(): void {
		$this->trail = [
			new class implements \JsonSerializable {
				/**
				 * Get changed.
				 *
				 * @return array
				 */
				public function getChanged(): array {
					return ['object' => ['new' => ['status' => 'published']]];
				}

				/**
				 * Json serialize.
				 *
				 * @return array
				 */
				public function jsonSerialize(): array {
					return ['created' => new \DateTimeImmutable('2026-09-01T08:00:00+00:00')];
				}
			},
		];

		$refusal = $this->refusal();

		$this->assertSame(expected: DraftPurgeRefusedException::PUBLISHED_IN_TRAIL, actual: $refusal->reason);
		$this->assertSame(expected: '2026-09-01T08:00:00+00:00', actual: $refusal->evidence);
	}//end testATrailEntityRecordingAPublishedCreateIsRefused()

	/**
	 * Afirst release stamp is refused.
	 *
	 * @return void
	 */
	public function testAFirstReleaseStampIsRefused(): void {
		$this->publication['firstReleasedAt'] = '2026-10-01T09:00:00+00:00';

		$refusal = $this->refusal();

		$this->assertSame(expected: DraftPurgeRefusedException::RELEASED, actual: $refusal->reason);
		$this->assertSame(expected: '2026-10-01T09:00:00+00:00', actual: $refusal->evidence);
	}//end testAFirstReleaseStampIsRefused()

	/**
	 * Adepublication naming it is refused.
	 *
	 * @return void
	 */
	public function testADepublicationNamingItIsRefused(): void {
		$this->rows['depublication_schema'] = [['id' => 'dep-7']];

		$refusal = $this->refusal();

		$this->assertSame(expected: DraftPurgeRefusedException::WITHDRAWN, actual: $refusal->reason);
		$this->assertSame(expected: 'dep-7', actual: $refusal->evidence);
	}//end testADepublicationNamingItIsRefused()

	/**
	 * Apublished state is refused.
	 *
	 * @return void
	 */
	public function testAPublishedStateIsRefused(): void {
		$this->publication['status'] = 'published';

		$refusal = $this->refusal();

		$this->assertSame(expected: DraftPurgeRefusedException::NOT_A_DRAFT, actual: $refusal->reason);
		$this->assertSame(expected: 'published', actual: $refusal->evidence);
	}//end testAPublishedStateIsRefused()

	/**
	 * An unreadable audit trail refuses.
	 *
	 * @return void
	 */
	public function testAnUnreadableAuditTrailRefuses(): void {
		$this->trailUnreadable = true;

		$refusal = $this->refusal();

		$this->assertSame(expected: DraftPurgeRefusedException::UNVERIFIABLE, actual: $refusal->reason);
		$this->assertSame(expected: 'audit-trail', actual: $refusal->evidence);
	}//end testAnUnreadableAuditTrailRefuses()

	/**
	 * An audit entry that cannot be read refuses.
	 *
	 * @return void
	 */
	public function testAnAuditEntryThatCannotBeReadRefuses(): void {
		$this->trail = ['not an entry'];

		$this->assertSame(expected: 'audit-trail', actual: $this->refusal()->evidence);
	}//end testAnAuditEntryThatCannotBeReadRefuses()

	/**
	 * Adepublication list that cannot be read refuses.
	 *
	 * @return void
	 */
	public function testADepublicationListThatCannotBeReadRefuses(): void {
		$this->rows['depublication_schema'] = 'throw';

		$this->assertSame(expected: 'depublication', actual: $this->refusal()->evidence);
	}//end testADepublicationListThatCannotBeReadRefuses()

	/**
	 * Aside record list that cannot be read refuses.
	 *
	 * @return void
	 */
	public function testASideRecordListThatCannotBeReadRefuses(): void {
		$this->rows['publication_process_schema'] = 'throw';

		$refusal = $this->refusal();

		$this->assertSame(expected: DraftPurgeRefusedException::UNVERIFIABLE, actual: $refusal->reason);
		$this->assertSame(expected: 'publicationProcess', actual: $refusal->evidence);
	}//end testASideRecordListThatCannotBeReadRefuses()

	/**
	 * Alist at the limit refuses because it may be incomplete.
	 *
	 * @return void
	 */
	public function testAListAtTheLimitRefusesBecauseItMayBeIncomplete(): void {
		$this->rows['metadata_suggestion_schema'] = array_fill(0, 500, ['id' => 'sug']);

		$this->assertSame(expected: 'metadataSuggestion', actual: $this->refusal()->evidence);
	}//end testAListAtTheLimitRefusesBecauseItMayBeIncomplete()

	/**
	 * Files that cannot be listed refuse.
	 *
	 * @return void
	 */
	public function testFilesThatCannotBeListedRefuse(): void {
		$this->filesUnreadable = true;

		$this->assertSame(expected: 'files', actual: $this->refusal()->evidence);
	}//end testFilesThatCannotBeListedRefuse()

	/**
	 * Apublication without an id refuses.
	 *
	 * @return void
	 */
	public function testAPublicationWithoutAnIdRefuses(): void {
		$this->publication['id'] = '';

		$this->assertSame(expected: 'id', actual: $this->refusal()->evidence);
	}//end testAPublicationWithoutAnIdRefuses()

	/**
	 * Afailure part way keeps the publication and names what went.
	 *
	 * @return void
	 */
	public function testAFailurePartWayKeepsThePublicationAndNamesWhatWent(): void {
		$this->failingFile = 12;

		try {
			$this->service()->purge(publication: $this->publicationObject());
			$this->fail(message: 'The purge did not stop.');
		} catch (DraftPurgeFailedException $e) {
			$this->assertSame(expected: ['document_reference_schema:ref-1', 'file:11'], actual: $this->removals);
			$this->assertSame(expected: ['ref-1', '11'], actual: array_column($e->removed, 'id'), message: 'The answer names what went.');
			$this->assertSame(expected: ['kind' => 'file', 'id' => '12', 'name' => 'annex.pdf'], actual: $e->failedOn);
			$this->assertTrue(condition: $e->publicationKept);
			$this->assertNotContains(needle: 'publication_schema:pub-1', haystack: $this->removals, message: 'The publication is kept.');
			$this->assertSame(expected: [], actual: $this->audits);
		}
	}//end testAFailurePartWayKeepsThePublicationAndNamesWhatWent()

	/**
	 * An audit entry that cannot be written is reported with everything removed.
	 *
	 * @return void
	 */
	public function testAnAuditEntryThatCannotBeWrittenIsReportedWithEverythingRemoved(): void {
		$this->auditFails = true;

		try {
			$this->service()->purge(publication: $this->publicationObject());
			$this->fail(message: 'The failed audit write was not reported.');
		} catch (DraftPurgeFailedException $e) {
			$this->assertFalse(condition: $e->publicationKept);
			$this->assertSame(expected: 'audit', actual: $e->failedOn['kind']);
			$this->assertCount(expectedCount: 4, haystack: $e->removed);
		}
	}//end testAnAuditEntryThatCannotBeWrittenIsReportedWithEverythingRemoved()
}//end class
