<?php
/**
 * Stub for OCA\OpenRegister\Db\SchemaMapper.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Minimal stub for SchemaMapper.
 * Method signatures match real SchemaMapper so named-parameter calls work.
 */
class SchemaMapper
{

    /**
     * Find a schema by ID or slug.
     *
     * @param string|integer      $id            The schema ID or slug.
     * @param array<mixed>|null   $_extend       Extension config.
     * @param boolean             $_rbac         Apply RBAC.
     * @param boolean             $_multitenancy Apply multitenancy.
     *
     * @return Schema|null
     */
    public function find(
        string|int $id,
        ?array $_extend=[],
        bool $_rbac=true,
        bool $_multitenancy=true
    ): ?Schema {
        return null;

    }//end find()


    /**
     * Find multiple schemas by IDs.
     *
     * @param array<int> $ids           The IDs to find.
     * @param boolean    $_rbac         Apply RBAC.
     * @param boolean    $_multitenancy Apply multitenancy.
     *
     * @return array<Schema>
     */
    public function findMultiple(array $ids, bool $_rbac=true, bool $_multitenancy=true): array
    {
        return [];

    }//end findMultiple()


    /**
     * Find all schemas.
     *
     * @return array<Schema>
     */
    public function findAll(): array
    {
        return [];

    }//end findAll()


}//end class
