<?php
/**
 * Migration to create the pages table.
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
 * Migration step to create the ocat_pages table.
 */
class Version6Date20241129151236 extends SimpleMigrationStep
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
     * Apply schema changes to create the pages table.
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

        if ($schema->hasTable(tableName: 'ocat_pages') === false) {
            $table = $schema->createTable(tableName: 'ocat_pages');

            // Primary key and identifier columns.
            // phpcs:ignore Generic.Files.LineLength.TooLong
            $table->addColumn(name: 'id', typeName: Types::BIGINT, options: ['autoincrement' => true, 'notnull' => true, 'length' => 4]);
            // phpcs:ignore Generic.Files.LineLength.TooLong
            $table->addColumn(name: 'uuid', typeName: Types::STRING, options: ['notnull' => true, 'length' => 255]);
            // phpcs:ignore Generic.Files.LineLength.TooLong
            $table->addColumn(name: 'version', typeName: Types::STRING, options: ['notnull' => true, 'length' => 255, 'default' => '0.0.1']);

            // Meta columns.
            // phpcs:ignore Generic.Files.LineLength.TooLong
            $table->addColumn(name: 'name', typeName: Types::STRING, options: ['notnull' => true, 'length' => 255]);
            // phpcs:ignore Generic.Files.LineLength.TooLong
            $table->addColumn(name: 'slug', typeName: Types::STRING, options: ['notnull' => true, 'length' => 255]);
            $table->addColumn(name: 'contents', typeName: Types::JSON, options: ['notnull' => false]);
            // phpcs:ignore Generic.Files.LineLength.TooLong
            $table->addColumn(name: 'updated', typeName: Types::DATETIME, options: ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
            // phpcs:ignore Generic.Files.LineLength.TooLong
            $table->addColumn(name: 'created', typeName: Types::DATETIME, options: ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);

            // Keys and indexes.
            $table->setPrimaryKey(columnNames: ['id']);
            $table->addIndex(['uuid'], 'ocat_pages_uuid_index');
            $table->addIndex(['slug'], 'ocat_pages_slug_index');
        }//end if

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
