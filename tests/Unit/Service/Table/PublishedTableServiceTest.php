<?php

/**
 * Unit tests for PublishedTableService.
 *
 * OpenRegister's reader, mappers, import preview and ObjectService are fakes
 * that record what they are asked, so each test asserts the exact calls and
 * the table record that comes out.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service\Table;

use InvalidArgumentException;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Service\Table\PublishedTableService;
use OCA\OpenCatalogi\Contract\TableFile;
use OCA\OpenRegister\Service\Import\ImportCommit;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * A fake OpenRegister entity: getters and setters over an array.
 */
class TableFakeEntity {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $fields The fields.
	 */
	public function __construct(public array $fields) {
	}

	/**
	 * Read or write a field by its getter or setter name.
	 *
	 * @param string $name The method.
	 * @param array<int, mixed> $args The arguments.
	 *
	 * @return mixed The field, or null for a setter.
	 */
	public function __call(string $name, array $args): mixed {
		$field = lcfirst(substr($name, 3));
		if (str_starts_with($name, 'set') === true) {
			$this->fields[$field] = $args[0];
			return null;
		}

		return ($this->fields[$field] ?? null);
	}
}

/**
 * A fake of every OpenRegister service the table service uses.
 */
class TableFakeOpenRegister {

	/** @var array<int, array<string, mixed>> */
	public array $rows = [];

	/** @var array<int, array<string, mixed>> */
	public array $createdSchemas = [];

	/** @var array<int, mixed> */
	public array $updatedRegisters = [];

	/** @var array<int, array<int, mixed>> */
	public array $previews = [];

	/** @var array<int, array<int, mixed>> */
	public array $commits = [];

	/** @var array<int, array<string, mixed>> */
	public array $saved = [];

	/** @var array<int, array<string, mixed>> */
	public array $searches = [];

	/** @var array<int, array<string, mixed>> */
	public array $finds = [];

	public TableFakeEntity $register;

	public TableFakeEntity $preview;

	public TableFakeEntity $committed;

	public ?TableFakeEntity $schema = null;

	public mixed $found = null;

	public array $searchAnswer = ['results' => [], 'total' => 0];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->register = new TableFakeEntity(['id' => 5, 'schemas' => [3]]);
		$this->preview = new TableFakeEntity(['toRefuse' => 0, 'state' => 'previewed', 'failed' => 0, 'sourceHash' => 'abc', 'report' => ['refusals' => []]]);
		$this->committed = new TableFakeEntity(['toRefuse' => 0, 'state' => 'committed', 'failed' => 0, 'applied' => 2, 'report' => ['refusals' => []]]);
	}

	/** Reader. */
	public function read(string $path, string $format): array {
		$this->finds[] = ['read' => [$path, $format]];
		return $this->rows;
	}

	/** Register and schema mappers. */
	public function find(mixed $id, mixed ...$rest): mixed {
		if ($id === 'open-data-tables') {
			return $this->register;
		}

		if ($rest === []) {
			return $this->schema;
		}

		$this->finds[] = ['id' => $id] + $rest;
		if ($this->found instanceof \Throwable) {
			throw $this->found;
		}

		return $this->found;
	}

	/** Schema mapper. */
	public function createFromArray(array $object): TableFakeEntity {
		$this->createdSchemas[] = $object;
		return new TableFakeEntity(['id' => 42] + $object);
	}

	/** Register mapper. */
	public function update(mixed $entity): mixed {
		$this->updatedRegisters[] = $entity;
		return $entity;
	}

	/** Import preview. */
	public function preview(array $params, mixed $user): TableFakeEntity {
		$this->previews[] = [$params, $user];
		return $this->preview;
	}

	/** Import commit. */
	public function commit(mixed $preview, ImportCommit $commit): TableFakeEntity {
		$this->commits[] = [$preview, $commit];
		return $this->committed;
	}

	/** ObjectService save. */
	public function saveObject(array $object, mixed ...$options): array {
		$this->saved[] = ['object' => $object] + $options;
		return ['id' => 't1'] + $object;
	}

	/** ObjectService search. */
	public function searchObjectsPaginated(array $query, mixed ...$options): array {
		$this->searches[] = ['query' => $query] + $options;
		return $this->searchAnswer;
	}
}

