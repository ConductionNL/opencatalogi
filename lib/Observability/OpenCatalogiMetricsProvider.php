<?php

/**
 * OpenCatalogi AppHost Metrics Provider.
 *
 * Escape-hatch metrics provider for the OpenRegister AppHost observability
 * engine (ADR-040 / ADR-006). The structurally-simple metrics (the two health
 * checks and `search_requests_total`) are expressed declaratively in the
 * `observability` block of src/manifest.json; the JSON-field-grouped domain
 * metrics cannot be expressed by the closed declarative source-kind set
 * (grouping happens on JSON object fields, the usageCounter sum splits one
 * source schema into two named families by `kind`, and the contract emits
 * explicit zero-fallback lines). This provider reproduces those families
 * byte-for-byte with the SAME OpenRegister-backed queries the now-deleted
 * MetricsController ran, so the /api/metrics contract is unchanged on adoption.
 *
 * The engine resolves this class via the container alias
 * `OCA\OpenRegister\AppHost\IMetricsProvider::opencatalogi` (ADR-035 pattern)
 * and merges its MetricSample output into the response; the AppHost
 * PrometheusRenderer prepends the `opencatalogi_` prefix and renders the
 * exposition format, so this provider never emits raw Prometheus text.
 *
 * @category Observability
 * @package  OCA\OpenCatalogi\Observability
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
 *
 * @spec openspec/changes/adopt-apphost/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Observability;

use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Domain metrics provider for OpenCatalogi (AppHost escape hatch).
 *
 * Counts are sourced through OpenRegister object aggregation (ADR-022): the
 * provider resolves the relevant schemas via OpenRegister's `SchemaMapper`,
 * fetches their objects through `ObjectService`, and aggregates the JSON fields
 * in PHP. It no longer issues raw query builders against OpenRegister's storage
 * tables, so OR is free to change its physical layout without breaking this
 * contract.
 *
 * @psalm-suppress UnusedClass Resolved via the AppHost container alias.
 *
 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class OpenCatalogiMetricsProvider implements IMetricsProvider
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container DI container for the OR ObjectService / mappers.
     * @param LoggerInterface    $logger    Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Resolve the OpenRegister ObjectService, or null when unavailable.
     *
     * @return object|null The ObjectService, or null.
     *
     * @spec exclude Lazy DI accessor for the OR ObjectService; pure plumbing.
     */
    private function getObjectService(): ?object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            return null;
        }

    }//end getObjectService()

    /**
     * Resolve the OpenRegister SchemaMapper, or null when unavailable.
     *
     * @return object|null The SchemaMapper, or null.
     *
     * @spec exclude Lazy DI accessor for the OR SchemaMapper; pure plumbing.
     */
    private function getSchemaMapper(): ?object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
        } catch (\Throwable $e) {
            return null;
        }

    }//end getSchemaMapper()

    /**
     * Resolve the OpenRegister RegisterMapper, or null when unavailable.
     *
     * @return object|null The RegisterMapper, or null.
     *
     * @spec exclude Lazy DI accessor for the OR RegisterMapper; pure plumbing.
     */
    private function getRegisterMapper(): ?object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Db\RegisterMapper');
        } catch (\Throwable $e) {
            return null;
        }

    }//end getRegisterMapper()

    /**
     * Normalise an OpenRegister object (entity or array) to a plain array.
     *
     * @param mixed $object The OR object.
     *
     * @return array<string, mixed> The object's own fields.
     *
     * @spec exclude Shape-normalisation plumbing for OR objects; no domain behaviour.
     */
    private function normalise(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true) {
            if (method_exists($object, 'jsonSerialize') === true) {
                $data = $object->jsonSerialize();
                if (is_array($data) === true) {
                    return $data;
                }
            }

            return (array) $object;
        }

        return [];

    }//end normalise()

    /**
     * Resolve the IDs of schemas whose title contains the given needle.
     *
     * Uses OpenRegister's SchemaMapper (an OR abstraction) rather than a raw SQL
     * LIKE against the schema table.
     *
     * @param string $needle Case-insensitive substring to match against schema titles.
     *
     * @return array<int, int> Matching schema IDs.
     *
     * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
     */
    private function resolveSchemaIds(string $needle): array
    {
        $schemaMapper = $this->getSchemaMapper();
        if ($schemaMapper === null) {
            return [];
        }

        $ids = [];
        try {
            foreach ($schemaMapper->findAll() as $schema) {
                $data  = $this->normalise($schema);
                $title = (string) ($data['title'] ?? '');
                $id    = ($data['id'] ?? null);
                if ($id !== null && $title !== '' && stripos($title, $needle) !== false) {
                    $ids[] = (int) $id;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('[OpenCatalogiMetricsProvider] Failed to resolve schemas', ['needle' => $needle, 'error' => $e->getMessage()]);
            return [];
        }

        return $ids;

    }//end resolveSchemaIds()

    /**
     * Build a map of schema ID → register IDs that contain it.
     *
     * @return array<int, array<int, int>> Map of schema ID → register IDs.
     *
     * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
     */
    private function buildSchemaRegisterMap(): array
    {
        $registerMapper = $this->getRegisterMapper();
        if ($registerMapper === null) {
            return [];
        }

        $map = [];
        try {
            foreach ($registerMapper->findAll() as $register) {
                $data       = $this->normalise($register);
                $registerId = ($data['id'] ?? null);
                if ($registerId === null) {
                    continue;
                }

                $schemas = ($data['schemas'] ?? []);
                if (is_array($schemas) === false) {
                    continue;
                }

                foreach ($schemas as $schema) {
                    // Register schema lists may hold IDs or nested schema arrays.
                    $schemaId = $schema;
                    if (is_array($schema) === true) {
                        $schemaId = ($schema['id'] ?? null);
                    }

                    if (is_numeric($schemaId) === false) {
                        continue;
                    }

                    $map[(int) $schemaId][] = (int) $registerId;
                }
            }//end foreach
        } catch (\Exception $e) {
            $this->logger->warning('[OpenCatalogiMetricsProvider] Failed to map schemas to registers', ['error' => $e->getMessage()]);
            return [];
        }//end try

        return $map;

    }//end buildSchemaRegisterMap()

    /**
     * Fetch, via OpenRegister object aggregation, every object of the schemas whose
     * title matches the needle, as plain field arrays.
     *
     * @param string $needle Schema-title substring identifying the domain schemas.
     *
     * @return array<int, array<string, mixed>> The matching objects' own fields.
     *
     * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
     */
    private function fetchObjectsBySchemaNeedle(string $needle): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $schemaIds = $this->resolveSchemaIds($needle);
        if ($schemaIds === []) {
            return [];
        }

        $schemaRegisterMap = $this->buildSchemaRegisterMap();
        $objects           = [];

        foreach ($schemaIds as $schemaId) {
            $registerIds = ($schemaRegisterMap[$schemaId] ?? []);
            foreach ($registerIds as $registerId) {
                try {
                    $results = $objectService->searchObjects(
                        query: [
                            '@self' => [
                                'register' => $registerId,
                                'schema'   => $schemaId,
                            ],
                        ],
                        _rbac: false,
                        _multitenancy: false,
                    );
                } catch (\Exception $e) {
                    $this->logger->warning(
                        '[OpenCatalogiMetricsProvider] Object aggregation failed',
                        ['schema' => $schemaId, 'register' => $registerId, 'error' => $e->getMessage()]
                    );
                    continue;
                }

                if (is_array($results) === false) {
                    continue;
                }

                foreach ($results as $result) {
                    $objects[] = $this->normalise($result);
                }
            }//end foreach
        }//end foreach

        return $objects;

    }//end fetchObjectsBySchemaNeedle()

    /**
     * Produce OpenCatalogi's domain metric samples.
     *
     * Order and family shape match the pre-adoption MetricsController exactly:
     * publications (status+catalog), catalogs (scalar), listings (status, with
     * a zero fallback), directory entries (scalar), then the usageCounter
     * view/download totals by catalog (each with a `{catalog=""} 0` fallback).
     *
     * @return MetricSample[] The provider's samples.
     *
     * @spec openspec/specs/adopt-apphost/spec.md
     */
    public function metrics(): array
    {
        $samples = [];

        // Publications total by status and catalog.
        $publicationPoints = [];
        foreach ($this->getPublicationCounts() as $row) {
            $publicationPoints[] = [
                'labels' => [
                    'status'  => (string) ($row['status'] ?? ''),
                    'catalog' => (string) ($row['catalog'] ?? ''),
                ],
                'value'  => (int) ($row['cnt'] ?? 0),
            ];
        }

        $samples[] = new MetricSample(
            name: 'publications_total',
            type: 'gauge',
            help: 'Total publications by status and catalog',
            samples: $publicationPoints
        );

        // Catalogs total.
        $samples[] = MetricSample::single(
            name: 'catalogs_total',
            type: 'gauge',
            help: 'Total catalogs',
            value: $this->countObjectsBySchemaNeedle('atalog')
        );

        // Listings total by status (with the historical zero fallback).
        $listingPoints = [];
        foreach ($this->getListingCounts() as $row) {
            $listingPoints[] = [
                'labels' => ['status' => (string) ($row['status'] ?? '')],
                'value'  => (int) ($row['cnt'] ?? 0),
            ];
        }

        if ($listingPoints === []) {
            $listingPoints[] = ['labels' => [], 'value' => 0];
        }

        $samples[] = new MetricSample(
            name: 'listings_total',
            type: 'gauge',
            help: 'Total listings by status',
            samples: $listingPoints
        );

        // Federation — directory entries total.
        $samples[] = MetricSample::single(
            name: 'directory_entries_total',
            type: 'gauge',
            help: 'Total federated directory entries',
            value: $this->countObjectsBySchemaNeedle('irectory')
        );

        // Usage analytics — catalog-labelled view/download totals (ANA-008).
        $usage = $this->getUsageTotalsByCatalog();

        $viewPoints = [];
        foreach ($usage['view'] as $catalog => $count) {
            $viewPoints[] = ['labels' => ['catalog' => (string) $catalog], 'value' => (int) $count];
        }

        if ($viewPoints === []) {
            $viewPoints[] = ['labels' => ['catalog' => ''], 'value' => 0];
        }

        $samples[] = new MetricSample(
            name: 'publication_views_total',
            type: 'counter',
            help: 'Total counted publication views by catalog',
            samples: $viewPoints
        );

        $downloadPoints = [];
        foreach ($usage['download'] as $catalog => $count) {
            $downloadPoints[] = ['labels' => ['catalog' => (string) $catalog], 'value' => (int) $count];
        }

        if ($downloadPoints === []) {
            $downloadPoints[] = ['labels' => ['catalog' => ''], 'value' => 0];
        }

        $samples[] = new MetricSample(
            name: 'file_downloads_total',
            type: 'counter',
            help: 'Total counted file downloads by catalog',
            samples: $downloadPoints
        );

        return $samples;

    }//end metrics()

    /**
     * Get publication counts grouped by status and catalog, via OR aggregation.
     *
     * @return array<array{status: string, catalog: string, cnt: int}> Grouped counts.
     *
     * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
     */
    private function getPublicationCounts(): array
    {
        $grouped = [];
        foreach ($this->fetchObjectsBySchemaNeedle('ublicati') as $object) {
            $status  = (string) ($object['status'] ?? '');
            $catalog = (string) ($object['catalog'] ?? '');
            $key     = $status.'|'.$catalog;
            if (isset($grouped[$key]) === false) {
                $grouped[$key] = ['status' => $status, 'catalog' => $catalog, 'cnt' => 0];
            }

            $grouped[$key]['cnt']++;
        }

        return array_values($grouped);

    }//end getPublicationCounts()

    /**
     * Count objects of the schemas whose title contains the given needle.
     *
     * @param string $needle Schema-title substring.
     *
     * @return int Object count.
     *
     * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
     */
    private function countObjectsBySchemaNeedle(string $needle): int
    {
        return count($this->fetchObjectsBySchemaNeedle($needle));

    }//end countObjectsBySchemaNeedle()

    /**
     * Get listing counts grouped by status, via OR aggregation.
     *
     * @return array<array{status: string, cnt: int}> Grouped counts.
     *
     * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
     */
    private function getListingCounts(): array
    {
        $grouped = [];
        foreach ($this->fetchObjectsBySchemaNeedle('isting') as $object) {
            $status = (string) ($object['status'] ?? '');
            if (isset($grouped[$status]) === false) {
                $grouped[$status] = ['status' => $status, 'cnt' => 0];
            }

            $grouped[$status]['cnt']++;
        }

        return array_values($grouped);

    }//end getListingCounts()

    /**
     * Sum usage-counter objects by catalog and kind for the metrics families.
     *
     * Reads the privacy-safe `usageCounter` objects (via OR object aggregation)
     * and sums their `count` grouped by `catalog` + `kind`.
     * Returns ['view' => [catalog => total], 'download' => [catalog => total]].
     * No per-publication grouping is performed (cardinality, ANA-008).
     *
     * @return array{view: array<string,int>, download: array<string,int>} Totals by catalog.
     *
     * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
     */
    private function getUsageTotalsByCatalog(): array
    {
        $out = ['view' => [], 'download' => []];

        foreach ($this->fetchObjectsBySchemaNeedle('sageCounter') as $object) {
            $kind = (string) ($object['kind'] ?? '');
            if (isset($out[$kind]) === false) {
                continue;
            }

            $catalog = (string) ($object['catalog'] ?? '');
            $count   = (int) ($object['count'] ?? 0);
            if (isset($out[$kind][$catalog]) === false) {
                $out[$kind][$catalog] = 0;
            }

            $out[$kind][$catalog] += $count;
        }//end foreach

        return $out;

    }//end getUsageTotalsByCatalog()
}//end class
