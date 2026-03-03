<?php
/**
 * Migration to create the ocat_pages table.
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
 * Migration step: Create ocat_pages table.
 */
class Version6Date20241129151236 extends SimpleMigrationStep
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
                options: [
                    'notnull' => true,
                    'length'  => 255,
                ]
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
                options: [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                name: 'slug',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 255,
                ]
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
            $table->addIndex(columns: ['uuid'], indexName: 'ocat_pages_uuid_index');
            $table->addIndex(columns: ['slug'], indexName: 'ocat_pages_slug_index');
        }//end if

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
