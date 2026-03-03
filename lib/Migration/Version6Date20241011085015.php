<?php
/**
 * Initial migration to create all OpenCatalogi database tables.
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
 * Migration step: Create initial OpenCatalogi database tables.
 */
class Version6Date20241011085015 extends SimpleMigrationStep
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

        if ($schema->hasTable(tableName: 'ocat_attachments') === false) {
            $table = $schema->createTable(tableName: 'ocat_attachments');
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
            $table->addColumn(
                name: 'reference',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                name: 'title',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                name: 'summary',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                name: 'description',
                typeName: Types::STRING,
                options: [
                    'notnull' => false,
                    'length'  => 20000,
                ]
            );
            $table->addColumn(name: 'labels', typeName: Types::JSON, options: ['notnull' => false]);
            $table->addColumn(name: 'access_url', typeName: Types::STRING, options: ['notnull' => false]);
            $table->addColumn(name: 'download_url', typeName: Types::STRING, options: ['notnull' => false]);
            $table->addColumn(name: 'type', typeName: Types::STRING, options: ['notnull' => false]);
            $table->addColumn(name: 'extension', typeName: Types::STRING, options: ['notnull' => false]);
            $table->addColumn(
                name: 'size',
                typeName: Types::INTEGER,
                options: [
                    'notnull' => true,
                    'default' => 0,
                ]
            );
            $table->addColumn(name: 'version_of', typeName: Types::STRING, options: ['notnull' => false]);
            $table->addColumn(name: 'hash', typeName: Types::STRING, options: ['notnull' => false]);
            $table->addColumn(name: 'anonymization', typeName: Types::JSON, options: ['notnull' => false]);
            $table->addColumn(name: 'language', typeName: Types::JSON, options: ['notnull' => false]);
            $table->addColumn(name: 'published', typeName: Types::DATETIME, options: ['notNull' => false]);
            $table->addColumn(name: 'modified', typeName: Types::DATETIME, options: ['notNull' => false]);
            $table->addColumn(name: 'license', typeName: Types::STRING);
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

            $table->setPrimaryKey(columnNames: ['id']);
            $table->addIndex(columns: ['uuid'], indexName: 'ocat_attachments_uuid_index');
        }//end if

        if ($schema->hasTable(tableName: 'ocat_catalogi') === false) {
            $table = $schema->createTable(tableName: 'ocat_catalogi');
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
            $table->addColumn(
                name: 'title',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                name: 'summary',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                name: 'description',
                typeName: Types::STRING,
                options: [
                    'notnull' => false,
                    'length'  => 20000,
                ]
            );
            $table->addColumn(
                name: 'image',
                typeName: Types::STRING,
                options: [
                    'length'  => 255,
                    'notnull' => false,
                ]
            );
            $table->addColumn(name: 'search', typeName: Types::STRING, options: ['notnull' => false]);
            $table->addColumn(
                name: 'listed',
                typeName: Types::BOOLEAN,
                options: [
                    'notNull' => false,
                    'default' => false,
                ]
            );
            $table->addColumn(
                name: 'publication_types',
                typeName: Types::JSON,
                options: [
                    'notNull' => false,
                    'default' => '{}',
                ]
            );
            $table->addColumn(
                name: 'organization',
                typeName: Types::STRING,
                options: [
                    'notNull' => false,
                    'default' => null,
                ]
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

            $table->setPrimaryKey(columnNames: ['id']);
            $table->addIndex(columns: ['uuid'], indexName: 'ocat_catalogi_uuid_index');
            $table->addIndex(columns: ['organization'], indexName: 'ocat_catalogi_organization_index');
        }//end if

        if ($schema->hasTable(tableName: 'ocat_listings') === false) {
            $table = $schema->createTable(tableName: 'ocat_listings');
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
            $table->addColumn(
                name: 'title',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                name: 'summary',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                name: 'description',
                typeName: Types::STRING,
                options: [
                    'notnull' => false,
                    'length'  => 20000,
                ]
            );
            $table->addColumn(name: 'search', typeName: Types::STRING, options: ['notnull' => false]);
            $table->addColumn(name: 'directory', typeName: Types::STRING, options: ['notnull' => false]);
            $table->addColumn(
                name: 'organization',
                typeName: Types::JSON,
                options: [
                    'notnull' => false,
                    'default' => '{}',
                ]
            );
            $table->addColumn(
                name: 'publication_types',
                typeName: Types::JSON,
                options: [
                    'notnull' => false,
                    'default' => '{}',
                ]
            );
            $table->addColumn(name: 'status', typeName: Types::STRING, options: ['notnull' => false]);
            $table->addColumn(name: 'last_sync', typeName: Types::DATETIME, options: ['notnull' => false]);
            $table->addColumn(name: 'default', typeName: Types::BOOLEAN, options: ['notnull' => false]);
            $table->addColumn(name: 'available', typeName: Types::BOOLEAN, options: ['notnull' => false]);
            $table->addColumn(name: 'catalog', typeName: Types::STRING);
            $table->addColumn(name: 'hash', typeName: Types::STRING);
            $table->addColumn(
                name: 'status_code',
                typeName: Types::INTEGER,
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

            $table->setPrimaryKey(columnNames: ['id']);
            $table->addIndex(columns: ['uuid'], indexName: 'ocat_listings_uuid_index');
            $table->addIndex(columns: ['catalog'], indexName: 'ocat_listings_catalog_index');
        }//end if

        if ($schema->hasTable(tableName: 'ocat_organizations') === false) {
            $table = $schema->createTable(tableName: 'ocat_organizations');
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
            $table->addColumn(
                name: 'title',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                name: 'summary',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                name: 'description',
                typeName: Types::STRING,
                options: [
                    'notnull' => false,
                    'length'  => 20000,
                ]
            );
            $table->addColumn(name: 'image', typeName: Types::STRING, options: ['notnull' => false]);
            $table->addColumn(name: 'oin', typeName: Types::STRING, options: ['notnull' => false]);
            $table->addColumn(name: 'tooi', typeName: Types::STRING, options: ['notnull' => false]);
            $table->addColumn(name: 'rsin', typeName: Types::STRING, options: ['notnull' => false]);
            $table->addColumn(name: 'pki', typeName: Types::STRING, options: ['notnull' => false]);
            $table->addColumn(name: 'upd', typeName: Types::DATETIME, options: ['notNull' => false]);
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

            $table->setPrimaryKey(columnNames: ['id']);
            $table->addIndex(columns: ['uuid'], indexName: 'ocat_organizations_uuid_index');
        }//end if

        if ($schema->hasTable(tableName: 'ocat_publications') === false) {
            $table = $schema->createTable(tableName: 'ocat_publications');
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
            $table->addColumn(
                name: 'reference',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                name: 'title',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                name: 'summary',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                name: 'description',
                typeName: Types::STRING,
                options: [
                    'notnull' => false,
                    'length'  => 20000,
                ]
            );
            $table->addColumn(
                name: 'image',
                typeName: Types::STRING,
                options: [
                    'length'  => 255,
                    'notnull' => false,
                ]
            );
            $table->addColumn(
                name: 'category',
                typeName: Types::STRING,
                options: [
                    'length'  => 255,
                    'notnull' => true,
                ]
            );
            $table->addColumn(name: 'portal', typeName: Types::STRING, options: ['notnull' => false]);
            $table->addColumn(name: 'featured', typeName: Types::BOOLEAN, options: ['notnull' => false]);
            $table->addColumn(
                name: 'publication_type',
                typeName: Types::STRING,
                options: ['notnull' => false]
            );
            $table->addColumn(
                name: 'attachments',
                typeName: Types::JSON,
                options: [
                    'notnull' => false,
                    'default' => '{}',
                ]
            );
            $table->addColumn(
                name: 'attachment_count',
                typeName: Types::INTEGER,
                options: [
                    'notnull' => false,
                    'default' => 0,
                ]
            );
            $table->addColumn(name: 'themes', typeName: Types::JSON, options: ['notnull' => false]);
            $table->addColumn(
                name: 'data',
                typeName: Types::JSON,
                options: [
                    'notnull' => false,
                    'default' => '{}',
                ]
            );
            $table->addColumn(name: 'anonymization', typeName: Types::JSON, options: ['notnull' => false]);
            $table->addColumn(
                name: 'language_object',
                typeName: Types::JSON,
                options: ['notnull' => false]
            );
            $table->addColumn(name: 'license', typeName: Types::STRING, options: ['notnull' => false]);
            $table->addColumn(name: 'archive', typeName: Types::JSON, options: ['notnull' => false]);
            $table->addColumn(name: 'geo', typeName: Types::JSON, options: ['notnull' => false]);
            $table->addColumn(name: 'source', typeName: Types::STRING, options: ['notnull' => false]);
            $table->addColumn(name: 'validation', typeName: Types::JSON, options: ['notnull' => false]);
            $table->addColumn(
                name: 'catalog',
                typeName: Types::STRING,
                options: ['notnull' => true]
            );
            $table->addColumn(
                name: 'organization',
                typeName: Types::STRING,
                options: [
                    'notnull' => false,
                    'default' => '{}',
                ]
            );
            $table->addColumn(
                name: 'status',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'default' => 'Concept',
                ]
            );
            $table->addColumn(name: 'published', typeName: Types::DATETIME, options: ['notnull' => false]);
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

            $table->setPrimaryKey(columnNames: ['id']);
            $table->addIndex(columns: ['uuid'], indexName: 'ocat_publications_uuid_index');
            $table->addIndex(columns: ['publication_type'], indexName: 'ocat_publications_type_index');
            $table->addIndex(columns: ['organization'], indexName: 'ocat_publications_index');
        }//end if

        if ($schema->hasTable(tableName: 'ocat_publication_types') === false) {
            $table = $schema->createTable(tableName: 'ocat_publication_types');
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
            $table->addColumn(
                name: 'title',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                name: 'description',
                typeName: Types::STRING,
                options: [
                    'notnull' => false,
                    'length'  => 20000,
                ]
            );
            $table->addColumn(name: 'required', typeName: Types::JSON, options: ['notnull' => false]);
            $table->addColumn(name: 'properties', typeName: Types::JSON, options: ['notnull' => false]);
            $table->addColumn(
                name: 'source',
                typeName: Types::STRING,
                options: [
                    'notNull' => false,
                    'default' => null,
                ]
            );
            $table->addColumn(name: 'summary', typeName: Types::STRING, options: ['notnull' => false]);
            $table->addColumn(name: 'archive', typeName: Types::JSON, options: ['notnull' => false]);
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

            $table->setPrimaryKey(columnNames: ['id']);
            $table->addIndex(columns: ['uuid'], indexName: 'ocat_publication_uuid_index');
        }//end if

        if ($schema->hasTable(tableName: 'ocat_themes') === false) {
            $table = $schema->createTable(tableName: 'ocat_themes');
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
            $table->addColumn(
                name: 'title',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                name: 'summary',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                name: 'description',
                typeName: Types::STRING,
                options: [
                    'notnull' => false,
                    'length'  => 20000,
                ]
            );
            $table->addColumn(name: 'image', typeName: Types::STRING, options: ['notnull' => false]);
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

            $table->setPrimaryKey(columnNames: ['id']);
            $table->addIndex(columns: ['uuid'], indexName: 'ocat_themes_uuid_index');
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
