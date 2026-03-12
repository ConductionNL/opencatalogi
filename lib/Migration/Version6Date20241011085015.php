<?php
/**
 * Initial migration to create all OpenCatalogi tables.
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
 * FIXME Auto-generated migration step: Please modify to your needs!
 */
class Version6Date20241011085015 extends SimpleMigrationStep
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
     * Apply schema changes to create all tables.
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

        // Common options for reuse.
        $idOpts       = ['autoincrement' => true, 'notnull' => true, 'length' => 4];
        $uuidOpts     = ['notnull' => true, 'length' => 255];
        $versionOpts  = ['notnull' => true, 'length' => 255, 'default' => '0.0.1'];
        $titleOpts    = ['notnull' => true, 'length' => 255];
        $summaryOpts  = ['notnull' => true, 'length' => 255];
        $descOpts     = ['notnull' => false, 'length' => 20000];
        $tsOpts       = ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP'];
        $nullableOpts = ['notnull' => false];

        if ($schema->hasTable(tableName: 'ocat_attachments') === false) {
            $table = $schema->createTable(tableName: 'ocat_attachments');
            $table->addColumn(name: 'id', typeName: Types::BIGINT, options: $idOpts);
            $table->addColumn(name: 'uuid', typeName: Types::STRING, options: $uuidOpts);
            $table->addColumn(name: 'version', typeName: Types::STRING, options: $versionOpts);
            $table->addColumn(name: 'reference', typeName: TYPES::STRING, options: $titleOpts);
            $table->addColumn(name: 'title', typeName: TYPES::STRING, options: $titleOpts);
            $table->addColumn(name: 'summary', typeName: TYPES::STRING, options: $summaryOpts);
            $table->addColumn(name: 'description', typeName: TYPES::STRING, options: $descOpts);
            $table->addColumn(name: 'labels', typeName: TYPES::JSON, options: $nullableOpts);
            $table->addColumn(name: 'access_url', typeName: TYPES::STRING, options: $nullableOpts);
            $table->addColumn(name: 'download_url', typeName: TYPES::STRING, options: $nullableOpts);
            $table->addColumn(name: 'type', typeName: TYPES::STRING, options: $nullableOpts);
            $table->addColumn(name: 'extension', typeName: TYPES::STRING, options: $nullableOpts);
            $table->addColumn(name: 'size', typeName: TYPES::INTEGER, options: ['notnull' => true, 'default' => 0]);
            $table->addColumn(name: 'version_of', typeName: TYPES::STRING, options: $nullableOpts);
            $table->addColumn(name: 'hash', typeName: TYPES::STRING, options: $nullableOpts);
            $table->addColumn(name: 'anonymization', typeName: TYPES::JSON, options: $nullableOpts);
            $table->addColumn(name: 'language', typeName: TYPES::JSON, options: $nullableOpts);
            $table->addColumn(name: 'published', typeName: TYPES::DATETIME, options: ['notNull' => false]);
            $table->addColumn(name: 'modified', typeName: TYPES::DATETIME, options: ['notNull' => false]);
            $table->addColumn(name: 'license', typeName: TYPES::STRING);
            $table->addColumn(name: 'updated', typeName: Types::DATETIME, options: $tsOpts);
            $table->addColumn(name: 'created', typeName: Types::DATETIME, options: $tsOpts);

            $table->setPrimaryKey(columnNames: ['id']);
            $table->addIndex(['uuid'], 'ocat_attachments_uuid_index');
        }//end if

        if ($schema->hasTable(tableName: 'ocat_catalogi') === false) {
            $table = $schema->createTable(tableName: 'ocat_catalogi');
            $table->addColumn(name: 'id', typeName: Types::BIGINT, options: $idOpts);
            $table->addColumn(name: 'uuid', typeName: Types::STRING, options: $uuidOpts);
            $table->addColumn(name: 'version', typeName: Types::STRING, options: $versionOpts);
            $table->addColumn(name: 'title', typeName: TYPES::STRING, options: $titleOpts);
            $table->addColumn(name: 'summary', typeName: TYPES::STRING, options: $summaryOpts);
            $table->addColumn(name: 'description', typeName: TYPES::STRING, options: $descOpts);
            $table->addColumn(
                name: 'image',
                typeName: TYPES::STRING,
                options: ['length' => 255, 'notnull' => false]
            );
            $table->addColumn(name: 'search', typeName: TYPES::STRING, options: $nullableOpts);
            $table->addColumn(
                name: 'listed',
                typeName: Types::BOOLEAN,
                options: ['notNull' => false, 'default' => false]
            );
            $table->addColumn(
                name: 'publication_types',
                typeName: Types::JSON,
                options: ['notNull' => false, 'default' => '{}']
            );
            $table->addColumn(
                name: 'organization',
                typeName: Types::STRING,
                options: ['notNull' => false, 'default' => null]
            );
            $table->addColumn(name: 'updated', typeName: Types::DATETIME, options: $tsOpts);
            $table->addColumn(name: 'created', typeName: Types::DATETIME, options: $tsOpts);

            $table->setPrimaryKey(columnNames: ['id']);
            $table->addIndex(['uuid'], 'ocat_catalogi_uuid_index');
            $table->addIndex(['organization'], 'ocat_catalogi_organization_index');
        }//end if

        if ($schema->hasTable(tableName: 'ocat_listings') === false) {
            $table = $schema->createTable(tableName: 'ocat_listings');
            $table->addColumn(name: 'id', typeName: Types::BIGINT, options: $idOpts);
            $table->addColumn(name: 'uuid', typeName: Types::STRING, options: $uuidOpts);
            $table->addColumn(name: 'version', typeName: Types::STRING, options: $versionOpts);
            $table->addColumn(name: 'title', typeName: TYPES::STRING, options: $titleOpts);
            $table->addColumn(name: 'summary', typeName: TYPES::STRING, options: $summaryOpts);
            $table->addColumn(name: 'description', typeName: TYPES::STRING, options: $descOpts);
            $table->addColumn(name: 'search', typeName: TYPES::STRING, options: $nullableOpts);
            $table->addColumn(name: 'directory', typeName: TYPES::STRING, options: $nullableOpts);
            $table->addColumn(
                name: 'organization',
                typeName: Types::JSON,
                options: ['notnull' => false, 'default' => '{}']
            );
            $table->addColumn(
                name: 'publication_types',
                typeName: Types::JSON,
                options: ['notnull' => false, 'default' => '{}']
            );
            $table->addColumn(name: 'status', typeName: TYPES::STRING, options: $nullableOpts);
            $table->addColumn(name: 'last_sync', typeName: TYPES::DATETIME, options: $nullableOpts);
            $table->addColumn(name: 'default', typeName: TYPES::BOOLEAN, options: $nullableOpts);
            $table->addColumn(name: 'available', typeName: TYPES::BOOLEAN, options: $nullableOpts);
            $table->addColumn(name: 'catalog', typeName: Types::STRING);
            $table->addColumn(name: 'hash', typeName: Types::STRING);
            $table->addColumn(name: 'status_code', typeName: Types::INTEGER, options: $nullableOpts);
            $table->addColumn(name: 'updated', typeName: Types::DATETIME, options: $tsOpts);
            $table->addColumn(name: 'created', typeName: Types::DATETIME, options: $tsOpts);

            $table->setPrimaryKey(columnNames: ['id']);
            $table->addIndex(['uuid'], 'ocat_listings_uuid_index');
            $table->addIndex(['catalog'], 'ocat_listings_catalog_index');
        }//end if

        if ($schema->hasTable(tableName: 'ocat_organizations') === false) {
            $table = $schema->createTable(tableName: 'ocat_organizations');
            $table->addColumn(name: 'id', typeName: Types::BIGINT, options: $idOpts);
            $table->addColumn(name: 'uuid', typeName: Types::STRING, options: $uuidOpts);
            $table->addColumn(name: 'version', typeName: Types::STRING, options: $versionOpts);
            $table->addColumn(name: 'title', typeName: TYPES::STRING, options: $titleOpts);
            $table->addColumn(name: 'summary', typeName: TYPES::STRING, options: $summaryOpts);
            $table->addColumn(name: 'description', typeName: TYPES::STRING, options: $descOpts);
            $table->addColumn(name: 'image', typeName: TYPES::STRING, options: $nullableOpts);
            $table->addColumn(name: 'oin', typeName: TYPES::STRING, options: $nullableOpts);
            $table->addColumn(name: 'tooi', typeName: TYPES::STRING, options: $nullableOpts);
            $table->addColumn(name: 'rsin', typeName: TYPES::STRING, options: $nullableOpts);
            $table->addColumn(name: 'pki', typeName: TYPES::STRING, options: $nullableOpts);
            $table->addColumn(name: 'upd', typeName: TYPES::DATETIME, options: ['notNull' => false]);
            $table->addColumn(name: 'updated', typeName: Types::DATETIME, options: $tsOpts);
            $table->addColumn(name: 'created', typeName: Types::DATETIME, options: $tsOpts);

            $table->setPrimaryKey(columnNames: ['id']);
            $table->addIndex(['uuid'], 'ocat_organizations_uuid_index');
        }

        if ($schema->hasTable(tableName: 'ocat_publications') === false) {
            $table = $schema->createTable(tableName: 'ocat_publications');
            $table->addColumn(name: 'id', typeName: Types::BIGINT, options: $idOpts);
            $table->addColumn(name: 'uuid', typeName: Types::STRING, options: $uuidOpts);
            $table->addColumn(name: 'version', typeName: Types::STRING, options: $versionOpts);
            $table->addColumn(name: 'reference', typeName: TYPES::STRING, options: $titleOpts);
            $table->addColumn(name: 'title', typeName: TYPES::STRING, options: $titleOpts);
            $table->addColumn(name: 'summary', typeName: TYPES::STRING, options: $summaryOpts);
            $table->addColumn(name: 'description', typeName: TYPES::STRING, options: $descOpts);
            $table->addColumn(
                name: 'image',
                typeName: TYPES::STRING,
                options: ['length' => 255, 'notnull' => false]
            );
            $table->addColumn(
                name: 'category',
                typeName: TYPES::STRING,
                options: ['length' => 255, 'notnull' => true]
            );
            $table->addColumn(name: 'portal', typeName: TYPES::STRING, options: $nullableOpts);
            $table->addColumn(name: 'featured', typeName: TYPES::BOOLEAN, options: $nullableOpts);
            $table->addColumn(name: 'publication_type', typeName: TYPES::STRING, options: $nullableOpts);
            // <- Should be changed in Stoplight.
            $table->addColumn(
                name: 'attachments',
                typeName: TYPES::JSON,
                options: ['notnull' => false, 'default' => '{}']
            );
            $table->addColumn(
                name: 'attachment_count',
                typeName: TYPES::INTEGER,
                options: ['notnull' => false, 'default' => 0]
            );
            $table->addColumn(name: 'themes', typeName: TYPES::JSON, options: $nullableOpts);
            $table->addColumn(
                name: 'data',
                typeName: TYPES::JSON,
                options: ['notnull' => false, 'default' => '{}']
            );
            $table->addColumn(name: 'anonymization', typeName: TYPES::JSON, options: $nullableOpts);
            $table->addColumn(name: 'language_object', typeName: TYPES::JSON, options: $nullableOpts);
            $table->addColumn(name: 'license', typeName: TYPES::STRING, options: $nullableOpts);
            $table->addColumn(name: 'archive', typeName: TYPES::JSON, options: $nullableOpts);
            $table->addColumn(name: 'geo', typeName: TYPES::JSON, options: $nullableOpts);
            $table->addColumn(name: 'source', typeName: TYPES::STRING, options: $nullableOpts);
            $table->addColumn(name: 'validation', typeName: TYPES::JSON, options: $nullableOpts);
            $table->addColumn(name: 'catalog', typeName: TYPES::STRING, options: ['notnull' => true]);
            $table->addColumn(
                name: 'organization',
                typeName: TYPES::STRING,
                options: ['notnull' => false, 'default' => '{}']
            );
            $table->addColumn(
                name: 'status',
                typeName: TYPES::STRING,
                options: ['notnull' => true, 'default' => 'Concept']
            );
            $table->addColumn(name: 'published', typeName: Types::DATETIME, options: $nullableOpts);
            $table->addColumn(name: 'updated', typeName: Types::DATETIME, options: $tsOpts);
            $table->addColumn(name: 'created', typeName: Types::DATETIME, options: $tsOpts);

            $table->setPrimaryKey(columnNames: ['id']);
            $table->addIndex(['uuid'], 'ocat_publications_uuid_index');
            $table->addIndex(['publication_type'], 'ocat_publications_type_index');
            $table->addIndex(['organization'], 'ocat_publications_index');
        }//end if

        if ($schema->hasTable(tableName: 'ocat_publication_types') === false) {
            $table = $schema->createTable(tableName: 'ocat_publication_types');
            $table->addColumn(name: 'id', typeName: Types::BIGINT, options: $idOpts);
            $table->addColumn(name: 'uuid', typeName: Types::STRING, options: $uuidOpts);
            $table->addColumn(name: 'version', typeName: Types::STRING, options: $versionOpts);
            $table->addColumn(name: 'title', typeName: TYPES::STRING, options: $titleOpts);
            $table->addColumn(name: 'description', typeName: TYPES::STRING, options: $descOpts);
            $table->addColumn(name: 'required', typeName: TYPES::JSON, options: $nullableOpts);
            $table->addColumn(name: 'properties', typeName: TYPES::JSON, options: $nullableOpts);
            $table->addColumn(
                name: 'source',
                typeName: Types::STRING,
                options: ['notNull' => false, 'default' => null]
            );
            $table->addColumn(name: 'summary', typeName: Types::STRING, options: $nullableOpts);
            $table->addColumn(name: 'archive', typeName: Types::JSON, options: $nullableOpts);
            $table->addColumn(name: 'updated', typeName: Types::DATETIME, options: $tsOpts);
            $table->addColumn(name: 'created', typeName: Types::DATETIME, options: $tsOpts);

            $table->setPrimaryKey(columnNames: ['id']);
            $table->addIndex(['uuid'], 'ocat_publication_uuid_index');
        }//end if

        if ($schema->hasTable(tableName: 'ocat_themes') === false) {
            $table = $schema->createTable(tableName: 'ocat_themes');
            $table->addColumn(name: 'id', typeName: Types::BIGINT, options: $idOpts);
            $table->addColumn(name: 'uuid', typeName: Types::STRING, options: $uuidOpts);
            $table->addColumn(name: 'version', typeName: Types::STRING, options: $versionOpts);
            $table->addColumn(name: 'title', typeName: TYPES::STRING, options: $titleOpts);
            $table->addColumn(name: 'summary', typeName: TYPES::STRING, options: $summaryOpts);
            $table->addColumn(name: 'description', typeName: TYPES::STRING, options: $descOpts);
            $table->addColumn(name: 'image', typeName: TYPES::STRING, options: $nullableOpts);
            $table->addColumn(name: 'updated', typeName: Types::DATETIME, options: $tsOpts);
            $table->addColumn(name: 'created', typeName: Types::DATETIME, options: $tsOpts);

            $table->setPrimaryKey(columnNames: ['id']);
            $table->addIndex(['uuid'], 'ocat_themes_uuid_index');
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
