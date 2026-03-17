<?php
/**
 * Migration to clean up old tables.
 *
 * @category Migration
 * @package  OCA\OpenCatalogi\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Class Version6Date20250419123213
 *
 * Migration to:
 * 1. Add uri columns to all tables
 * 2. Remove old tables that are no longer used
 * 3. Install and enable OpenRegister
 */
class Version6Date20250419123213 extends SimpleMigrationStep
{
    /**
     * Pre-schema change handler.
     *
     * @param IOutput                   $output        The output handler.
     * @param Closure(): ISchemaWrapper $schemaClosure The schema closure.
     * @param array                     $options       Migration options.
     *
     * @return void
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {

    }//end preSchemaChange()

    /**
     * Apply schema changes.
     *
     * @param IOutput                   $output        The output handler.
     * @param Closure(): ISchemaWrapper $schemaClosure The schema closure.
     * @param array                     $options       Migration options.
     *
     * @return null|ISchemaWrapper The updated schema or null.
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // Get the schema from the closure.
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
     * Post-schema change handler.
     *
     * @param IOutput                   $output        The output handler.
     * @param Closure(): ISchemaWrapper $schemaClosure The schema closure.
     * @param array                     $options       Migration options.
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {

    }//end postSchemaChange()
}//end class
