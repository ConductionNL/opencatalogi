<?php
/**
 * One sweep of the orphan directory listings: deletes rows and keeps the tally.
 *
 * @category Repair
 * @package  OCA\OpenCatalogi\Repair
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Repair;

use OCP\Migration\IOutput;

/**
 * Deletes orphan listing rows through OpenRegister and counts the outcome.
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-synchronize-all-directories-via-cron-every-hour-dir-004
 */
final class OrphanListingSweep
{

    /**
     * Rows deleted.
     *
     * @var integer
     */
    public int $deleted = 0;

    /**
     * Valid rows left in the swept tables.
     *
     * @var integer
     */
    public int $kept = 0;

    /**
     * Rows that could not be deleted.
     *
     * @var integer
     */
    public int $failed = 0;

    /**
     * Constructor.
     *
     * @param object  $objectService OpenRegister's ObjectService.
     * @param IOutput $output        Repair output channel.
     */
    public function __construct(
        private readonly object $objectService,
        public readonly IOutput $output
    ) {

    }//end __construct()

    /**
     * Delete one orphan row. A row without a uuid, or one whose delete
     * throws, counts as a failure.
     *
     * @param ListingTable         $table The table the row lives in.
     * @param array{_uuid: string} $row   The row, with its `_uuid`.
     *
     * @return void
     *
     * @spec openspec/specs/dashboard/spec.md#requirement-synchronize-all-directories-via-cron-every-hour-dir-004
     */
    public function delete(ListingTable $table, array $row): void
    {
        $uuid = $row['_uuid'];
        if (empty($uuid) === true) {
            $this->failed++;
            return;
        }

        try {
            $this->objectService->deleteObject(
                uuid: (string) $uuid,
                register: (string) $table->registerId,
                schema: (string) $table->schemaId,
                _rbac: false,
                _multitenancy: false
            );
            $this->deleted++;
        } catch (\Throwable $e) {
            $this->failed++;
            $this->output->warning(sprintf('Failed to delete listing %s: %s', (string) $uuid, $e->getMessage()));
        }

    }//end delete()

    /**
     * The summary line of the sweep.
     *
     * @return string
     *
     * @spec openspec/specs/dashboard/spec.md#requirement-synchronize-all-directories-via-cron-every-hour-dir-004
     */
    public function summary(): string
    {
        return sprintf(
            'Directory-listings cleanup: deleted %d orphan row(s), kept %d valid row(s), %d failure(s)',
            $this->deleted,
            $this->kept,
            $this->failed
        );

    }//end summary()
}//end class
