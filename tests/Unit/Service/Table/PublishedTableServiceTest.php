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
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use OCA\OpenCatalogi\Tests\Support\LegacyObjectArgs;

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

	public ?\Throwable $previewThrows = null;

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
		$rest = LegacyObjectArgs::flatten(args: $rest);
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
		if ($this->previewThrows !== null) {
			throw $this->previewThrows;
		}

		return $this->preview;
	}

	/** Import commit. */
	public function commit(mixed $preview, ImportCommit $commit): TableFakeEntity {
		$this->commits[] = [$preview, $commit];
		return $this->committed;
	}

	/** ObjectService save. */
	public function saveObject(array $object, mixed ...$options): array {
		$options = \OCA\OpenCatalogi\Tests\Support\LegacyObjectArgs::flatten(args: $options);
		$options = \OCA\OpenCatalogi\Tests\Support\LegacyObjectArgs::flatten(args: $options);
		$options = \OCA\OpenCatalogi\Tests\Support\LegacyObjectArgs::flatten(args: $options);
		$this->saved[] = ['object' => $object] + $options;
		return ['id' => 't1'] + $object;
	}

	public mixed $file = null;

	public string $tempPath = '';

	/** FileService. */
	public function getFile(mixed $object = null, string|int $file = ''): mixed {
		$this->finds[] = ['getFile' => [$object, $file]];
		return $this->file;
	}

	/** Temp manager. */
	public function getTemporaryFile(string $postFix = ''): string {
		return $this->tempPath;
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
 * @uses   \OCA\OpenCatalogi\Service\Table\TableImportRun
 * @uses   \OCA\OpenCatalogi\Service\SchemaObjects
 */
class PublishedTableServiceTest extends TestCase {

	private TableFakeOpenRegister $openRegister;

	private PublishedTableService $service;

	private ContainerInterface $container;

	private OpenRegisterLookupInterface $registers;

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
						'OCA\OpenRegister\Service\FileService',
						'OCP\ITempManager',
					]
				);
				return $this->openRegister;
			}
		);

		$this->container = $container;
		$this->registers = $registers;
		$this->service = new PublishedTableService(registers: $registers, container: $container);
	}//end setUp()

	/**
	 * The service with a URL generator that spells out each route and its arguments.
	 *
	 * @return PublishedTableService
	 */
	private function serviceWithUrls(): PublishedTableService {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $args): string => 'https://example.org/'.$route.'/'.$args['catalogSlug'].'/'.$args['publicationId'].'/'.$args['tableId']
		);

		return new PublishedTableService(registers: $this->registers, container: $this->container, urls: $urls);
	}//end serviceWithUrls()

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
			['id' => 't1', 'register' => 'pub', 'schema' => 'tbl', '_rbac' => false, '_multitenancy' => false],
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
		$this->assertSame(['id' => 'p1', '_rbac' => true, '_multitenancy' => false], $this->openRegister->finds[0]);

		$this->openRegister->found = null;
		$this->assertFalse($this->service->canReadPublication(publicationId: 'p1'));

		$this->openRegister->found = new \RuntimeException('forbidden');
		$this->assertFalse($this->service->canReadPublication(publicationId: 'p1'));
	}//end testAPublicationIsReadableOnlyWhenFoundWithRbac()

	/**
	 * A publication's ready tables are listed with their rows and dictionary URLs.
	 *
	 * @return void
	 */
	public function testTablesOfListsReadyTablesWithTheirUrls(): void {
		$this->openRegister->searchAnswer = [
			'results' => [
				['id' => 't1', 'title' => 'speeltuinen.csv', 'file' => '77', 'rowCount' => 2, 'status' => 'ready'],
				['id' => 't2', 'title' => 'oud.csv', 'file' => '78', 'status' => 'failed'],
			],
		];

		$tables = $this->serviceWithUrls()->tablesOf(publicationId: 'p1', catalogSlug: 'speeltuinen');

		$this->assertSame(
			[
				[
					'id' => 't1',
					'title' => 'speeltuinen.csv',
					'file' => '77',
					'rowCount' => 2,
					'rowsUrl' => 'https://example.org/opencatalogi.publishedTable.rows/speeltuinen/p1/t1',
					'dictionaryUrl' => 'https://example.org/opencatalogi.publishedTable.dictionary/speeltuinen/p1/t1',
				],
			],
			$tables
		);
		$this->assertSame(
			[
				'query' => ['@self' => ['register' => 'pub', 'schema' => 'tbl'], 'publication' => 'p1', 'status' => 'ready', '_limit' => 100],
				'_rbac' => false,
				'_multitenancy' => false,
			],
			$this->openRegister->searches[0]
		);
	}//end testTablesOfListsReadyTablesWithTheirUrls()

	/**
	 * Without a publication nothing is searched; without a URL generator the URLs are empty.
	 *
	 * @return void
	 */
	public function testTablesOfWithoutAPublicationOrUrlGenerator(): void {
		$this->assertSame([], $this->service->tablesOf(publicationId: '', catalogSlug: 'c'));
		$this->assertSame([], $this->openRegister->searches);

		$this->openRegister->searchAnswer = ['results' => [['id' => 't1', 'file' => '77', 'status' => 'ready']]];
		$tables = $this->service->tablesOf(publicationId: 'p1', catalogSlug: 'c');
		$this->assertSame('', $tables[0]['rowsUrl']);
		$this->assertSame('', $tables[0]['dictionaryUrl']);
		$this->assertSame(0, $tables[0]['rowCount']);
	}//end testTablesOfWithoutAPublicationOrUrlGenerator()

	/**
	 * Only the file published as a table gets its dictionary URL.
	 *
	 * @return void
	 */
	public function testWithDictionaryUrlsMarksTheTablesFile(): void {
		$this->openRegister->searchAnswer = ['results' => [['id' => 't1', 'file' => '77', 'status' => 'ready']]];
		$files = [['id' => 77, 'title' => 'speeltuinen.csv'], ['id' => 80, 'title' => 'toelichting.pdf']];

		$this->assertSame(
			[
				['id' => 77, 'title' => 'speeltuinen.csv', 'dataDictionaryUrl' => 'https://example.org/opencatalogi.publishedTable.dictionary/c/p1/t1'],
				['id' => 80, 'title' => 'toelichting.pdf'],
			],
			$this->serviceWithUrls()->withDictionaryUrls(files: $files, publicationId: 'p1', catalogSlug: 'c')
		);
	}//end testWithDictionaryUrlsMarksTheTablesFile()

	/**
	 * Without tables, or without URLs, the files come back unchanged.
	 *
	 * @return void
	 */
	public function testWithDictionaryUrlsLeavesFilesWithoutTablesAlone(): void {
		$files = [['id' => 77]];
		$this->assertSame($files, $this->serviceWithUrls()->withDictionaryUrls(files: $files, publicationId: 'p1', catalogSlug: 'c'));

		$this->openRegister->searchAnswer = ['results' => [['id' => 't1', 'file' => '77', 'status' => 'ready']]];
		$this->assertSame($files, $this->service->withDictionaryUrls(files: $files, publicationId: 'p1', catalogSlug: 'c'));
	}//end testWithDictionaryUrlsLeavesFilesWithoutTablesAlone()

	/**
	 * A CSV attachment is copied to a temporary file and imported as the publication's table.
	 *
	 * @return void
	 */
	public function testPublishAttachmentImportsTheCsv(): void {
		$this->openRegister->tempPath = (string)tempnam(sys_get_temp_dir(), 'odt');
		$this->openRegister->file = new TableFakeEntity(['name' => 'speeltuinen.CSV', 'id' => 77, 'content' => "naam,wijk\nPark,Centrum\n"]);
		$publication = new TableFakeEntity(['uuid' => 'p1']);

		$table = $this->service->publishAttachment(publication: $publication, fileId: '77');

		$this->assertSame(['getFile' => [$publication, 77]], $this->openRegister->finds[0]);
		$this->assertSame("naam,wijk\nPark,Centrum\n", file_get_contents($this->openRegister->tempPath));
		$this->assertSame(['read' => [$this->openRegister->tempPath, 'csv']], $this->openRegister->finds[1]);
		$this->assertSame('p1', $table['publication']);
		$this->assertSame('77', $table['file']);
		$this->assertSame('speeltuinen.CSV', $table['title']);
		$this->assertSame('table-p1-77', $this->openRegister->createdSchemas[0]['slug']);
		unlink($this->openRegister->tempPath);
	}//end testPublishAttachmentImportsTheCsv()

	/**
	 * A missing attachment or one that is not a CSV is refused before anything is imported.
	 *
	 * @return void
	 */
	public function testPublishAttachmentRefusesMissingAndNonCsvFiles(): void {
		$publication = new TableFakeEntity(['uuid' => 'p1']);
		try {
			$this->service->publishAttachment(publication: $publication, fileId: '9');
			$this->fail('A missing attachment was published.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('The publication has no attachment 9.', $e->getMessage());
		}

		$this->openRegister->file = new TableFakeEntity(['name' => 'besluit.pdf', 'id' => 9]);
		try {
			$this->service->publishAttachment(publication: $publication, fileId: '9');
			$this->fail('A PDF was published as a table.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('Only a CSV attachment can be published as a table; "besluit.pdf" is not one.', $e->getMessage());
		}

		$this->assertSame([], $this->openRegister->createdSchemas);
	}//end testPublishAttachmentRefusesMissingAndNonCsvFiles()

	/**
	 * Editors see every table of the publication; a ready one carries its dictionary.
	 *
	 * @return void
	 */
	public function testTablesForListsEveryStatus(): void {
		$this->openRegister->schema = new TableFakeEntity(['properties' => ['naam' => ['type' => 'string', 'title' => 'Naam']]]);
		$this->openRegister->searchAnswer = [
			'results' => [
				['id' => 't1', 'status' => 'ready', 'schema' => '42'],
				['id' => 't2', 'status' => 'failed', 'lastError' => 'Row 350: not a number'],
			],
		];

		$tables = $this->service->tablesFor(publicationId: 'p1');

		$this->assertSame([['name' => 'naam', 'title' => 'Naam', 'description' => '', 'type' => 'string']], $tables[0]['dictionary']);
		$this->assertSame(['id' => 't2', 'status' => 'failed', 'lastError' => 'Row 350: not a number'], $tables[1]);
		$this->assertSame(['@self' => ['register' => 'pub', 'schema' => 'tbl'], 'publication' => 'p1', '_limit' => 100], $this->openRegister->searches[0]['query']);
		$this->assertSame([], $this->service->tablesFor(publicationId: ''));
	}//end testTablesForListsEveryStatus()

	/**
	 * An editor's titles and descriptions are written to the schema; names and types stay.
	 *
	 * @return void
	 */
	public function testUpdateDictionaryWritesTitlesAndDescriptions(): void {
		$this->openRegister->schema = new TableFakeEntity(
			['properties' => ['naam' => ['type' => 'string', 'title' => 'naam'], 'toestellen' => ['type' => 'integer', 'title' => 'toestellen']]]
		);

		$dictionary = $this->service->updateDictionary(
			table: ['schema' => '42'],
			columns: [
				['name' => 'toestellen', 'title' => '  Toestellen ', 'description' => 'Number of play devices on the site', 'type' => 'string'],
				['name' => 'naam', 'title' => '', 'description' => str_repeat('x', 2100)],
			]
		);

		$this->assertSame(['type' => 'integer', 'title' => 'Toestellen', 'description' => 'Number of play devices on the site'], $this->openRegister->schema->fields['properties']['toestellen']);
		$this->assertSame('naam', $this->openRegister->schema->fields['properties']['naam']['title']);
		$this->assertSame(2000, mb_strlen($this->openRegister->schema->fields['properties']['naam']['description']));
		$this->assertSame([$this->openRegister->schema], $this->openRegister->updatedRegisters);
		$this->assertSame('Number of play devices on the site', $dictionary[1]['description']);
	}//end testUpdateDictionaryWritesTitlesAndDescriptions()

	/**
	 * A column the table does not declare is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testUpdateDictionaryRefusesAnUnknownColumn(): void {
		$this->openRegister->schema = new TableFakeEntity(['properties' => ['naam' => ['type' => 'string']]]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('This table has no column named kleur.');
		try {
			$this->service->updateDictionary(table: ['schema' => '42'], columns: [['name' => 'kleur', 'title' => 'Kleur']]);
		} finally {
			$this->assertSame([], $this->openRegister->updatedRegisters);
		}
	}//end testUpdateDictionaryRefusesAnUnknownColumn()

	/**
	 * A failed preview is stored importing first, with who started it, and
	 * then updated to failed: the notification fires on that update.
	 *
	 * @return void
	 */
	public function testAFailedPreviewGoesFromImportingToFailed(): void {
		$this->openRegister->preview->fields['toRefuse'] = 1;
		$this->openRegister->preview->fields['report'] = ['refusals' => [['row' => 12408, 'reason' => 'bedrag is not a number']]];
		$editor = $this->createMock(\OCP\IUser::class);
		$editor->method('getUID')->willReturn('anna');

		$table = $this->service->createFromFile(
			publication: ['id' => 'p1', 'slug' => 'speeltuinen'],
			file: new TableFile(path: '/tmp/speeltuinen.csv', id: '77', name: 'speeltuinen.csv'),
			user: $editor
		);

		$this->assertCount(2, $this->openRegister->saved);
		$first = $this->openRegister->saved[0]['object'];
		$this->assertSame('importing', $first['status']);
		$this->assertSame('anna', $first['importedBy']);
		$this->assertArrayNotHasKey('lastError', $first);
		$second = $this->openRegister->saved[1]['object'];
		$this->assertSame('t1', $second['id'], 'the second save updates the stored table');
		$this->assertSame('failed', $second['status']);
		$this->assertSame('Row 12408: bedrag is not a number', $second['lastError']);
		$this->assertSame('anna', $second['importedBy']);
		$this->assertSame($editor, $this->openRegister->previews[0][1]);
		$this->assertSame('failed', $table['status']);
	}//end testAFailedPreviewGoesFromImportingToFailed()

	/**
	 * An import that throws ends the table failed with the message, never importing.
	 *
	 * @return void
	 */
	public function testAThrowingImportEndsFailed(): void {
		$this->openRegister->previewThrows = new \RuntimeException('  the reader gave up  ');

		$table = $this->publish();

		$this->assertSame(['importing', 'failed'], array_map(static fn (array $s): string => $s['object']['status'], $this->openRegister->saved));
		$this->assertSame('the reader gave up', $table['lastError']);

		$this->openRegister->saved = [];
		$this->openRegister->previewThrows = new \RuntimeException(str_repeat('x', 2500));
		$this->assertSame(2000, mb_strlen($this->publish()['lastError']));

		$this->openRegister->previewThrows = new \RuntimeException('');
		$this->assertSame('The import failed.', $this->publish()['lastError']);
	}//end testAThrowingImportEndsFailed()

	/**
	 * A re-import of a stored table looks its schema up, keeps its id, clears
	 * the old error and records who started it.
	 *
	 * @return void
	 */
	public function testAReimportOfAStoredTable(): void {
		$this->openRegister->schema = new TableFakeEntity(['id' => 42]);

		$table = $this->service->importTable(
			table: ['id' => 't9', 'publication' => 'p1', 'file' => '77', 'schema' => '42', 'status' => 'failed', 'lastError' => 'Row 3: old', 'importedBy' => 'anna'],
			file: new TableFile(path: '/tmp/speeltuinen.csv', id: '77', name: 'speeltuinen.csv'),
			importedBy: 'bram'
		);

		$first = $this->openRegister->saved[0]['object'];
		$this->assertSame(['t9', 'importing', 'bram'], [$first['id'], $first['status'], $first['importedBy']]);
		$this->assertArrayNotHasKey('lastError', $first);
		$this->assertSame(42, $this->openRegister->previews[0][0]['schema']);
		$this->assertSame('ready', $table['status']);
		$this->assertSame('bram', $table['importedBy']);
		$this->assertArrayNotHasKey('lastError', $table);
	}//end testAReimportOfAStoredTable()

	/**
	 * A service whose OpenRegister lookup answers the publication pair as well as the table pair.
	 *
	 * @param \OCA\OpenCatalogi\Contract\TableImportQueueInterface|null $queue The import queue.
	 *
	 * @return PublishedTableService The service.
	 */
	private function serviceWithQueue(?\OCA\OpenCatalogi\Contract\TableImportQueueInterface $queue): PublishedTableService {
		$registers = $this->createMock(originalClassName: OpenRegisterLookupInterface::class);
		$registers->method('objectService')->willReturn($this->openRegister);
		$registers->method('registerConfiguration')->willReturnMap(
			[
				['publication_register', 'publication_schema', ['register' => 'pub', 'schema' => 'publ']],
				['publication_register', 'published_table_schema', ['register' => 'pub', 'schema' => 'tbl']],
			]
		);

		return new PublishedTableService(registers: $registers, container: $this->container, queue: $queue);
	}//end serviceWithQueue()

	/**
	 * A file over the large-file limit is stored importing and queued, not imported in the request (REQ-DFA-002).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.3
	 */
	public function testALargeFileIsQueuedNotImported(): void {
		$this->openRegister->rows = array_fill(0, PublishedTableService::LARGE_FILE_ROWS + 1, ['data' => ['naam' => 'Park', 'toestellen' => '4']]);
		$queue = $this->createMock(originalClassName: \OCA\OpenCatalogi\Contract\TableImportQueueInterface::class);
		$queue->expects($this->once())->method('queue')->with('t1', 'anna')->willReturn(true);
		$user = $this->createMock(originalClassName: \OCP\IUser::class);
		$user->method('getUID')->willReturn('anna');

		$table = $this->serviceWithQueue(queue: $queue)->createFromFile(
			publication: ['id' => 'p1', 'slug' => 'speeltuinen'],
			file: new TableFile(path: '/tmp/speeltuinen.csv', id: '77', name: 'speeltuinen.csv'),
			user: $user
		);

		$this->assertSame(expected: 'importing', actual: $table['status']);
		$this->assertSame(expected: 'anna', actual: $table['importedBy']);
		$this->assertSame(expected: [], actual: $this->openRegister->previews);
		$this->assertCount(expectedCount: 1, haystack: $this->openRegister->saved);
		$this->assertCount(expectedCount: 1, haystack: $this->openRegister->createdSchemas);
	}//end testALargeFileIsQueuedNotImported()

	/**
	 * Without a queue, and at the limit, a file is imported in the request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.3
	 */
	public function testAFileAtTheLimitIsImportedInTheRequest(): void {
		$this->openRegister->rows = array_fill(0, PublishedTableService::LARGE_FILE_ROWS, ['data' => ['naam' => 'Park']]);
		$queue = $this->createMock(originalClassName: \OCA\OpenCatalogi\Contract\TableImportQueueInterface::class);
		$queue->expects($this->never())->method('queue');

		$table = $this->serviceWithQueue(queue: $queue)->createFromFile(
			publication: ['id' => 'p1'],
			file: new TableFile(path: '/tmp/a.csv', id: '77', name: 'a.csv')
		);
		$this->assertSame(expected: 'ready', actual: $table['status']);

		$this->openRegister->rows = array_fill(0, PublishedTableService::LARGE_FILE_ROWS + 1, ['data' => ['naam' => 'Park']]);
		$table = $this->serviceWithQueue(queue: null)->createFromFile(
			publication: ['id' => 'p1'],
			file: new TableFile(path: '/tmp/a.csv', id: '77', name: 'a.csv')
		);
		$this->assertSame(expected: 'ready', actual: $table['status']);
	}//end testAFileAtTheLimitIsImportedInTheRequest()

	/**
	 * A stored table is imported again from its publication's attachment, as the publication's system read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.3
	 */
	public function testReimportReadsTheAttachmentOfThePublication(): void {
		$this->openRegister->tempPath = (string)tempnam(sys_get_temp_dir(), 'odt');
		$this->openRegister->schema = new TableFakeEntity(['id' => 42]);
		$publication = new TableFakeEntity(['uuid' => 'p1']);
		$this->openRegister->found = $publication;
		$this->openRegister->file = new TableFakeEntity(['name' => 'speeltuinen.csv', 'id' => 77, 'content' => "naam\nPark\n"]);

		$table = $this->serviceWithQueue(queue: null)->reimport(
			table: ['id' => 't9', 'publication' => 'p1', 'file' => '77', 'schema' => '42', 'status' => 'importing'],
			importedBy: 'bram'
		);

		$this->assertSame(expected: 'p1', actual: $this->openRegister->finds[0]['id']);
		$this->assertSame(expected: ['getFile' => [$publication, 77]], actual: $this->openRegister->finds[1]);
		$this->assertSame(expected: 'ready', actual: $table['status']);
		$this->assertSame(expected: 'bram', actual: $table['importedBy']);
		unlink($this->openRegister->tempPath);
	}//end testReimportReadsTheAttachmentOfThePublication()

	/**
	 * A table whose publication is gone cannot be imported again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.3
	 */
	public function testReimportOfATableWhosePublicationIsGoneIsRefused(): void {
		$this->openRegister->found = new \RuntimeException('not found');

		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'The publication of this table is gone.');
		$this->serviceWithQueue(queue: null)->reimport(table: ['id' => 't9', 'publication' => 'p1', 'file' => '77']);
	}//end testReimportOfATableWhosePublicationIsGoneIsRefused()
}//end class