/**
 * Publishing a CSV as a table, and reading its rows and dictionary.
 *
 * @covers \OCA\OpenCatalogi\Service\Table\PublishedTableService
 * @uses   \OCA\OpenCatalogi\Service\Table\ColumnTypes
 * @uses   \OCA\OpenCatalogi\Contract\TableFile
 * @uses   \OCA\OpenCatalogi\Service\SchemaObjects
 */
class PublishedTableServiceTest extends TestCase {

	private TableFakeOpenRegister $openRegister;

	private PublishedTableService $service;

	/**
	 * Wire the service to the fakes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->openRegister = new TableFakeOpenRegister();
		$this->openRegister->rows = [
			['row' => 2, 'data' => ['naam' => 'Park', 'wijk' => 'Centrum', 'toestellen' => '4']],
			['row' => 3, 'data' => ['naam' => 'Plein', 'wijk' => 'Noord', 'toestellen' => '2']],
		];

		$registers = $this->createMock(OpenRegisterLookupInterface::class);
		$registers->method('objectService')->willReturn($this->openRegister);
		$registers->method('registerConfiguration')
			->with('publication_register', 'published_table_schema')
			->willReturn(['register' => 'pub', 'schema' => 'tbl']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $class): TableFakeOpenRegister {
				$this->assertContains(
					$class,
					[
						'OCA\OpenRegister\Service\Import\SourceRowReader',
						'OCA\OpenRegister\Db\RegisterMapper',
						'OCA\OpenRegister\Db\SchemaMapper',
						'OCA\OpenRegister\Service\Import\ImportPreviewServiceInterface',
					]
				);
				return $this->openRegister;
			}
		);

		$this->service = new PublishedTableService(registers: $registers, container: $container);
	}//end setUp()

	/**
	 * Publish the playground list.
	 *
	 * @return array<string, mixed> The stored record.
	 */
	private function publish(): array {
		return $this->service->createFromFile(
			publication: ['id' => 'p1', 'slug' => 'speeltuinen'],
			file: new TableFile(path: '/tmp/speeltuinen.csv', id: '77', name: 'speeltuinen.csv')
		);
	}//end publish()

	/**
	 * A clean file: schema from the header, preview, commit, ready with the row count.
	 *
	 * @return void
	 */
	public function testACleanFileIsImportedAndTheTableIsReady(): void {
		$table = $this->publish();

		$this->assertSame([['read' => ['/tmp/speeltuinen.csv', 'csv']]], $this->openRegister->finds);
		$schema = $this->openRegister->createdSchemas[0];
		$this->assertSame('table-speeltuinen-77', $schema['slug']);
		$this->assertSame(['naam', 'wijk', 'toestellen'], array_keys($schema['properties']));
		$this->assertSame('integer', $schema['properties']['toestellen']['type']);
		$this->assertSame(['authenticated'], $schema['authorization']['read']);
		$this->assertSame([3, 42], $this->openRegister->register->fields['schemas']);
		$this->assertSame([$this->openRegister->register], $this->openRegister->updatedRegisters);

		$this->assertSame(
			['register' => 5, 'schema' => 42, 'filePath' => '/tmp/speeltuinen.csv', 'sourceName' => 'speeltuinen.csv', 'format' => 'csv'],
			$this->openRegister->previews[0][0]
		);
		$this->assertCount(1, $this->openRegister->commits);
		$this->assertSame('abc', $this->openRegister->commits[0][1]->sourceHash);

		$saved = $this->openRegister->saved[0];
		$this->assertSame(['register' => 'pub', 'schema' => 'tbl', '_rbac' => false, '_multitenancy' => false], array_diff_key($saved, ['object' => 1]));
		$this->assertSame('ready', $table['status']);
		$this->assertSame(2, $table['rowCount']);
		$this->assertSame('p1', $table['publication']);
		$this->assertSame('77', $table['file']);
		$this->assertSame('42', $table['schema']);
		$this->assertArrayHasKey('importedAt', $table);
		$this->assertArrayNotHasKey('lastError', $table);
	}//end testACleanFileIsImportedAndTheTableIsReady()

