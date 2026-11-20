<?php

/**
 * What happens to a published table's import outside the request that started it.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/data-file-import-failure-alert/specs/open-data-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Table;

use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Contract\TableImportQueueInterface;
use OCA\OpenCatalogi\Service\SchemaObjects;
use OCP\AppFramework\Utility\ITimeFactory;
use Throwable;

/**
 * Runs a queued import, ends imports that stopped without an answer, and
 * queues a re-import when a table's file is replaced.
 *
 * Every path ends a table `ready` or `failed`, never `importing`: the failed
 * import notification is declared on the update to `failed` (design D1, D3).
 *
 * @spec openspec/changes/data-file-import-failure-alert/specs/open-data-tables/spec.md
 */
class TableImportFollowUp {

	/**
	 * An import still `importing` after this many seconds stopped without an answer.
	 *
	 * @var integer
	 */
	public const STALE_AFTER_SECONDS = 3600;

	/**
	 * The last error of an import that stopped without an answer.
	 *
	 * @var string
	 */
	public const STALE_ERROR = 'The import stopped without an answer.';

	/**
	 * Constructor.
	 *
	 * @param PublishedTableService       $tables    Imports a stored table again from its attachment.
	 * @param OpenRegisterLookupInterface $registers The OpenRegister ObjectService and register configuration.
	 * @param TableImportQueueInterface   $queue     Queues an import.
	 * @param ITimeFactory                $time      The clock.
	 */
	public function __construct(
		private readonly PublishedTableService $tables,
		private readonly OpenRegisterLookupInterface $registers,
		private readonly TableImportQueueInterface $queue,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Run the queued import of one table; anything thrown ends it `failed` with the message.
	 *
	 * @param string $tableId    The table.
	 * @param string $importedBy Who started it.
	 *
	 * @return array<string, mixed>|null The stored table record, or null when the table is gone.
	 *
	 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.3
	 */
	public function run(string $tableId, string $importedBy): ?array {
		$table = $this->tables->findTable(tableId: $tableId);
		if ($table === null) {
			return null;
		}

		$who = $importedBy;
		if ($who === '') {
			$who = (string)($table['importedBy'] ?? '');
		}

		try {
			return $this->tables->reimport(table: $table, importedBy: $who);
		} catch (Throwable $e) {
			return $this->fail(table: array_merge($table, ['importedBy' => $who]), message: $e->getMessage());
		}
	}//end run()

	/**
	 * End every table that has been `importing` for over an hour as `failed`.
	 *
	 * @param string $except A table to leave alone: the one the running job imports.
	 *
	 * @return integer How many tables were ended.
	 *
	 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.3
	 */
	public function failStaleImports(string $except = ''): int {
		$found = self::plain(value: $this->objects()->search(filters: ['status' => PublishedTableService::STATUS_IMPORTING, '_limit' => 100]));
		$limit = $this->time->getTime() - self::STALE_AFTER_SECONDS;
		$ended = 0;
		foreach ((array)($found['results'] ?? []) as $record) {
			$table = self::plain(value: $record);
			if (is_array($table) === false || ($table['status'] ?? null) !== PublishedTableService::STATUS_IMPORTING) {
				continue;
			}

			$since = self::startedAt(table: $table);
			if ((string)($table['id'] ?? '') === $except || $since === null || $since > $limit) {
				continue;
			}

			$this->fail(table: $table, message: self::STALE_ERROR);
			$ended++;
		}

		return $ended;
	}//end failStaleImports()

	/**
	 * Queue a re-import of every table published from a file that was replaced.
	 *
	 * @param int    $fileId     The replaced file.
	 * @param string $importedBy Who replaced it; empty keeps the table's last importer.
	 *
	 * @return integer How many re-imports were queued.
	 *
	 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.4
	 */
	public function queueForFile(int $fileId, string $importedBy): int {
		if ($fileId <= 0) {
			return 0;
		}

		$found = self::plain(value: $this->objects()->search(filters: ['file' => (string)$fileId, '_limit' => 100]));
		$queued = 0;
		foreach ((array)($found['results'] ?? []) as $record) {
			$table = self::plain(value: $record);
			if (is_array($table) === false || (string)($table['file'] ?? '') !== (string)$fileId) {
				continue;
			}

			$who = $importedBy;
			if ($who === '') {
				$who = (string)($table['importedBy'] ?? '');
			}

			if ($this->queue->queue(tableId: (string)($table['id'] ?? ''), importedBy: $who) === true) {
				$queued++;
			}
		}

		return $queued;
	}//end queueForFile()

	/**
	 * Store a table `failed` with a message.
	 *
	 * @param array<string, mixed> $table   The table record.
	 * @param string               $message Why.
	 *
	 * @return array<string, mixed> The stored record.
	 *
	 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.3
	 */
	private function fail(array $table, string $message): array {
		$message = trim($message);
		if ($message === '') {
			$message = 'The import failed.';
		}

		$table['status'] = PublishedTableService::STATUS_FAILED;
		$table['lastError'] = mb_substr($message, 0, 2000);
		unset($table['@self']);

		return (array)self::plain(value: $this->objects()->save(object: $table));
	}//end fail()

	/**
	 * When a table's current import started: the last time its record was written.
	 *
	 * @param array<string, mixed> $table The table record.
	 *
	 * @return integer|null The Unix time, or null when the record says nothing readable.
	 */
	private static function startedAt(array $table): ?int {
		$updated = ($table['@self']['updated'] ?? ($table['updated'] ?? null));
		if (is_string($updated) === false || $updated === '') {
			return null;
		}

		$time = strtotime($updated);
		if ($time === false) {
			return null;
		}

		return $time;
	}//end startedAt()

	/**
	 * The table records, read and written as the system.
	 *
	 * @return SchemaObjects The bound objects.
	 */
	private function objects(): SchemaObjects {
		$config = $this->registers->registerConfiguration(registerKey: 'publication_register', schemaKey: 'published_table_schema');

		return new SchemaObjects(objects: $this->registers->objectService(), target: [$config['register'], $config['schema']]);
	}//end objects()

	/**
	 * A serialisable value as plain data.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The plain value.
	 */
	private static function plain(mixed $value): mixed {
		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			return $value->jsonSerialize();
		}

		return $value;
	}//end plain()
}//end class
