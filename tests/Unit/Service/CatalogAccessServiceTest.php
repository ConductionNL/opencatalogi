<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for CatalogAccessService: the rules a schema gets from the staff
 * access of the catalogues that cover it (design D2).
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\CatalogAccessService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for CatalogAccessService.
 */
class CatalogAccessServiceTest extends TestCase {

	private const PUBLIC_RULE = ['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now']]];

	/**
	 * In-memory app config.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * The schema as the mapper holds it.
	 *
	 * @var object
	 */
	private object $schema;

	/**
	 * How often the mapper wrote.
	 *
	 * @var int
	 */
	private int $writes = 0;

	/**
	 * Whether the mapper's update throws.
	 *
	 * @var bool
	 */
	public bool $failWrites = false;

	/**
	 * How often the catalogues were searched.
	 *
	 * @var int
	 */
	public int $searches = 0;

	/**
	 * The service over these catalogues.
	 *
	 * @param array<int, array<string, mixed>>|null $catalogs The catalogues; null makes the catalogue search throw.
	 *
	 * @return CatalogAccessService The service.
	 */
	private function service(?array $catalogs): CatalogAccessService {
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

		$objects = new class($catalogs, $this) {
			/**
			 * @param array<int, array<string, mixed>>|null $rows The rows; null throws.
			 * @param CatalogAccessServiceTest              $test Counts the searches.
			 */
			public function __construct(private ?array $rows, private CatalogAccessServiceTest $test) {
			}

			/**
			 * @param array<string, mixed> $query         The query.
			 * @param bool                 $_rbac         Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<string, mixed>
			 */
			public function searchObjectsPaginated(array $query, bool $_rbac=true, bool $_multitenancy=true): array {
				$this->test->searches++;
				if ($this->rows === null) {
					throw new \RuntimeException('search index unavailable');
				}

				return ['results' => $this->rows];
			}
		};
		$test = $this;
		$mapper = new class($test) {
			/**
			 * @param CatalogAccessServiceTest $test The test holding the schema.
			 */
			public function __construct(private CatalogAccessServiceTest $test) {
			}

			/**
			 * @return object The schema.
			 */
			public function find(int $id, array $extend=[], bool $_rbac=true, bool $_multitenancy=true): object {
				return clone $this->test->schema();
			}

			/**
			 * @param object $schema The schema.
			 *
			 * @return object The schema.
			 */
			public function update(object $schema): object {
				if ($this->test->failWrites === true) {
					throw new \RuntimeException('database gone');
				}

				$this->test->wrote(schema: $schema);
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
	 * The schema the mapper hands out.
	 *
	 * @return object The schema.
	 */
	public function schema(): object {
		return $this->schema;

	}//end schema()

	/**
	 * Store one write, as the database would: only a written schema changes.
	 *
	 * @param object $schema The written schema.
	 *
	 * @return void
	 */
	public function wrote(object $schema): void {
		$this->schema = $schema;
		$this->writes++;

	}//end wrote()

	protected function setUp(): void {
		$this->schema = new class {
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
		$this->schema->authorization = ['read' => [self::PUBLIC_RULE, 'authenticated']];

	}//end setUp()

	public function testAnUnrestrictedCatalogueKeepsTodaysRules(): void {
		$service = $this->service([['title' => 'Woo-verzoeken', 'schemas' => [173]]]);

		$this->assertFalse($service->recompute(schemaId: 173));
		$this->assertSame(['read' => [self::PUBLIC_RULE, 'authenticated']], $this->schema->authorization);
		$this->assertSame(0, $this->writes);

	}//end testAnUnrestrictedCatalogueKeepsTodaysRules()

	public function testARestrictedCatalogueWritesGroupRulesWithItsFilters(): void {
		$filters = ['wooCategory' => 'infocat014'];
		$service = $this->service(
			[
				[
					'title'       => 'Woo-verzoeken',
					'schemas'     => ['173'],
					'filters'     => $filters,
					'staffAccess' => ['restricted' => true, 'readGroups' => ['juridisch'], 'editGroups' => ['woo-team']],
				],
			]
		);

		$this->assertTrue($service->recompute(schemaId: 173));

		$rules = $this->schema->authorization;
		$this->assertSame(self::PUBLIC_RULE, $rules['read'][0]);
		$this->assertNotContains('authenticated', $rules['read']);
		$this->assertContains(['group' => 'juridisch', 'match' => $filters], $rules['read']);
		$this->assertContains(['group' => 'woo-team', 'match' => $filters], $rules['read']);
		foreach (['create', 'update', 'delete'] as $action) {
			$this->assertSame([['group' => 'woo-team', 'match' => $filters]], $rules[$action]);
		}

	}//end testARestrictedCatalogueWritesGroupRulesWithItsFilters()

	public function testTwoCataloguesOnOneSchemaAreUnited(): void {
		$service = $this->service(
			[
				['title' => 'Raadsstukken', 'schemas' => [173], 'filters' => ['kind' => 'raad'], 'staffAccess' => ['restricted' => true, 'editGroups' => ['griffie']]],
				['title' => 'Woo-verzoeken', 'schemas' => [173], 'filters' => ['kind' => 'woo'], 'staffAccess' => ['restricted' => true, 'editGroups' => ['woo-team']]],
				['title' => 'Elders', 'schemas' => [999], 'staffAccess' => ['restricted' => true, 'editGroups' => ['ander']]],
			]
		);

		$service->recompute(schemaId: 173);

		$this->assertSame(
			[['group' => 'griffie', 'match' => ['kind' => 'raad']], ['group' => 'woo-team', 'match' => ['kind' => 'woo']]],
			$this->schema->authorization['update']
		);

	}//end testTwoCataloguesOnOneSchemaAreUnited()

	public function testAnUnrestrictedCatalogueBesideARestrictedOneKeepsStaffAccessForItsOwnPublications(): void {
		$service = $this->service(
			[
				['title' => 'Raadsstukken', 'schemas' => [173], 'filters' => ['kind' => 'raad'], 'staffAccess' => ['restricted' => true, 'editGroups' => ['griffie']]],
				['title' => 'Overig', 'schemas' => [173], 'filters' => ['kind' => 'overig']],
			]
		);

		$service->recompute(schemaId: 173);

		$this->assertContains(['group' => 'authenticated', 'match' => ['kind' => 'overig']], $this->schema->authorization['update']);

	}//end testAnUnrestrictedCatalogueBesideARestrictedOneKeepsStaffAccessForItsOwnPublications()

	public function testThePublicReadRulesStayFirst(): void {
		$otherPublic = ['group' => 'public', 'match' => ['kind' => 'open']];
		$this->schema->authorization = ['read' => ['authenticated', self::PUBLIC_RULE, 'redactie', $otherPublic]];
		$service = $this->service(
			[['title' => 'Raadsstukken', 'schemas' => [173], 'staffAccess' => ['restricted' => true, 'readGroups' => ['juridisch'], 'editGroups' => ['griffie']]]]
		);

		$service->recompute(schemaId: 173);

		$this->assertSame([self::PUBLIC_RULE, $otherPublic, 'juridisch', 'griffie'], $this->schema->authorization['read']);

	}//end testThePublicReadRulesStayFirst()

	public function testNoWriteWhenNothingChanged(): void {
		$catalogs = [['title' => 'Raadsstukken', 'schemas' => [173], 'staffAccess' => ['restricted' => true, 'editGroups' => ['griffie']]]];

		$this->assertTrue($this->service($catalogs)->recompute(schemaId: 173));
		$this->assertFalse($this->service($catalogs)->recompute(schemaId: 173));
		$this->assertSame(1, $this->writes);

	}//end testNoWriteWhenNothingChanged()

	public function testLiftingTheRestrictionRestoresThePreviousRules(): void {
		$restricted = [['title' => 'Raadsstukken', 'schemas' => [173], 'staffAccess' => ['restricted' => true, 'editGroups' => ['griffie']]]];
		$this->service($restricted)->recompute(schemaId: 173);
		$this->assertSame([173], $this->service($restricted)->coveredSchemaIds());

		$lifted = [['title' => 'Raadsstukken', 'schemas' => [173], 'staffAccess' => ['restricted' => false]]];
		$this->assertTrue($this->service($lifted)->recompute(schemaId: 173));

		$this->assertSame(['read' => [self::PUBLIC_RULE, 'authenticated']], $this->schema->authorization);
		$this->assertSame([], $this->stored);

	}//end testLiftingTheRestrictionRestoresThePreviousRules()

	public function testAFailedCatalogueReadLeavesARestrictedSchemaAndItsSnapshotUnchanged(): void {
		$restricted = [['title' => 'Raadsstukken', 'schemas' => [173], 'staffAccess' => ['restricted' => true, 'editGroups' => ['griffie']]]];
		$this->service($restricted)->recompute(schemaId: 173);
		$rules = $this->schema->authorization;
		$snapshot = $this->stored;
		$writes = $this->writes;

		$this->assertFalse($this->service(null)->recompute(schemaId: 173));

		$this->assertSame($rules, $this->schema->authorization);
		$this->assertSame($snapshot, $this->stored);
		$this->assertArrayHasKey(CatalogAccessService::PREVIOUS_KEY.'173', $this->stored);
		$this->assertSame($writes, $this->writes);

	}//end testAFailedCatalogueReadLeavesARestrictedSchemaAndItsSnapshotUnchanged()

	public function testAFailedCatalogueReadStillListsTheSchemasWithASnapshot(): void {
		$this->stored[CatalogAccessService::PREVIOUS_KEY.'173'] = '{"read":[]}';

		$this->assertSame([173], $this->service(null)->coveredSchemaIds());

	}//end testAFailedCatalogueReadStillListsTheSchemasWithASnapshot()

	public function testRecomputeManyReadsTheCataloguesOnce(): void {
		$catalogs = [['title' => 'Raadsstukken', 'schemas' => [173, 184, 190], 'staffAccess' => ['restricted' => true, 'editGroups' => ['griffie']]]];

		$this->assertSame(['written' => 1, 'failed' => []], $this->service($catalogs)->recomputeMany(schemaIds: [173, 184, 190, 173]));

		$this->assertSame(1, $this->searches);

	}//end testRecomputeManyReadsTheCataloguesOnce()

	public function testAFailingSchemaWriteIsLoggedNotThrown(): void {
		$this->failWrites = true;
		$catalogs = [['title' => 'Raadsstukken', 'schemas' => [173], 'staffAccess' => ['restricted' => true, 'editGroups' => ['griffie']]]];

		$this->assertFalse($this->service($catalogs)->recompute(schemaId: 173));
		$this->assertSame(['written' => 0, 'failed' => [173]], $this->service($catalogs)->recomputeMany(schemaIds: [173]));

	}//end testAFailingSchemaWriteIsLoggedNotThrown()

	public function testAFailedWriteOnALiftKeepsTheSnapshot(): void {
		$restricted = [['title' => 'Raadsstukken', 'schemas' => [173], 'staffAccess' => ['restricted' => true, 'editGroups' => ['griffie']]]];
		$this->service($restricted)->recompute(schemaId: 173);
		$snapshot = $this->stored;

		$this->failWrites = true;
		$lifted = [['title' => 'Raadsstukken', 'schemas' => [173], 'staffAccess' => ['restricted' => false]]];
		$this->assertSame(['written' => 0, 'failed' => [173]], $this->service($lifted)->recomputeMany(schemaIds: [173]));
		$this->assertSame($snapshot, $this->stored);

		$this->failWrites = false;
		$this->assertTrue($this->service($lifted)->recompute(schemaId: 173));
		$this->assertSame(['read' => [self::PUBLIC_RULE, 'authenticated']], $this->schema->authorization);
		$this->assertSame([], $this->stored);

	}//end testAFailedWriteOnALiftKeepsTheSnapshot()

	public function testAFailedCatalogueReadReportsEverySchemaAsFailed(): void {
		$this->assertSame(['written' => 0, 'failed' => [173, 184]], $this->service(null)->recomputeMany(schemaIds: [173, 184]));

	}//end testAFailedCatalogueReadReportsEverySchemaAsFailed()

	public function testRestrictedSchemaIdsListsOnlyTheSchemasARestrictedCatalogueCovers(): void {
		$service = $this->service(
			[
				['title' => 'Raadsstukken', 'schemas' => [173, '184'], 'staffAccess' => ['restricted' => true, 'editGroups' => ['griffie']]],
				['title' => 'Overig', 'schemas' => [173, 190]],
			]
		);

		$this->assertSame([173, 184], $service->restrictedSchemaIds());
		$this->assertNull($this->service(null)->restrictedSchemaIds());

	}//end testRestrictedSchemaIdsListsOnlyTheSchemasARestrictedCatalogueCovers()
}//end class
