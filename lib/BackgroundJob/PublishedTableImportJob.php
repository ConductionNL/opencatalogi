<?php

/**
 * OpenCatalogi published table import job.
 *
 * Imports one published table's file in the background: a file over
 * PublishedTableService::BACKGROUND_ROWS rows, or a replaced file. Every run
 * ends the table `ready` or `failed`, so a crashed import still alerts, and
 * first fails every table that has been `importing` for over an hour.
 *
 * @category BackgroundJob
 * @package  OCA\OpenCatalogi\BackgroundJob
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
 * @spec openspec/changes/data-file-import-failure-alert/specs/open-data-tables/spec.md#requirement-every-import-ends-in-ready-or-failed-req-dfa-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\BackgroundJob;

use OCA\OpenCatalogi\Service\Table\PublishedTableService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Imports one queued table and fails the tables whose import stopped.
 *
 * @spec openspec/changes/data-file-import-failure-alert/specs/open-data-tables/spec.md#requirement-every-import-ends-in-ready-or-failed-req-dfa-002
 */
class PublishedTableImportJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param PublishedTableService $tables The tables and their import.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly PublishedTableService $tables,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

	}//end __construct()

	/**
	 * Fail the stale imports, then import the queued table.
	 *
	 * Every Throwable ends the table `failed` with its message: a table is
	 * never left `importing` by this job.
	 *
	 * @param mixed $argument `{table, importedBy}`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-file-import-failure-alert/specs/open-data-tables/spec.md#requirement-every-import-ends-in-ready-or-failed-req-dfa-002
	 */
	protected function run($argument): void {
		try {
			$this->tables->failStaleImports(now: $this->time->getTime());
		} catch (Throwable $e) {
			$this->logger->warning('[PublishedTableImportJob] stale imports could not be failed', ['exception' => $e->getMessage()]);
		}

		$tableId = '';
		$importedBy = null;
		if (is_array($argument) === true) {
			$tableId = trim((string)($argument['table'] ?? ''));
			$importedBy = ($argument['importedBy'] ?? null);
		}

		if (is_string($importedBy) === false || $importedBy === '') {
			$importedBy = null;
		}

		if ($tableId === '') {
			return;
		}

		$table = $this->tables->findTable(tableId: $tableId);
		if ($table === null) {
			$this->logger->info('[PublishedTableImportJob] the table is gone', ['table' => $tableId]);
			return;
		}

		try {
			$this->tables->importTable(table: $table, file: $this->tables->tableFile(table: $table), importedBy: $importedBy);
		} catch (Throwable $e) {
			$this->fail(table: $table, error: $e, importedBy: $importedBy);
		}

	}//end run()

	/**
	 * Store the table failed with the exception's message.
	 *
	 * @param array<string, mixed> $table      The table record.
	 * @param Throwable            $error      What went wrong.
	 * @param string|null          $importedBy Who started the import.
	 *
	 * @return void
	 */
	private function fail(array $table, Throwable $error, ?string $importedBy): void {
		try {
			$this->tables->failImport(table: $table, message: $error->getMessage(), importedBy: $importedBy);
		} catch (Throwable $e) {
			$this->logger->error(
				'[PublishedTableImportJob] the failed import could not be recorded',
				['table' => ($table['id'] ?? null), 'error' => $error->getMessage(), 'exception' => $e->getMessage()]
			);
		}

	}//end fail()
}//end class
