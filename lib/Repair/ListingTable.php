<?php
/**
 * One per-register listing table of OpenRegister.
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

/**
 * The register and schema whose objects live in one table.
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-synchronize-all-directories-via-cron-every-hour-dir-004
 */
final class ListingTable
{
    /**
     * Constructor.
     *
     * @param int $registerId The register.
     * @param int $schemaId   The listing schema.
     */
    public function __construct(
        public readonly int $registerId,
        public readonly int $schemaId
    ) {

    }//end __construct()

    /**
     * The table name, unprefixed.
     *
     * @return string
     *
     * @spec openspec/specs/dashboard/spec.md#requirement-synchronize-all-directories-via-cron-every-hour-dir-004
     */
    public function name(): string
    {
        return sprintf('openregister_table_%d_%d', $this->registerId, $this->schemaId);

    }//end name()
}//end class
