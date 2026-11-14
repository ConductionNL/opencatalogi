<?php

/**
 * Published tables: a CSV attachment's rows as OpenRegister objects, and their dictionary.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Table
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Table;

use InvalidArgumentException;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Contract\PublishedTableServiceInterface;
use OCA\OpenCatalogi\Contract\TableFile;
use OCA\OpenRegister\Service\Import\ImportCommit;
use OCP\IUser;
use Psr\Container\ContainerInterface;

/**
 * Turns a CSV attachment into a table through OpenRegister's own import, and
 * answers a table's rows and its data dictionary.
 *
 * The rows are imported by OpenRegister's import preview and commit, never by
 * code here: this class only builds the schema from the header and decides
 * whether the preview may be committed.
 *
 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
 */
class PublishedTableService implements PublishedTableServiceInterface {

	/**
	 * The slug of the register that holds every table's rows.
	 *
	 * @var string
	 */
	public const ROWS_REGISTER = 'open-data-tables';

	/**
	 * A table whose rows are being imported.
	 *
	 * @var string
	 */
	public const STATUS_IMPORTING = 'importing';

	/**
	 * A table whose rows are all imported.
	 *
	 * @var string
	 */
	public const STATUS_READY = 'ready';

	/**
	 * A table whose import was refused; no row was written.
	 *
	 * @var string
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * The largest page of rows one request answers.
	 *
	 * @var integer
	 */
	public const MAX_LIMIT = 1000;