	/**
	 * A refused row: nothing is committed and the table is failed with that row named.
	 *
	 * @return void
	 */
	public function testARefusedRowFailsTheTableWithoutCommitting(): void {
		$this->openRegister->preview->fields['toRefuse'] = 1;
		$this->openRegister->preview->fields['report'] = ['refusals' => [['row' => 350, 'reason' => 'toestellen is not an integer']]];

		$table = $this->publish();

		$this->assertSame([], $this->openRegister->commits);
		$this->assertSame('failed', $table['status']);
		$this->assertSame('Row 350: toestellen is not an integer', $table['lastError']);
		$this->assertSame(0, $table['rowCount']);
	}//end testARefusedRowFailsTheTableWithoutCommitting()

	/**
	 * A refusal count without a report still fails; a commit that fails is failed with its error.
	 *
	 * @return void
	 */
	public function testRefusalsWithoutAReportAndAFailedCommitFail(): void {
		$this->openRegister->preview->fields['toRefuse'] = 3;
		$this->openRegister->preview->fields['report'] = null;
		$this->assertSame('The import refused 3 rows.', $this->publish()['lastError']);

		$this->openRegister->preview->fields['toRefuse'] = 0;
		$this->openRegister->committed->fields['state'] = 'failed';
		$this->openRegister->committed->fields['report'] = ['error' => 'disk full'];
		$table = $this->publish();
		$this->assertSame('failed', $table['status']);
		$this->assertSame('disk full', $table['lastError']);
	}//end testRefusalsWithoutAReportAndAFailedCommitFail()

	/**
	 * An empty file is refused before any schema is made.
	 *
	 * @return void
	 */
	public function testAnEmptyFileIsRefused(): void {
		$this->openRegister->rows = [];

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('speeltuinen.csv');
		try {
			$this->publish();
		} finally {
			$this->assertSame([], $this->openRegister->createdSchemas);
		}
	}//end testAnEmptyFileIsRefused()

	/**
	 * The dictionary lists each column from the schema, dates by their format.
	 *
	 * @return void
	 */
	public function testTheDictionaryListsEachColumn(): void {
		$this->openRegister->schema = new TableFakeEntity(
			[
				'properties' => [
					'toestellen' => ['title' => 'Toestellen', 'description' => 'Number of play devices on the site', 'type' => 'integer'],
					'sinds' => ['type' => 'string', 'format' => 'date'],
					'broken' => 'not a property',
				],
			]
		);

		$this->assertSame(
			[
				['name' => 'toestellen', 'title' => 'Toestellen', 'description' => 'Number of play devices on the site', 'type' => 'integer'],
				['name' => 'sinds', 'title' => 'sinds', 'description' => '', 'type' => 'date'],
			],
			$this->service->dictionary(table: ['schema' => '42'])
		);
	}//end testTheDictionaryListsEachColumn()

	/**
	 * A filter on an undeclared column is reported; control parameters are not filters.
	 *
	 * @return void
	 */
	public function testUndeclaredFiltersAreReported(): void {
		$this->openRegister->schema = new TableFakeEntity(['properties' => ['wijk' => ['type' => 'string']]]);

		$query = ['wijk' => 'Centrum', 'kleur' => 'rood', '_order' => ['wijk' => 'asc'], '_limit' => '5', '_page' => '2', '_search' => 'x', '_route' => 'r'];

		$this->assertSame(['kleur'], $this->service->undeclaredFilters(query: $query, table: ['schema' => '42']));
	}//end testUndeclaredFiltersAreReported()

