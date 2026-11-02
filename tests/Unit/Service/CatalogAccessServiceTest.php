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
			 * @param array<int, array<string, mixed>> $rows The rows.
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
				return $this->test->schema();
			}

			/**
			 * @param object $schema The schema.
			 *
			 * @return object The schema.
			 */
			public function update(object $schema): object {
				$this->test->wrote();
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
	 * Count one write.
	 *
	 * @return void
	 */
	public function wrote(): void {
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
}//end class
