<?php

/**
 * Contract of the queue that imports a published table in the background.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
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

namespace OCA\OpenCatalogi\Contract;

/**
 * Queues the import of a published table, so a large file never runs in the request.
 *
 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.3
 */
interface TableImportQueueInterface {
	/**
	 * Queue the import of one stored table.
	 *
	 * @param string $tableId    The table.
	 * @param string $importedBy Who started it; the failure notification goes to this user.
	 *
	 * @return bool Whether the job was queued.
	 *
	 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.3
	 */
	public function queue(string $tableId, string $importedBy): bool;
}//end interface