	/**
	 * Rows: declared filters pass through, the limit is capped, the table's schema is scoped.
	 *
	 * @return void
	 */
	public function testRowsPassTheFiltersAndCapTheLimit(): void {
		$this->openRegister->schema = new TableFakeEntity(['properties' => ['wijk' => ['type' => 'string'], 'naam' => ['type' => 'string']]]);
		$this->openRegister->searchAnswer = ['results' => [['naam' => 'Park']], 'total' => 31, 'page' => 2, 'pages' => 4];

		$answer = $this->service->rows(
			table: ['schema' => '42'],
			query: ['wijk' => 'Centrum', '_order' => ['naam' => 'asc'], '_limit' => '5000', '_page' => '2', 'catalogSlug' => 'c']
		);

		$this->assertSame(['results' => [['naam' => 'Park']], 'total' => 31, 'page' => 2, 'pages' => 4], $answer);
		$search = $this->openRegister->searches[0];
		$this->assertSame(
			['wijk' => 'Centrum', '_order' => ['naam' => 'asc'], '_page' => '2', '_limit' => 1000, '@self' => ['register' => '5', 'schema' => '42']],
			$search['query']
		);
		$this->assertFalse($search['_rbac']);
		$this->assertFalse($search['_multitenancy']);
	}//end testRowsPassTheFiltersAndCapTheLimit()

	/**
	 * Rows default to twenty and never ask for less than one.
	 *
	 * @return void
	 */
	public function testRowsDefaultToTwentyAndAtLeastOne(): void {
		$this->openRegister->schema = new TableFakeEntity(['properties' => []]);

		$answer = $this->service->rows(table: ['schema' => '42'], query: []);
		$this->service->rows(table: ['schema' => '42'], query: ['_limit' => '-3']);

		$this->assertSame(20, $this->openRegister->searches[0]['query']['_limit']);
		$this->assertSame(1, $this->openRegister->searches[1]['query']['_limit']);
		$this->assertSame(['results' => [], 'total' => 0, 'page' => 1, 'pages' => 1], $answer);
	}//end testRowsDefaultToTwentyAndAtLeastOne()

	/**
	 * A table is found as the system in the configured schema; a miss is null.
	 *
	 * @return void
	 */
	public function testFindTableReadsAsTheSystem(): void {
		$this->openRegister->found = ['id' => 't1', 'status' => 'ready'];
		$this->assertSame(['id' => 't1', 'status' => 'ready'], $this->service->findTable(tableId: 't1'));
		$this->assertSame(
			['id' => 't1', '_extend' => [], 'register' => 'pub', 'schema' => 'tbl', '_rbac' => false, '_multitenancy' => false],
			$this->openRegister->finds[0]
		);

		$this->openRegister->found = new \RuntimeException('gone');
		$this->assertNull($this->service->findTable(tableId: 't2'));
	}//end testFindTableReadsAsTheSystem()

	/**
	 * A publication is readable only when OpenRegister finds it with RBAC on.
	 *
	 * @return void
	 */
	public function testAPublicationIsReadableOnlyWhenFoundWithRbac(): void {
		$this->openRegister->found = ['id' => 'p1'];
		$this->assertTrue($this->service->canReadPublication(publicationId: 'p1'));
		$this->assertSame(['id' => 'p1', '_extend' => [], '_rbac' => true, '_multitenancy' => false], $this->openRegister->finds[0]);

		$this->openRegister->found = null;
		$this->assertFalse($this->service->canReadPublication(publicationId: 'p1'));

		$this->openRegister->found = new \RuntimeException('forbidden');
		$this->assertFalse($this->service->canReadPublication(publicationId: 'p1'));
	}//end testAPublicationIsReadableOnlyWhenFoundWithRbac()
}//end class
