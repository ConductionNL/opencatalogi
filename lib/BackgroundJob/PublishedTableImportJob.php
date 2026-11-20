<?php

/**
 * Imports a published table in the background.
 *
 * @category BackgroundJob
 * @package  OCA\OpenCatalogi\BackgroundJob
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

namespace OCA\OpenCatalogi\BackgroundJob;

use OCA\OpenCatalogi\Service\Table\TableImportFollowUp;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the import of one table: a file over the large-file limit, a retry, or
 * a replaced file (design D3).
 *
 * Each run first ends the tables that have been `importing` for over an hour
 * as `failed`, then imports its own table. Nothing it does leaves a table
 * `importing`: a Throwable ends the table `failed` with its message.
 *
 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.3
 */
class PublishedTableImportJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory        $time     The clock.
	 * @param TableImportFollowUp $followUp Runs the import and ends stale ones.
	 * @param LoggerInterface     $logger   Records a sweep that could not run.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly TableImportFollowUp $followUp,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Import the table the argument names.
	 *
	 * @param mixed $argument `{table, importedBy}`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.3
	 */
	protected function run($argument): void {
		$tableId = '';
		$importedBy = '';
		if (is_array($argument) === true) {
			$tableId = (string)($argument['table'] ?? '');
			$importedBy = (string)($argument['importedBy'] ?? '');
		}

		try {
			$this->followUp->failStaleImports(except: $tableId);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[PublishedTableImportJob] stale imports could not be checked',
				context: ['exception' => $e->getMessage()]
			);
		}

		if ($tableId === '') {
			return;
		}

		try {
			$this->followUp->run(tableId: $tableId, importedBy: $importedBy);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[PublishedTableImportJob] the table could not be imported',
				context: ['table' => $tableId, 'exception' => $e->getMessage()]
			);
		}
	}//end run()
}//end class
