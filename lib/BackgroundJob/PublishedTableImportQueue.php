<?php

/**
 * Queues the background import of a published table.
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

use OCA\OpenCatalogi\Contract\TableImportQueueInterface;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Adds a PublishedTableImportJob for one table.
 *
 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.3
 */
class PublishedTableImportQueue implements TableImportQueueInterface {

	/**
	 * Constructor.
	 *
	 * @param IJobList        $jobList The background job list.
	 * @param LoggerInterface $logger  Records a job that could not be queued.
	 */
	public function __construct(
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Queue the import of one stored table. A failure to queue is logged, never thrown.
	 *
	 * @param string $tableId    The table.
	 * @param string $importedBy Who started it.
	 *
	 * @return bool Whether the job was queued.
	 *
	 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.3
	 */
	public function queue(string $tableId, string $importedBy): bool {
		if ($tableId === '') {
			return false;
		}

		try {
			$this->jobList->add(PublishedTableImportJob::class, ['table' => $tableId, 'importedBy' => $importedBy]);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[PublishedTableImportQueue] the import job could not be queued',
				context: ['table' => $tableId, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end queue()
}//end class
