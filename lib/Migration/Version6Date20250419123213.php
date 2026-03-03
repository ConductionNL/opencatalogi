<?php
/**
 * Migration to remove old tables that are no longer used.
 *
 * @category Migration
 * @package  OCA\OpenCatalogi\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenCatalogi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration step: Remove old database tables.
 */
class Version6Date20250419123213 extends SimpleMigrationStep
{


    /**
     * Pre-schema change hook.
     *
     * @param IOutput $output        Migration output handler.
     * @param Closure $schemaClosure Schema wrapper closure.
     * @param array   $options       Migration options.
     *
     * @return void
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {

    }//end preSchemaChange()


    /**
     * Apply schema changes.
     *
     * @param IOutput $output        Migration output handler.
     * @param Closure $schemaClosure Schema wrapper closure.
     * @param array   $options       Migration options.
     *
     * @return null|ISchemaWrapper The modified schema or null.
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();

        // Remove old tables that are no longer used.
        $tablesToRemove = [
            'ocat_attachments',
            'ocat_catalogi',
            'ocat_listings',
            'ocat_organizations',
            'ocat_pages',
            'ocat_publication_types',
            'ocat_publications',
            'ocat_themes',
        ];

        foreach ($tablesToRemove as $tableName) {
            if ($schema->hasTable($tableName) === true) {
                $schema->dropTable($tableName);
            }
        }

        return $schema;

    }//end changeSchema()


    /**
     * Post-schema change hook.
     *
     * @param IOutput $output        Migration output handler.
     * @param Closure $schemaClosure Schema wrapper closure.
     * @param array   $options       Migration options.
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {

    }//end postSchemaChange()


}//end class
