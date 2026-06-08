<?php
/**
 * Migration to create pages table.
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

namespace OCA\OpenCatalogi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to create the pages table.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version6Date20241129151236 extends SimpleMigrationStep
{
    /**
     * Pre-schema change hook.
     *
     * @param IOutput                  $output        The output handler.
     * @param Closure():ISchemaWrapper $schemaClosure The schema closure.
     * @param array                    $options       Migration options.
     *
     * @return void
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {

    }//end preSchemaChange()

    /**
     * Apply schema changes.
     *
     * @param IOutput                  $output        The output handler.
     * @param Closure():ISchemaWrapper $schemaClosure The schema closure.
     * @param array                    $options       Migration options.
     *
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // @var ISchemaWrapper $schema
        $schema = $schemaClosure();

        if ($schema->hasTable(tableName: 'ocat_pages') === false) {
            $table = $schema->createTable(tableName: 'ocat_pages');

            // Primary key and identifier columns.
            $table->addColumn(
                name: 'id',
                typeName: Types::BIGINT,
                options: [
                    'autoincrement' => true,
                    'notnull'       => true,
                    'length'        => 4,
                ]
            );
            $table->addColumn(
                name: 'uuid',
                typeName: Types::STRING,
                options: ['notnull' => true, 'length' => 255]
            );
            $table->addColumn(
                name: 'version',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 255,
                    'default' => '0.0.1',
                ]
            );

            // Meta columns.
            $table->addColumn(
                name: 'name',
                typeName: Types::STRING,
                options: ['notnull' => true, 'length' => 255]
            );
            $table->addColumn(
                name: 'slug',
                typeName: Types::STRING,
                options: ['notnull' => true, 'length' => 255]
            );
            $table->addColumn(
                name: 'contents',
                typeName: Types::JSON,
                options: ['notnull' => false]
            );
            $table->addColumn(
                name: 'updated',
                typeName: Types::DATETIME,
                options: [
                    'notnull' => true,
                    'default' => 'CURRENT_TIMESTAMP',
                ]
            );
            $table->addColumn(
                name: 'created',
                typeName: Types::DATETIME,
                options: [
                    'notnull' => true,
                    'default' => 'CURRENT_TIMESTAMP',
                ]
            );

            // Keys and indexes.
            $table->setPrimaryKey(columnNames: ['id']);
            $table->addIndex(['uuid'], 'ocat_pages_uuid_index');
            $table->addIndex(['slug'], 'ocat_pages_slug_index');
        }//end if

        return $schema;

    }//end changeSchema()

    /**
     * Post-schema change hook.
     *
     * @param IOutput                  $output        The output handler.
     * @param Closure():ISchemaWrapper $schemaClosure The schema closure.
     * @param array                    $options       Migration options.
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {

    }//end postSchemaChange()
}//end class
