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
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Domain metrics provider for OpenCatalogi (AppHost escape hatch).
 *
 * @psalm-suppress UnusedClass Resolved via the AppHost container alias.
 */
class OpenCatalogiMetricsProvider implements IMetricsProvider
{
    /**
     * Constructor.
     *
     * @param IDBConnection   $db     Database connection.
     * @param LoggerInterface $logger Logger.
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

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
     * @spec openspec/changes/adopt-apphost/specs/adopt-apphost/spec.md
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
            value: $this->countObjectsBySchemaPattern('%atalog%')
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
            value: $this->countObjectsBySchemaPattern('%irectory%')
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
     * Get publication counts grouped by status and catalog.
     *
     * @return array<array{status: string, catalog: string, cnt: string}> Grouped counts.
     */
    private function getPublicationCounts(): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(
                $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.status')) AS status"),
                $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.catalog')) AS catalog"),
            )
                ->selectAlias($qb->func()->count('o.id'), 'cnt')
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter('%ublicati%')))
                ->groupBy('status', 'catalog');

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            return $rows;
        } catch (\Exception $e) {
            $this->logger->warning('[OpenCatalogiMetricsProvider] Failed to get publication counts', ['error' => $e->getMessage()]);
            return [];
        }//end try

    }//end getPublicationCounts()

    /**
     * Count objects matching a schema title pattern.
     *
     * @param string $pattern SQL LIKE pattern for schema title.
     *
     * @return int Object count.
     */
    private function countObjectsBySchemaPattern(string $pattern): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('o.id', 'cnt'))
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter($pattern)));

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            return (int) ($row['cnt'] ?? 0);
        } catch (\Exception $e) {
            $this->logger->warning('[OpenCatalogiMetricsProvider] Failed to count objects', ['error' => $e->getMessage()]);
            return 0;
        }

    }//end countObjectsBySchemaPattern()

    /**
     * Get listing counts grouped by status.
     *
     * @return array<array{status: string, cnt: string}> Grouped counts.
     */
    private function getListingCounts(): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(
                $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.status')) AS status"),
            )
                ->selectAlias($qb->func()->count('o.id'), 'cnt')
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter('%isting%')))
                ->groupBy('status');

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            return $rows;
        } catch (\Exception $e) {
            $this->logger->warning('[OpenCatalogiMetricsProvider] Failed to get listing counts', ['error' => $e->getMessage()]);
            return [];
        }

    }//end getListingCounts()

    /**
     * Sum usage-counter objects by catalog and kind for the metrics families.
     *
     * Reads the privacy-safe `usageCounter` objects (schema title matched by
     * pattern) and sums their JSON `count` grouped by `catalog` + `kind`.
     * Returns ['view' => [catalog => total], 'download' => [catalog => total]].
     * No per-publication grouping is performed (cardinality, ANA-008).
     *
     * @return array{view: array<string,int>, download: array<string,int>} Totals by catalog.
     */
    private function getUsageTotalsByCatalog(): array
    {
        $out = ['view' => [], 'download' => []];

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(
                $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.catalog')) AS catalog"),
                $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.kind')) AS kind"),
            )
                ->selectAlias(
                    $qb->createFunction("SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.count')) AS UNSIGNED))"),
                    'total'
                )
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter('%sageCounter%')))
                ->groupBy('catalog', 'kind');

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            foreach ($rows as $row) {
                $kind = (string) ($row['kind'] ?? '');
                if (isset($out[$kind]) === false) {
                    continue;
                }

                $catalog = (string) ($row['catalog'] ?? '');
                $out[$kind][$catalog] = (int) ($row['total'] ?? 0);
            }
        } catch (\Exception $e) {
            $this->logger->warning('[OpenCatalogiMetricsProvider] Failed to get usage totals', ['error' => $e->getMessage()]);
        }//end try

        return $out;

    }//end getUsageTotalsByCatalog()
}//end class
