<?php
/**
 * Service for publication query building and response shaping.
 *
 * Encapsulates the query-building, object-location, and response-shaping logic
 * extracted from PublicationsController to keep the controller thin.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Service;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;

/**
 * Query-building and response-shaping helpers for publications.
 *
 * All methods in this service are pure-logic helpers with no side-effects on
 * routing, authentication, or HTTP response codes. They exist solely to reduce
 * the size of PublicationsController.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class PublicationQueryService
{

    /**
     * Order fields that exist in every magic-mapper table.
     *
     * Used when filtering _order for multi-register searches.
     *
     * @var array<string>
     */
    private const UNIVERSAL_ORDER_FIELDS = [
        '_uuid',
        '_created',
        '_updated',
        '_name',
        '_description',
        '_summary',
        '_relevance',
    ];

    /**
     * Per-instance existence cache for magic-mapper tables.
     *
     * Avoids repeated information_schema lookups when a request touches the same
     * (register × schema) pair multiple times. Cleared whenever the service is
     * reconstructed (no long-lived state across requests).
     *
     * @var array<string, bool>
     */
    private array $magicTableCache = [];

    /**
     * Constructor.
     *
     * @param IDBConnection      $db        Database connection
     * @param ContainerInterface $container DI container
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly ContainerInterface $container,
    ) {

    }//end __construct()

    /**
     * Find the register and schema IDs for an object UUID within a constrained scope.
     *
     * OpenRegister stores objects in per-register-per-schema "magic tables" named
     * oc_openregister_table_{register}_{schema}. This helper locates which table a
     * UUID lives in, but it is ALWAYS scoped to the caller-supplied register/schema
     * lists. The legacy platform-wide UNION-ALL across every magic table (with a
     * per-request information_schema reflection) is gone (#734) — it was an
     * anonymous-reachable DoS vector and also leaked cross-catalog objects (#733).
     *
     * Callers MUST pass non-empty $allowedRegisters and $allowedSchemas; otherwise
     * the method returns null without touching the database.
     *
     * @param string                 $uuid             The UUID of the object to find.
     * @param array<int|string>|null $allowedRegisters Register IDs the search may touch.
     * @param array<int|string>|null $allowedSchemas   Schema IDs the search may touch.
     *
     * @return array{register: int, schema: int}|null The register/schema IDs, or null.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec exclude DB-scan helper that searches a constrained subset of magic mapper
     *       tables to locate an object's register/schema IDs; pure framework plumbing.
     */
    public function findObjectLocation(
        string $uuid,
        ?array $allowedRegisters=null,
        ?array $allowedSchemas=null
    ): ?array {
        if (empty($allowedRegisters) === true || empty($allowedSchemas) === true) {
            // Fail closed — without an explicit constraint we will NOT do a
            // platform-wide scan. This is the post-#734 behaviour.
            return null;
        }

        // Build a UNION ALL query across the catalog's (register × schema) magic
        // tables. The table name pattern is deterministic so there is no need to
        // reflect against information_schema on the hot path.
        $unionParts = [];
        $quotedUuid = $this->db->quote($uuid);
        foreach ($allowedRegisters as $register) {
            if (is_numeric($register) === false) {
                continue;
            }

            $registerId = (int) $register;
            foreach ($allowedSchemas as $schema) {
                if (is_numeric($schema) === false) {
                    continue;
                }

                $schemaId = (int) $schema;
                $table    = "oc_openregister_table_{$registerId}_{$schemaId}";
                if ($this->magicTableExists($table) === false) {
                    continue;
                }

                $part         = "(SELECT {$registerId} AS register_id,";
                $part        .= " {$schemaId} AS schema_id";
                $part        .= " FROM {$table} WHERE _uuid = {$quotedUuid})";
                $unionParts[] = $part;
            }
        }//end foreach

        if (empty($unionParts) === true) {
            return null;
        }

        $sql    = implode(' UNION ALL ', $unionParts).' LIMIT 1';
        $result = $this->db->executeQuery($sql);
        $row    = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            return null;
        }

        return [
            'register' => (int) $row['register_id'],
            'schema'   => (int) $row['schema_id'],
        ];

    }//end findObjectLocation()

    /**
     * Lightweight existence probe for a magic-mapper table.
     *
     * Cached for the lifetime of the service instance. Lets callers safely UNION
     * across a catalog's (register × schema) combinations even when some pairs
     * have no backing table — without falling back to a platform-wide
     * information_schema scan (#734).
     *
     * @param string $table The magic-mapper table name.
     *
     * @return bool True when the table exists.
     *
     * @spec exclude DB-existence-probe plumbing for the constrained findObjectLocation;
     *       pure framework helper.
     */
    private function magicTableExists(string $table): bool
    {
        if (isset($this->magicTableCache[$table]) === true) {
            return $this->magicTableCache[$table];
        }

        // Use a parameterised information_schema lookup constrained to this single
        // table name and the current database (prevents cross-schema false-positives).
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from('information_schema.tables')
            ->where($qb->expr()->eq('table_name', $qb->createNamedParameter($table)))
            ->andWhere($qb->expr()->eq('table_schema', $qb->createFunction('DATABASE()')));

        try {
            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();
            $exists = ($row !== false && (int) ($row['cnt'] ?? 0) > 0);
        } catch (\Exception $e) {
            $exists = false;
        }

        $this->magicTableCache[$table] = $exists;
        return $exists;

    }//end magicTableExists()

    /**
     * Build the ObjectService search query for a catalog index request.
     *
     * Merges the incoming request parameters with catalog-level schema/register filters,
     * handles multi-schema and multi-register cases, and strips non-universal _order fields
     * when searching across multiple registers.
     *
     * @param array  $catalog       Catalog data array (keys: schemas, registers).
     * @param array  $queryParams   Raw request query parameters from IRequest::getParams().
     * @param object $objectService ObjectService instance (already resolved from container).
     *
     * @return array The merged and sanitised search query ready for searchObjectsPaginated().
     *
     * @spec exclude Query-assembly plumbing extracted from PublicationsController; translates
     *       request params into an ObjectService search query, no domain behavior of its own.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function buildCatalogSearchQuery(array $catalog, array $queryParams, object $objectService): array
    {
        // Use ObjectService centralized query builder which handles dot-to-underscore conversion.
        $searchQuery = array_merge(
            $objectService->buildSearchQuery($queryParams),
            ['_includeDeleted' => false]
        );

        // Clean up catalog-specific parameters.
        unset($searchQuery['catalogSlug'], $searchQuery['fq']);

        // Handle catalog filtering using _schemas for multi-schema search.
        if (empty($catalog['schemas']) === false) {
            $schemas = $catalog['schemas'];
            // Parse JSON string if needed.
            if (is_string($schemas) === true) {
                $schemas = json_decode($schemas, true) ?? [];
            }

            $schemas = array_map('intval', $schemas);
            // Pass all schemas for both search and faceting.
            $searchQuery['_schemas'] = $schemas;
            // Only set _schema for single-schema catalogs for magic mapper optimization.
            // Explicitly unset _schema for multi-schema search to prevent auto-setting.
            unset($searchQuery['_schema']);
            if (count($schemas) === 1) {
                $searchQuery['_schema'] = $schemas[0];
            }
        }//end if

        if (empty($catalog['registers']) === false) {
            $registers = $catalog['registers'];
            // Parse JSON string if needed.
            if (is_string($registers) === true) {
                $registers = json_decode($registers, true) ?? [];
            }

            $registers = array_map('intval', $registers);
            if (count($registers) === 1) {
                // Single register: use magic mapper optimization.
                $searchQuery['_register'] = $registers[0];
                return $searchQuery;
            }

            // Multi-register: pass all register IDs and prevent auto-setting.
            $searchQuery['_registers'] = $registers;
            $searchQuery['_register']  = null;

            // Multi-register search: strip _order on non-universal fields
            // since schemas may have different property names (e.g., 'name' vs 'naam').
            // Only allow metadata fields that exist in all magic mapper tables.
            if (empty($searchQuery['_order']) === false && is_array($searchQuery['_order']) === true) {
                foreach (array_keys($searchQuery['_order']) as $orderField) {
                    if (in_array($orderField, self::UNIVERSAL_ORDER_FIELDS, true) === false) {
                        unset($searchQuery['_order'][$orderField]);
                    }
                }

                if (empty($searchQuery['_order']) === true) {
                    unset($searchQuery['_order']);
                }
            }
        }//end if

        return $searchQuery;

    }//end buildCatalogSearchQuery()

    /**
     * Resolve schema and register objects from OpenRegister mappers for catalog enrichment.
     *
     * Returns an array with keys 'schemas' (id → {id, slug, title}) and
     * 'registers' (id → {id, slug, title}). Missing entries are silently skipped.
     *
     * @param array $catalog Catalog data array (keys: schemas, registers).
     *
     * @return array{schemas: array<int|string, array>, registers: array<int|string, array>}
     *
     * @spec exclude Metadata-resolution plumbing extracted from PublicationsController; looks up
     *       schema/register labels via OR mappers for response enrichment, no domain behavior.
     */
    public function resolveSchemaAndRegisterObjects(array $catalog): array
    {
        $resolvedSchemas   = [];
        $resolvedRegisters = [];

        try {
            $schemaMapper   = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
            $registerMapper = $this->container->get('OCA\OpenRegister\Db\RegisterMapper');

            $schemaIds = $catalog['schemas'] ?? [];
            if (is_string($schemaIds) === true) {
                $schemaIds = json_decode($schemaIds, true) ?? [];
            }

            foreach ($schemaIds as $schemaId) {
                try {
                    $schema = $schemaMapper->find((int) $schemaId);
                    $resolvedSchemas[$schemaId] = [
                        'id'    => $schema->getId(),
                        'slug'  => $schema->getSlug(),
                        'title' => $schema->getTitle(),
                    ];
                } catch (\Exception $e) {
                    // Schema not found, skip.
                }
            }

            $registerIds = $catalog['registers'] ?? [];
            if (is_string($registerIds) === true) {
                $registerIds = json_decode($registerIds, true) ?? [];
            }

            foreach ($registerIds as $registerId) {
                try {
                    $register = $registerMapper->find((int) $registerId);
                    $resolvedRegisters[$registerId] = [
                        'id'    => $register->getId(),
                        'slug'  => $register->getSlug(),
                        'title' => $register->getTitle(),
                    ];
                } catch (\Exception $e) {
                    // Register not found, skip.
                }
            }
        } catch (\Exception $e) {
            // OpenRegister not available, return empty sets.
        }//end try

        return [
            'schemas'   => $resolvedSchemas,
            'registers' => $resolvedRegisters,
        ];

    }//end resolveSchemaAndRegisterObjects()

    /**
     * Find an object within a catalog's registers/schemas using ObjectService.
     *
     * Iterates over each (register, schema) combination in the catalog.
     * Returns the first matching object entity, or null if not found.
     *
     * @param array  $catalog       Catalog data array (keys: schemas, registers).
     * @param string $id            The UUID of the object to find.
     * @param object $objectService ObjectService instance (already resolved from container).
     *
     * @return object|null The found object entity, or null.
     *
     * @spec exclude Lookup plumbing extracted from PublicationsController; iterates a catalog's
     *       (register, schema) pairs and delegates the actual read to ObjectService::find().
     */
    public function findObjectInCatalog(array $catalog, string $id, object $objectService): ?object
    {
        $catalogRegisters = $catalog['registers'] ?? [];
        $catalogSchemas   = $catalog['schemas'] ?? [];

        // Parse JSON string if needed (catalog fields may be JSON-encoded).
        if (is_string($catalogRegisters) === true) {
            $catalogRegisters = json_decode($catalogRegisters, true) ?? [];
        }

        if (is_string($catalogSchemas) === true) {
            $catalogSchemas = json_decode($catalogSchemas, true) ?? [];
        }

        $register = null;
        if (empty($catalogRegisters) === false) {
            $register = (int) $catalogRegisters[0];
        }

        // For multi-schema catalogs, loop through all schemas to find the object.
        $schemasToTry = array_map('intval', $catalogSchemas);
        foreach ($schemasToTry as $schemaId) {
            try {
                $object = $objectService->find(
                    id: $id,
                    _extend: [],
                    files: false,
                    register: $register,
                    schema: $schemaId,
                    _rbac: true,
                    _multitenancy: false
                );
                if ($object !== null) {
                    return $object;
                }
            } catch (DoesNotExistException $e) {
                // Object not found in this schema, try next one.
                continue;
            }
        }//end foreach

        return null;

    }//end findObjectInCatalog()

    /**
     * Recursively strips empty values (null, empty string, empty array) from an array.
     *
     * Used to reduce API response payload by omitting properties that have no value.
     * Values of 0, false, and "0" are preserved as they are meaningful.
     *
     * @param array $data The data array to strip empty values from.
     *
     * @return array The data with empty values removed.
     *
     * @spec exclude Response-shaping plumbing extracted from PublicationsController; recursively
     *       prunes empty values to slim the payload, no domain behavior.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function stripEmptyValues(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value) === true) {
                $this->processArrayValue(result: $result, key: $key, value: $value);
                continue;
            }//end if

            if ($value === null || $value === '') {
                continue;
            }

            $result[$key] = $value;
        }//end foreach

        return $result;

    }//end stripEmptyValues()

    /**
     * Process a single array value during empty-value stripping.
     *
     * Handles both sequential (list) and associative arrays, recursing into nested arrays.
     *
     * @param array      $result Reference to the result array being built.
     * @param int|string $key    The key for this value.
     * @param array      $value  The array value to process.
     *
     * @return void
     */
    private function processArrayValue(array &$result, int|string $key, array $value): void
    {
        if (array_is_list($value) === true) {
            $stripped = [];
            foreach ($value as $item) {
                if (is_array($item) === false) {
                    $stripped[] = $item;
                    continue;
                }

                $stripped[] = $this->stripEmptyValues(data: $item);
            }

            if (empty($stripped) === false) {
                $result[$key] = $stripped;
            }

            return;
        }

        $stripped = $this->stripEmptyValues(data: $value);
        if (empty($stripped) === false) {
            $result[$key] = $stripped;
        }

    }//end processArrayValue()
}//end class