	/**
	 * Query parameters that are not a filter on a column.
	 *
	 * @var array<int, string>
	 */
	private const CONTROL_PARAMETERS = ['_search', '_order', '_limit', '_page', 'catalogSlug', 'publicationId', 'tableId', '_route'];

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterLookupInterface $registers The OpenRegister ObjectService and register configuration.
	 * @param ContainerInterface $container Resolves OpenRegister's schema, register and import services.
	 */
	public function __construct(
		private readonly OpenRegisterLookupInterface $registers,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Publish a CSV attachment of a publication as a table.
	 *
	 * Builds the schema from the header and the first rows, previews the import
	 * of the whole file into it, and commits only when the preview refuses no row.
	 * The table record says `ready` with its row count, or `failed` with the
	 * first refused row.
	 *
	 * @param array<string, mixed> $publication The publication the file belongs to.
	 * @param TableFile $file The CSV on disk and its identity.
	 * @param IUser|null $user The editor.
	 *
	 * @return array<string, mixed> The stored table record.
	 *
	 * @throws InvalidArgumentException When the file has no rows.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
	 */
	public function createFromFile(array $publication, TableFile $file, ?IUser $user = null): array {
		$rows = $this->service(class: 'OCA\OpenRegister\Service\Import\SourceRowReader')->read($file->path, 'csv');
		$sample = array_map(
			static fn (array $row): array => (array)($row['data'] ?? []),
			array_slice($rows, 0, ColumnTypes::SAMPLE_SIZE)
		);
		if ($sample === []) {
			throw new InvalidArgumentException('The file "'.$file->name.'" holds no rows.');
		}

		$register = $this->service(class: 'OCA\OpenRegister\Db\RegisterMapper')->find(self::ROWS_REGISTER);
		$schema = $this->createRowSchema(register: $register, publication: $publication, file: $file, sample: $sample);

		$table = [
			'publication' => (string)($publication['id'] ?? ''),
			'file' => $file->id,
			'title' => $file->name,
			'schema' => (string)$schema->getId(),
			'rowCount' => 0,
			'status' => self::STATUS_IMPORTING,
		];

		return $this->tables()->save(object: array_merge($table, $this->import(register: $register, schema: $schema, file: $file, user: $user)));
	}//end createFromFile()

	/**
	 * Create the schema that holds one table's rows and attach it to the rows register.
	 *
	 * @param mixed $register The rows register.
	 * @param array<string, mixed> $publication The publication.
	 * @param TableFile $file The CSV.
	 * @param array<int, array<string, mixed>> $sample The first rows.
	 *
	 * @return mixed The created schema.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
	 */
	private function createRowSchema(mixed $register, array $publication, TableFile $file, array $sample): mixed {
		$publicationSlug = (string)($publication['slug'] ?? $publication['id'] ?? 'publication');
		$schema = $this->service(class: 'OCA\OpenRegister\Db\SchemaMapper')->createFromArray(
			[
				'title' => $file->name,
				'slug' => 'table-'.$publicationSlug.'-'.$file->id,
				'description' => 'The rows of '.$file->name.'.',
				'properties' => ColumnTypes::properties(header: array_keys($sample[0]), rows: $sample),
				// Rows are served by the public rows endpoint, which checks that the
				// publication is readable first; direct reads stay with signed-in users.
				'authorization' => ['read' => ['authenticated'], 'create' => ['admin'], 'update' => ['admin'], 'delete' => ['admin']],
			]
		);

		$register->setSchemas(array_values(array_unique(array_merge((array)$register->getSchemas(), [$schema->getId()]))));
		$this->service(class: 'OCA\OpenRegister\Db\RegisterMapper')->update($register);

		return $schema;
	}//end createRowSchema()

	/**
	 * Preview the import of the whole file and commit it when no row is refused.
	 *
	 * @param mixed $register The rows register.
	 * @param mixed $schema The table's schema.
	 * @param TableFile $file The CSV.
	 * @param IUser|null $user The editor.
	 *
	 * @return array<string, mixed> The status fields of the table record.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
	 */
	private function import(mixed $register, mixed $schema, TableFile $file, ?IUser $user): array {
		$importer = $this->service(class: 'OCA\OpenRegister\Service\Import\ImportPreviewServiceInterface');
		$preview = $importer->preview(
			[
				'register' => $register->getId(),
				'schema' => $schema->getId(),
				'filePath' => $file->path,
				'sourceName' => $file->name,
				'format' => 'csv',
			],
			$user
		);

		$refusal = self::firstFailure(preview: $preview);
		if ($refusal !== null) {
			return ['status' => self::STATUS_FAILED, 'lastError' => $refusal];
		}

		$committed = $importer->commit($preview, new ImportCommit(sourceHash: $preview->getSourceHash(), currentUser: $user));
		$failure = self::firstFailure(preview: $committed);
		if ($failure !== null) {
			return ['status' => self::STATUS_FAILED, 'lastError' => $failure];
		}

		return [
			'status' => self::STATUS_READY,
			'rowCount' => (int)$committed->getApplied(),
			'importedAt' => gmdate('c'),
		];
	}//end import()

	/**
	 * Why a preview or a commit cannot stand: its first refused row, or its error.
	 *
	 * @param mixed $preview The import preview.
	 *
	 * @return string|null The reason, or null when nothing was refused.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
	 */
	private static function firstFailure(mixed $preview): ?string {
		$report = (array)($preview->getReport() ?? []);
		$refusal = ($report['refusals'][0] ?? null);
		if (is_array($refusal) === true) {
			return 'Row '.(int)($refusal['row'] ?? 0).': '.(string)($refusal['reason'] ?? 'refused');
		}

		if ((int)$preview->getToRefuse() > 0) {
			return 'The import refused '.(int)$preview->getToRefuse().' rows.';
		}

		if ($preview->getState() === 'failed' || (int)$preview->getFailed() > 0) {
			return (string)($report['error'] ?? 'The import failed.');
		}

		return null;
	}//end firstFailure()

	/**
	 * Find a table record.
	 *
	 * @param string $tableId The table id.
	 *
	 * @return array<string, mixed>|null The record, or null when there is none.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-anyone-queries-the-rows-of-a-public-table-req-odt-002
	 */
	public function findTable(string $tableId): ?array {
		$config = $this->registers->registerConfiguration(registerKey: 'publication_register', schemaKey: 'published_table_schema');
		try {
			$table = $this->registers->objectService()->find(
				id: $tableId,
				_extend: [],
				register: $config['register'],
				schema: $config['schema'],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			return null;
		}

		$table = self::plain(value: $table);
		if (is_array($table) === false) {
			return null;
		}

		return $table;
	}//end findTable()

	/**
	 * Whether the caller may read a publication: OpenRegister finds it with RBAC on.
	 *
	 * @param string $publicationId The publication id.
	 *
	 * @return boolean True when the caller can read it.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-anyone-queries-the-rows-of-a-public-table-req-odt-002
	 */
	public function canReadPublication(string $publicationId): bool {
		try {
			return $this->registers->objectService()->find(id: $publicationId, _extend: [], _rbac: true, _multitenancy: false) !== null;
		} catch (\Throwable $e) {
			return false;
		}
	}//end canReadPublication()

	/**
	 * The data dictionary of a table: each column's name, title, description and type.
	 *
	 * @param array<string, mixed> $table The table record.
	 *
	 * @return array<int, array<string, string>> The columns, in schema order.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-readers-see-what-each-column-means-req-odt-003
	 */
	public function dictionary(array $table): array {
		$columns = [];
		foreach ($this->columns(table: $table) as $name => $property) {
			$columns[] = [
				'name' => (string)$name,
				'title' => (string)($property['title'] ?? $name),
				'description' => (string)($property['description'] ?? ''),
				'type' => (string)($property['format'] ?? $property['type'] ?? 'string'),
			];
		}

		return $columns;
	}//end dictionary()

	/**
	 * The query parameters that filter on a column the table does not declare.
	 *
	 * @param array<string, mixed> $query The request's query parameters.
	 * @param array<string, mixed> $table The table record.
	 *
	 * @return array<int, string> The unknown column names.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-anyone-queries-the-rows-of-a-public-table-req-odt-002
	 */
	public function undeclaredFilters(array $query, array $table): array {
		$declared = array_keys($this->columns(table: $table));
		$filters = array_diff(array_map('strval', array_keys($query)), self::CONTROL_PARAMETERS);

		return array_values(array_diff($filters, $declared));
	}//end undeclaredFilters()

	/**
	 * One page of a table's rows.
	 *
	 * The caller has already checked that the publication is readable, so the
	 * rows are read as the system. Only filters on declared columns, `_search`,
	 * `_order`, `_limit` (at most {@see MAX_LIMIT}) and `_page` pass through.
	 *
	 * @param array<string, mixed> $table The table record.
	 * @param array<string, mixed> $query The request's query parameters.
	 *
	 * @return array<string, mixed> The rows with `total`, `page` and `pages`.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-anyone-queries-the-rows-of-a-public-table-req-odt-002
	 */
	public function rows(array $table, array $query): array {
		$declared = array_keys($this->columns(table: $table));
		$search = array_intersect_key($query, array_flip(array_merge($declared, ['_search', '_order', '_page'])));
		$search['_limit'] = max(1, min(self::MAX_LIMIT, (int)($query['_limit'] ?? 20)));
		$search['@self'] = ['register' => $this->rowsRegisterId(), 'schema' => (string)$table['schema']];

		$found = $this->registers->objectService()->searchObjectsPaginated(query: $search, _rbac: false, _multitenancy: false);
		$results = array_map(
			static fn (mixed $row): mixed => self::plain(value: $row),
			(array)($found['results'] ?? [])
		);

		return [
			'results' => $results,
			'total' => (int)($found['total'] ?? count($results)),
			'page' => (int)($found['page'] ?? ($query['_page'] ?? 1)),
			'pages' => (int)($found['pages'] ?? 1),
		];
	}//end rows()

	/**
	 * An OpenRegister entity as its array, anything else as it is.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The plain value.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-anyone-queries-the-rows-of-a-public-table-req-odt-002
	 */
	private static function plain(mixed $value): mixed {
		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			return $value->jsonSerialize();
		}

		return $value;
	}//end plain()

	/**
	 * The declared columns of a table's schema.
	 *
	 * @param array<string, mixed> $table The table record.
	 *
	 * @return array<string, array<string, mixed>> The properties, keyed by column name.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-readers-see-what-each-column-means-req-odt-003
	 */
	private function columns(array $table): array {
		$schema = $this->service(class: 'OCA\OpenRegister\Db\SchemaMapper')->find((string)($table['schema'] ?? ''));

		return array_filter((array)$schema->getProperties(), 'is_array');
	}//end columns()

	/**
	 * The id of the rows register.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-anyone-queries-the-rows-of-a-public-table-req-odt-002
	 */
	private function rowsRegisterId(): string {
		return (string)$this->service(class: 'OCA\OpenRegister\Db\RegisterMapper')->find(self::ROWS_REGISTER)->getId();
	}//end rowsRegisterId()

	/**
	 * The table records, read and written as the system.
	 *
	 * @return \OCA\OpenCatalogi\Service\SchemaObjects The bound objects.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
	 */
	private function tables(): \OCA\OpenCatalogi\Service\SchemaObjects {
		$config = $this->registers->registerConfiguration(registerKey: 'publication_register', schemaKey: 'published_table_schema');

		return new \OCA\OpenCatalogi\Service\SchemaObjects(
			objects: $this->registers->objectService(),
			target: [$config['register'], $config['schema']]
		);
	}//end tables()

	/**
	 * An OpenRegister service from the container.
	 *
	 * @param string $class The service class.
	 *
	 * @return mixed The service.
	 *
	 * @spec exclude pure framework plumbing: the runtime-guarded OpenRegister dependency (ADR-083).
	 */
	private function service(string $class): mixed {
		return $this->container->get($class);
	}//end service()
}//end class
