<?php
/**
 * Stub for OCA\OpenRegister\Db\RegisterMapper.
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
 * Minimal stub for RegisterMapper.
 * Method signatures match real RegisterMapper so named-parameter calls work.
 */
class RegisterMapper
{

    /**
     * Find a register by ID or slug.
     *
     * @param string|integer $id            The register ID or slug.
     * @param boolean        $_rbac         Apply RBAC.
     * @param boolean        $_multitenancy Apply multitenancy.
     *
     * @return Register|null
     */
    public function find(
        string|int $id,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): ?Register {
        return null;

    }//end find()


    /**
     * Find multiple registers by IDs.
     *
     * @param array<int> $ids           The IDs to find.
     * @param boolean    $_rbac         Apply RBAC.
     * @param boolean    $_multitenancy Apply multitenancy.
     *
     * @return array<Register>
     */
    public function findMultiple(array $ids, bool $_rbac=true, bool $_multitenancy=true): array
    {
        return [];

    }//end findMultiple()


    /**
     * Find all registers.
     *
     * @param integer|null        $limit              Max results.
     * @param integer|null        $offset             Start offset.
     * @param array<mixed>|null   $filters            Filters.
     * @param array<mixed>|null   $searchConditions   Search conditions.
     * @param array<mixed>|null   $searchParams       Search params.
     * @param boolean             $_rbac              Apply RBAC.
     * @param boolean             $_multitenancy      Apply multitenancy.
     *
     * @return array<Register>
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=[],
        ?array $searchConditions=[],
        ?array $searchParams=[],
        bool $_rbac=true,
        bool $_multitenancy=true
    ): array {
        return [];

    }//end findAll()


}//end class
