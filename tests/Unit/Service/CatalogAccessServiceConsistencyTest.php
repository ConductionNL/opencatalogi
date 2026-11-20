<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Consistency tests for CatalogAccessService across several schemas: one
 * schema write that fails leaves the other schemas written and every
 * schema's rules and snapshot in step, a later recompute converges, and a
 * change of groups replaces the earlier group rules. CatalogAccessServiceTest
 * holds one schema; this harness keeps one per id. Enforcement of the rules
 * is OpenRegister's and is not exercised here (catalogue-staff-access
 * tasks.md 1.4).
 *
 * @covers \OCA\OpenCatalogi\Service\CatalogAccessService
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\CatalogAccessService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for CatalogAccessService over several schemas.
 */
class CatalogAccessServiceConsistencyTest extends TestCase {

	private const PUBLIC_RULE = ['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now']]];

	/**
	 * Each schema's rules before any catalogue restricted it.
	 */
	private const ORIGINAL = [
		173 => ['read' => [self::PUBLIC_RULE, 'authenticated']],
		184 => ['read' => [self::PUBLIC_RULE, 'authenticated', 'redactie'], 'update' => ['redactie']],
		190 => ['read' => [self::PUBLIC_RULE], 'create' => ['authenticated']],
	];

	/**
	 * In-memory app config.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * The rules each schema holds in the database, by schema id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $rules = [];

	/**
	 * How often each schema was written, by schema id.
	 *
	 * @var array<int, int>
	 */
	public array $writes = [];

	/**
	 * The schema ids whose write throws.
	 *
	 * @var int[]
	 */
	public array $failWritesFor = [];

	/**
	 * The service over these catalogues.
	 *
	 * @param array<int, array<string, mixed>> $catalogs The catalogues.
	 *
	 * @return CatalogAccessService The service.
	 */
	private function service(array $catalogs): CatalogAccessService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => (['catalog_register' => '19', 'catalog_schema' => '172'][$key] ?? ($this->stored[$key] ?? $default))
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$key] = $value;
				return true;
			}
		);
		$config->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->stored[$key]);
			}
		);
		$config->method('getKeys')->willReturnCallback(fn (): array => array_keys($this->stored));

		$objects = new class($catalogs) {
			/**
			 * @param array<int, array<string, mixed>> $rows The catalogues.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * @param array<string, mixed> $query         The query.
			 * @param bool                 $_rbac         Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<string, mixed>
			 */
			public function searchObjectsPaginated(array $query, bool $_rbac=true, bool $_multitenancy=true): array {
				return ['results' => $this->rows];
			}
		};
		$mapper = new class($this) {
			/**
			 * @param CatalogAccessServiceConsistencyTest $test The test holding the schemas.
			 */
			public function __construct(private CatalogAccessServiceConsistencyTest $test) {
			}

			/**
			 * @return object A fresh copy of the stored schema.
			 */
			public function find(int $id, array $extend=[], bool $_rbac=true, bool $_multitenancy=true): object {
				return $this->test->schemaRow(id: $id);
			}

			/**
			 * @param object $schema The schema.
			 *
			 * @return object The schema.
			 */
			public function update(object $schema): object {
				if (in_array($schema->id, $this->test->failWritesFor, true) === true) {
					throw new \RuntimeException('database gone');
				}

				$this->test->rules[$schema->id] = $schema->getAuthorization();
				$this->test->writes[$schema->id] = (($this->test->writes[$schema->id] ?? 0) + 1);
				return $schema;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => str_ends_with($id, 'SchemaMapper') ? $mapper : $objects
		);

		return new CatalogAccessService($config, $container, $this->createMock(LoggerInterface::class));

	}//end service()

	/**
	 * A schema entity holding the stored rules of one schema, as the mapper reads it.
	 *
	 * @param int $id The schema id.
	 *
	 * @return object The schema.
	 */
	public function schemaRow(int $id): object {
		$schema = new class {
			/**
			 * @var int
			 */
			public int $id = 0;

			/**
			 * @var array<string, mixed>
			 */
			public array $authorization = [];

			/**
			 * @return array<string, mixed>
			 */
			public function getAuthorization(): array {
				return $this->authorization;
			}

			/**
			 * @param array<string, mixed> $authorization The rules.
			 */
			public function setAuthorization(array $authorization): void {
				$this->authorization = $authorization;
			}
		};
		$schema->id = $id;
		$schema->authorization = $this->rules[$id];
		return $schema;

	}//end schemaRow()

	protected function setUp(): void {
		$this->rules = self::ORIGINAL;

	}//end setUp()

	/**
	 * A restricted catalogue over the given schemas.
	 *
	 * @param int[]    $schemas    The schema ids.
	 * @param string[] $editGroups The edit groups.
	 * @param string[] $readGroups The read groups.
	 *
	 * @return array<int, array<string, mixed>> The catalogues.
	 */
	private function restricted(array $schemas, array $editGroups=['griffie'], array $readGroups=[]): array {
		return [['title' => 'Raadsstukken', 'schemas' => $schemas, 'staffAccess' => ['restricted' => true, 'readGroups' => $readGroups, 'editGroups' => $editGroups]]];

	}//end restricted()

	/**
	 * The rules a schema gets from a catalogue restricted to edit group griffie.
	 *
	 * @param int $id The schema id.
	 *
	 * @return array<string, mixed> The rules.
	 */
	private function griffieRules(int $id): array {
		return array_merge(self::ORIGINAL[$id], ['read' => [self::PUBLIC_RULE, 'griffie'], 'create' => ['griffie'], 'update' => ['griffie'], 'delete' => ['griffie']]);

	}//end griffieRules()

	/**
	 * The snapshot key of a schema.
	 *
	 * @param int $id The schema id.
	 *
	 * @return string The key.
	 */
	private function snapshotKey(int $id): string {
		return CatalogAccessService::PREVIOUS_KEY.$id;

	}//end snapshotKey()

	public function testOneFailingSchemaWriteLeavesTheOthersWrittenAndEverySnapshotTrue(): void {
		$this->failWritesFor = [184];

		$this->assertSame(['written' => 2, 'failed' => [184]], $this->service($this->restricted([173, 184, 190]))->recomputeMany(schemaIds: [173, 184, 190]));

		$this->assertSame([173 => $this->griffieRules(173), 184 => self::ORIGINAL[184], 190 => $this->griffieRules(190)], $this->rules);
		foreach (self::ORIGINAL as $id => $original) {
			$this->assertSame(json_encode($original), $this->stored[$this->snapshotKey($id)], 'snapshot of '.$id);
		}

	}//end testOneFailingSchemaWriteLeavesTheOthersWrittenAndEverySnapshotTrue()

	public function testALaterRecomputeConvergesAfterAPartialFailure(): void {
		$catalogs = $this->restricted([173, 184, 190]);
		$this->failWritesFor = [184];
		$this->service($catalogs)->recomputeMany(schemaIds: [173, 184, 190]);

		$this->failWritesFor = [];
		$this->assertSame(['written' => 1, 'failed' => []], $this->service($catalogs)->recomputeMany(schemaIds: [173, 184, 190]));
		$this->assertSame([173 => $this->griffieRules(173), 184 => $this->griffieRules(184), 190 => $this->griffieRules(190)], $this->rules);
		$this->assertSame([173 => 1, 190 => 1, 184 => 1], $this->writes);
		$this->assertSame(json_encode(self::ORIGINAL[184]), $this->stored[$this->snapshotKey(184)]);

		$this->assertSame(['written' => 0, 'failed' => []], $this->service($catalogs)->recomputeMany(schemaIds: [173, 184, 190]));

		$lifted = [['title' => 'Raadsstukken', 'schemas' => [173, 184, 190], 'staffAccess' => ['restricted' => false]]];
		$this->assertSame(['written' => 3, 'failed' => []], $this->service($lifted)->recomputeMany(schemaIds: [173, 184, 190]));
		$this->assertSame(self::ORIGINAL, $this->rules);
		$this->assertSame([], $this->stored);

	}//end testALaterRecomputeConvergesAfterAPartialFailure()

	public function testAPartialFailureOnALiftRestoresTheOthersAndKeepsTheFailedSchemasSnapshot(): void {
		$this->service($this->restricted([173, 184, 190]))->recomputeMany(schemaIds: [173, 184, 190]);

		// The schema leaves the catalogue; its write fails.
		$this->failWritesFor = [190];
		$narrowed = $this->restricted([173, 184]);
		$this->assertSame(['written' => 0, 'failed' => [190]], $this->service($narrowed)->recomputeMany(schemaIds: [190]));
		$this->assertSame($this->griffieRules(190), $this->rules[190]);
		$this->assertSame(json_encode(self::ORIGINAL[190]), $this->stored[$this->snapshotKey(190)]);

		// The snapshot keeps the schema in the repair step's list, so it converges.
		$this->failWritesFor = [];
		$covered = $this->service($narrowed)->coveredSchemaIds();
		$this->assertContains(190, $covered);
		$this->assertSame(['written' => 1, 'failed' => []], $this->service($narrowed)->recomputeMany(schemaIds: $covered));
		$this->assertSame([173 => $this->griffieRules(173), 184 => $this->griffieRules(184), 190 => self::ORIGINAL[190]], $this->rules);
		$this->assertArrayNotHasKey($this->snapshotKey(190), $this->stored);
		$this->assertArrayHasKey($this->snapshotKey(173), $this->stored);

	}//end testAPartialFailureOnALiftRestoresTheOthersAndKeepsTheFailedSchemasSnapshot()

	public function testChangingTheGroupsReplacesTheEarlierGroupRules(): void {
		$this->service($this->restricted([173]))->recompute(schemaId: 173);

		$this->assertTrue($this->service($this->restricted([173], ['woo-team'], ['juridisch']))->recompute(schemaId: 173));

		$this->assertSame(
			['read' => [self::PUBLIC_RULE, 'juridisch', 'woo-team'], 'create' => ['woo-team'], 'update' => ['woo-team'], 'delete' => ['woo-team']],
			$this->rules[173]
		);
		$this->assertSame(json_encode(self::ORIGINAL[173]), $this->stored[$this->snapshotKey(173)]);

	}//end testChangingTheGroupsReplacesTheEarlierGroupRules()

	public function testARestrictedCatalogueWithoutGroupsGrantsStaffNothingButThePublicRead(): void {
		$this->assertTrue($this->service($this->restricted([184], []))->recompute(schemaId: 184));

		$this->assertSame(['read' => [self::PUBLIC_RULE], 'update' => [], 'create' => [], 'delete' => []], $this->rules[184]);

	}//end testARestrictedCatalogueWithoutGroupsGrantsStaffNothingButThePublicRead()
}//end class
