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
use OCA\OpenCatalogi\Contract\TableImportQueueInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\Import\ImportCommit;
use OCP\IURLGenerator;
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
	 * A file with more rows than this is imported by a queued job, not in the request.
	 *
	 * @var integer
	 */
	public const LARGE_FILE_ROWS = 5000;

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
	 * The longest column title or description an editor can store.
	 */
	public const MAX_TEXT = 2000;

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
	 * @param IURLGenerator|null $urls The URL generator for the rows and dictionary URLs; null leaves them empty.
	 * @param TableImportQueueInterface|null $queue Queues the import of a large file; null imports every file in the request.
	 */
	public function __construct(
		private readonly OpenRegisterLookupInterface $registers,
		private readonly ContainerInterface $container,
		private readonly ?IURLGenerator $urls = null,
		private readonly ?TableImportQueueInterface $queue = null,
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
	 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.3
	 */
	public function createFromFile(array $publication, TableFile $file, ?IUser $user = null): array {
		$rows = $this->rowsOf(file: $file);
		$sample = array_slice($rows, 0, ColumnTypes::SAMPLE_SIZE);
		$register = $this->service(class: 'OCA\OpenRegister\Db\RegisterMapper')->find(self::ROWS_REGISTER);
		$schema = $this->createRowSchema(register: $register, publication: $publication, file: $file, sample: $sample);

		$table = [
			'publication' => (string)($publication['id'] ?? ''),
			'file' => $file->id,
			'title' => $file->name,
			'schema' => (string)$schema->getId(),
			'rowCount' => 0,
		];

		if ($this->queue !== null && count($rows) > self::LARGE_FILE_ROWS) {
			return $this->queued(table: $table, importedBy: (string)$user?->getUID());
		}

		return $this->lifecycle(table: $table, run: new TableImportRun(file: $file, user: $user, schema: $schema));
	}//end createFromFile()

	/**
	 * Store a table importing and hand its import to the queue (REQ-DFA-002).
	 *
	 * @param array<string, mixed> $table      The table record.
	 * @param string               $importedBy Who started it.
	 *
	 * @return array<string, mixed> The stored table record, `importing`.
	 *
	 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.3
	 */
	private function queued(array $table, string $importedBy): array {
		$table['status'] = self::STATUS_IMPORTING;
		$table['importedBy'] = $importedBy;
		$stored = (array)self::plain(value: $this->tables()->save(object: $table));
		$this->queue?->queue(tableId: (string)($stored['id'] ?? ''), importedBy: $importedBy);

		return $stored;
	}//end queued()

	/**
	 * Import a stored table again from its publication's attachment.
	 *
	 * Used by the queued import of a large file, a retry and a replaced file.
	 * The table goes through importing to ready or failed like a first import.
	 *
	 * @param array<string, mixed> $table      The stored table record.
	 * @param string|null          $importedBy Who started it.
	 *
	 * @return array<string, mixed> The stored table record.
	 *
	 * @throws InvalidArgumentException When the publication or its attachment is gone.
	 *
	 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.3
	 */
	public function reimport(array $table, ?string $importedBy = null): array {
		$pair = $this->registers->registerConfiguration(registerKey: 'publication_register', schemaKey: 'publication_schema');
		$where = new ObjectFindOptions(register: $pair['register'], schema: $pair['schema']);
		try {
			$publication = $this->registers->objectService()->find(id: (string)($table['publication'] ?? ''), options: $where, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			throw new InvalidArgumentException('The publication of this table is gone.');
		}

		$file = $this->attachmentFile(publication: $publication, fileId: (string)($table['file'] ?? ''));

		return $this->importTable(table: $table, file: $file, importedBy: $importedBy);
	}//end reimport()

	/**
	 * The table records matching filters, read as the system.
	 *
	 * @param array<string, mixed> $filters The filters on the table schema.
	 *
	 * @return array<int, array<string, mixed>> The records.
	 *
	 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.3
	 */
	public function findTables(array $filters): array {
		$found = (array)self::plain(value: $this->tables()->search(filters: $filters));

		return array_values(array_filter(array_map(static fn (mixed $record): mixed => self::plain(value: $record), (array)($found['results'] ?? [])), 'is_array'));
	}//end findTables()

	/**
	 * Store a table record as the system.
	 *
	 * @param array<string, mixed> $table The record.
	 *
	 * @return array<string, mixed> The stored record.
	 *
	 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.3
	 */
	public function storeTable(array $table): array {
		return (array)self::plain(value: $this->tables()->save(object: $table));
	}//end storeTable()

	/**
	 * The rows of a CSV file; the first ones give the row schema's column types.
	 *
	 * @param TableFile $file The CSV on disk and its identity.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @throws InvalidArgumentException When the file has no rows.
	 */
	private function rowsOf(TableFile $file): array {
		$rows = array_map(
			static fn (array $row): array => (array)($row['data'] ?? []),
			$this->service(class: 'OCA\OpenRegister\Service\Import\SourceRowReader')->read($file->path, 'csv')
		);
		if ($rows === []) {
			throw new InvalidArgumentException('The file "'.$file->name.'" holds no rows.');
		}

		return $rows;
	}//end rowsOf()

	/**
	 * Import a table's file into its row schema, from importing to ready or failed.
	 *
	 * The table is stored as `importing` first, with the user who started it in
	 * `importedBy`, and then updated to `ready` or `failed`: the failed-import
	 * notification fires on an update to `failed`, so a table is never created
	 * as `failed`. An exception during the import ends the table `failed` with
	 * its message instead of leaving it `importing`.
	 *
	 * @param array<string, mixed> $table      The table record, new or stored.
	 * @param TableFile            $file       The CSV on disk and its identity.
	 * @param IUser|null           $user       The editor, handed to the importer.
	 * @param string|null          $importedBy Who started it, when not the editor (a queued re-import).
	 *
	 * @return array<string, mixed> The stored table record.
	 *
	 * @spec openspec/changes/data-file-import-failure-alert/specs/open-data-tables/spec.md
	 */
	public function importTable(array $table, TableFile $file, ?IUser $user = null, ?string $importedBy = null): array {
		return $this->lifecycle(table: $table, run: new TableImportRun(file: $file, user: $user, importedBy: $importedBy));
	}//end importTable()

	/**
	 * Store the table importing, import, and store it ready or failed.
	 *
	 * @param array<string, mixed> $table The table record.
	 * @param TableImportRun       $run   The CSV, the editor, who started it when not the editor, and
	 *                                    the row schema when just created (else looked up from the table).
	 *
	 * @return array<string, mixed> The stored table record.
	 */
	private function lifecycle(array $table, TableImportRun $run): array {
		$table['status'] = self::STATUS_IMPORTING;
		$table['importedBy'] = ($run->importedBy ?? ($run->user?->getUID() ?? (string)($table['importedBy'] ?? '')));
		unset($table['lastError']);
		$stored = (array)self::plain(value: $this->tables()->save(object: $table));

		try {
			$outcome = $this->import(table: $table, run: $run);
		} catch (\Throwable $e) {
			$outcome = ['status' => self::STATUS_FAILED, 'lastError' => self::errorText(message: $e->getMessage())];
		}

		return (array)self::plain(value: $this->tables()->save(object: array_merge($stored, $outcome)));
	}//end lifecycle()

	/**
	 * An exception's message as a table's last error.
	 *
	 * @param string $message The message.
	 *
	 * @return string The error, never empty, at most the property's 2000 characters.
	 */
	private static function errorText(string $message): string {
		$message = trim($message);
		if ($message === '') {
			$message = 'The import failed.';
		}

		return mb_substr($message, 0, 2000);
	}//end errorText()

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
	 * @param array<string, mixed> $table The table record; its schema is read when the run has none.
	 * @param TableImportRun       $run   The CSV, the editor and the row schema when just created.
	 *
	 * @return array<string, mixed> The status fields of the table record.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
	 */
	private function import(array $table, TableImportRun $run): array {
		$register = $this->service(class: 'OCA\OpenRegister\Db\RegisterMapper')->find(self::ROWS_REGISTER);
		$schema = ($run->schema ?? $this->service(class: 'OCA\OpenRegister\Db\SchemaMapper')->find((string)$table['schema']));
		$file = $run->file;
		$user = $run->user;
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
				options: new ObjectFindOptions(register: $config['register'], schema: $config['schema']),
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
			return $this->registers->objectService()->find(id: $publicationId, _rbac: true, _multitenancy: false) !== null;
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
	 * Publish a CSV attachment of a publication as a table (REQ-ODT-001).
	 *
	 * The caller has checked that the user may update the publication. The file
	 * is read from the publication's own attachments, copied to a temporary file
	 * OpenRegister's reader can open, and imported through createFromFile().
	 *
	 * @param object     $publication The publication as OpenRegister returned it.
	 * @param string     $fileId      The attachment's file id.
	 * @param IUser|null $user        The editor.
	 *
	 * @return array<string, mixed> The stored table record.
	 *
	 * @throws InvalidArgumentException When the publication has no such attachment, or it is not a CSV.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
	 */
	public function publishAttachment(object $publication, string $fileId, ?IUser $user = null): array {
		$file = $this->attachmentFile(publication: $publication, fileId: $fileId);
		$record = self::plain(value: $publication);

		return $this->createFromFile(
			publication: is_array($record) === true ? $record : ['id' => (string)$publication->getUuid()],
			file: $file,
			user: $user
		);
	}//end publishAttachment()

	/**
	 * A CSV attachment of the publication, copied to a temporary file OpenRegister's reader can open.
	 *
	 * @param object $publication The publication as OpenRegister returned it.
	 * @param string $fileId      The attachment's file id.
	 *
	 * @return TableFile The copy on disk and the attachment's identity.
	 *
	 * @throws InvalidArgumentException When the publication has no such attachment, or it is not a CSV.
	 */
	private function attachmentFile(object $publication, string $fileId): TableFile {
		$file = $this->service(class: 'OCA\OpenRegister\Service\FileService')->getFile(object: $publication, file: (int)$fileId);
		if ($file === null) {
			throw new InvalidArgumentException('The publication has no attachment '.$fileId.'.');
		}

		$name = (string)$file->getName();
		if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
			throw new InvalidArgumentException('Only a CSV attachment can be published as a table; "'.$name.'" is not one.');
		}

		$path = (string)$this->service(class: 'OCP\ITempManager')->getTemporaryFile('.csv');
		file_put_contents($path, (string)$file->getContent());

		return new TableFile(path: $path, id: (string)$file->getId(), name: $name);
	}//end attachmentFile()

	/**
	 * Every table of a publication, whatever its status, for its editors.
	 *
	 * @param string $publicationId The publication.
	 *
	 * @return array<int, array<string, mixed>> The table records, each with its `dictionary` when it is ready.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-readers-see-what-each-column-means-req-odt-003
	 */
	public function tablesFor(string $publicationId): array {
		if ($publicationId === '') {
			return [];
		}

		$found = $this->tables()->search(filters: ['publication' => $publicationId, '_limit' => 100]);
		$tables = [];
		foreach ((array)(self::plain(value: $found)['results'] ?? []) as $record) {
			$table = self::plain(value: $record);
			if (is_array($table) === false) {
				continue;
			}

			if (($table['status'] ?? null) === self::STATUS_READY) {
				$table['dictionary'] = $this->dictionary(table: $table);
			}

			$tables[] = $table;
		}

		return $tables;
	}//end tablesFor()

	/**
	 * Write the titles and descriptions an editor gave a table's columns (REQ-ODT-003).
	 *
	 * Only columns the table declares are written, and only their title and
	 * description; the names and types stay as the import made them.
	 *
	 * @param array<string, mixed>            $table   The table record.
	 * @param array<int, array<string,mixed>> $columns Each `{name, title?, description?}`.
	 *
	 * @return array<int, array<string, string>> The dictionary after the change.
	 *
	 * @throws InvalidArgumentException When a column is not one the table declares.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-readers-see-what-each-column-means-req-odt-003
	 */
	public function updateDictionary(array $table, array $columns): array {
		$mapper = $this->service(class: 'OCA\OpenRegister\Db\SchemaMapper');
		$schema = $mapper->find((string)($table['schema'] ?? ''));
		$properties = (array)$schema->getProperties();
		foreach ($columns as $column) {
			$name = (string)(is_array($column) === true ? ($column['name'] ?? '') : '');
			if (is_array($properties[$name] ?? null) === false) {
				throw new InvalidArgumentException('This table has no column named '.$name.'.');
			}

			$properties[$name] = array_merge($properties[$name], self::describedColumn(column: $column));
		}

		$schema->setProperties($properties);
		$mapper->update($schema);

		return $this->dictionary(table: $table);
	}//end updateDictionary()

	/**
	 * The title and description an editor gave one column, trimmed and capped; an empty title is left out.
	 *
	 * @param array<string, mixed> $column The column as the editor sent it.
	 *
	 * @return array<string, string> The `title` and `description` it carries.
	 */
	private static function describedColumn(array $column): array {
		$described = [];
		foreach (['title', 'description'] as $field) {
			$text = is_string($column[$field] ?? null) === true ? mb_substr(trim($column[$field]), 0, self::MAX_TEXT) : null;
			// A column always keeps a title; an empty one leaves the current title.
			if ($text !== null && ($text !== '' || $field === 'description')) {
				$described[$field] = $text;
			}
		}

		return $described;
	}//end describedColumn()

	/**
	 * The ready tables of a publication, each with its rows and dictionary URLs.
	 *
	 * @param string $publicationId The publication.
	 * @param string $catalogSlug   The catalogue the URLs are under.
	 *
	 * @return array<int, array<string, mixed>> The tables: id, title, file, rowCount, rowsUrl, dictionaryUrl.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-readers-see-what-each-column-means-req-odt-003
	 */
	public function tablesOf(string $publicationId, string $catalogSlug): array {
		if ($publicationId === '') {
			return [];
		}

		$found = $this->tables()->search(filters: ['publication' => $publicationId, 'status' => self::STATUS_READY, '_limit' => 100]);
		$tables = [];
		foreach ((array)(self::plain(value: $found)['results'] ?? []) as $record) {
			$table = self::plain(value: $record);
			if (is_array($table) === true && ($table['status'] ?? null) === self::STATUS_READY) {
				$tables[] = $this->tableEntry(table: $table, where: ['catalogSlug' => $catalogSlug, 'publicationId' => $publicationId]);
			}
		}

		return $tables;
	}//end tablesOf()

	/**
	 * Give each file published as a table its `dataDictionaryUrl`, for the DCAT distribution.
	 *
	 * @param array<int, array<string, mixed>> $files         formatFiles-shape file entries.
	 * @param string                           $publicationId The publication the files belong to.
	 * @param string                           $catalogSlug   The catalogue the URLs are under.
	 *
	 * @return array<int, array<string, mixed>> The files, a table's file with its dictionary URL.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-readers-see-what-each-column-means-req-odt-003
	 */
	public function withDictionaryUrls(array $files, string $publicationId, string $catalogSlug): array {
		$urls = [];
		foreach ($this->tablesOf(publicationId: $publicationId, catalogSlug: $catalogSlug) as $table) {
			$urls[$table['file']] ??= $table['dictionaryUrl'];
		}

		return self::withUrls(files: $files, urls: $urls);
	}//end withDictionaryUrls()

	/**
	 * The files, each one whose id has a dictionary URL with its `dataDictionaryUrl`.
	 *
	 * @param array<int, mixed>     $files The file entries.
	 * @param array<string, string> $urls  Dictionary URL by file id.
	 *
	 * @return array<int, mixed> The files; unchanged without URLs.
	 */
	private static function withUrls(array $files, array $urls): array {
		if ($urls === []) {
			return $files;
		}

		return array_map(
			static function (mixed $file) use ($urls): mixed {
				if (is_array($file) === false || ($urls[(string)($file['id'] ?? '')] ?? '') === '') {
					return $file;
				}

				return array_merge($file, ['dataDictionaryUrl' => $urls[(string)$file['id']]]);
			},
			$files
		);
	}//end withUrls()

	/**
	 * One table as the public publication answer lists it.
	 *
	 * @param array<string, mixed>                              $table The table record.
	 * @param array{catalogSlug: string, publicationId: string} $where The catalogue and the publication.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function tableEntry(array $table, array $where): array {
		$id = (string)($table['id'] ?? $table['@self']['id'] ?? '');
		$where['tableId'] = $id;

		return [
			'id' => $id,
			'title' => (string)($table['title'] ?? ''),
			'file' => (string)($table['file'] ?? ''),
			'rowCount' => (int)($table['rowCount'] ?? 0),
			'rowsUrl' => $this->tableUrl(route: 'rows', parameters: $where),
			'dictionaryUrl' => $this->tableUrl(route: 'dictionary', parameters: $where),
		];
	}//end tableEntry()

	/**
	 * The absolute URL of a table's public rows or dictionary endpoint.
	 *
	 * @param string                                                             $route      `rows` or `dictionary`.
	 * @param array{catalogSlug: string, publicationId: string, tableId: string} $parameters The route's parameters.
	 *
	 * @return string The URL; empty without a URL generator.
	 */
	private function tableUrl(string $route, array $parameters): string {
		if ($this->urls === null) {
			return '';
		}

		return $this->urls->linkToRouteAbsolute(
			'opencatalogi.publishedTable.'.$route,
			$parameters
		);
	}//end tableUrl()

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
