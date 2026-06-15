<?php
/**
 * OpenCatalogi Usage Counter Service.
 *
 * Privacy-safe, aggregate-only usage counting for publications. Records daily
 * view/download counts as OpenRegister objects in a `usageCounter` schema —
 * one object per (publication, date, kind). NO IP address, user agent, session
 * id, referrer, or any other request attribute is ever persisted. Aggregation,
 * roll-ups, and top-N ranking are computed by querying those counter objects
 * through OpenRegister object search (hydra ADR-022 — no bespoke tables/SQL).
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Service;

use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Aggregate-only usage counting and statistics for publications.
 *
 * @spec openspec/changes/publication-usage-analytics/specs/publication-usage-analytics/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class UsageCounterService
{

    /**
     * Counter kind: a public publication-detail view.
     *
     * @var string
     */
    public const KIND_VIEW = 'view';

    /**
     * Counter kind: a public publication file download.
     *
     * @var string
     */
    public const KIND_DOWNLOAD = 'download';

    /**
     * IAppConfig key holding the OpenRegister register id for counter objects.
     *
     * @var string
     */
    private const CONFIG_REGISTER = 'usageCounter_register';

    /**
     * IAppConfig key holding the OpenRegister schema id for counter objects.
     *
     * @var string
     */
    private const CONFIG_SCHEMA = 'usageCounter_schema';

    /**
     * IAppConfig key holding the configurable known-crawler user-agent list (CSV).
     *
     * The shipped default below is applied when the key is unset, so operators
     * can extend the list without a code release (ANA-003).
     *
     * @var string
     */
    private const CONFIG_CRAWLERS = 'usage_counter_crawlers';

    /**
     * Shipped default known-crawler substrings (case-insensitive match on UA).
     *
     * Search engines, monitoring probes, and the DCAT/sitemap harvesters whose
     * automated reads are not citizen "reach". Evaluated in memory only; the
     * user agent itself is never stored, hashed, or logged (ANA-003).
     *
     * @var array<int, string>
     */
    private const DEFAULT_CRAWLERS = [
        'bot',
        'crawl',
        'spider',
        'slurp',
        'googlebot',
        'bingbot',
        'duckduckbot',
        'yandex',
        'baiduspider',
        'facebookexternalhit',
        'monitoring',
        'pingdom',
        'uptimerobot',
        'curl',
        'wget',
        'python-requests',
        'go-http-client',
        'headlesschrome',
    ];

    /**
     * UsageCounterService constructor.
     *
     * @param IAppConfig         $appConfig  App config (register/schema/crawler list).
     * @param IAppManager        $appManager Detects whether OpenRegister is installed.
     * @param ContainerInterface $container  DI container for the OR ObjectService.
     * @param LoggerInterface    $logger     PSR-3 logger.
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve the OpenRegister ObjectService, or null when it is unavailable.
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null The ObjectService, or null.
     *
     * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
     *       ObjectService from the container; pure framework plumbing, no domain behaviour.
     */
    public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        }

        return null;

    }//end getObjectService()

    /**
     * Determine whether a request's user agent matches the known-crawler list.
     *
     * The user agent is evaluated in memory only — it is NEVER stored, hashed,
     * or logged from this path (ANA-003). An empty UA is treated as a crawler
     * (automated clients commonly omit it).
     *
     * @param string|null $userAgent The raw User-Agent header value, or null.
     *
     * @return boolean True when the request should NOT be counted.
     *
     * @spec openspec/changes/publication-usage-analytics/specs/publication-usage-analytics/spec.md
     */
    public function isCrawler(?string $userAgent): bool
    {
        $userAgent = trim((string) $userAgent);
        if ($userAgent === '') {
            return true;
        }

        $needle = strtolower($userAgent);
        foreach ($this->getCrawlerList() as $pattern) {
            if ($pattern !== '' && str_contains($needle, $pattern) === true) {
                return true;
            }
        }

        return false;

    }//end isCrawler()

    /**
     * Resolve the configured crawler substring list (shipped default + config).
     *
     * @return array<int, string> Lower-cased crawler substrings.
     *
     * @spec exclude Config plumbing — merges the shipped default crawler list with the
     *       operator-configurable CSV; no counting/aggregation behaviour of its own.
     */
    private function getCrawlerList(): array
    {
        $configured = (string) $this->appConfig->getValueString('opencatalogi', self::CONFIG_CRAWLERS, '');
        if (trim($configured) === '') {
            return self::DEFAULT_CRAWLERS;
        }

        $extra = array_filter(
            array_map(
                static fn(string $item): string => strtolower(trim($item)),
                explode(',', $configured)
            )
        );

        return array_values(array_unique(array_merge(self::DEFAULT_CRAWLERS, $extra)));

    }//end getCrawlerList()

    /**
     * Increment today's usage counter for a publication, fire-and-forget.
     *
     * Performs a read-modify-write upsert on today's `(publication, date, kind)`
     * counter object in OpenRegister. A small lost-update tolerance is accepted
     * by design: two concurrent increments may drop one count (documented in
     * ANA-002). Any failure is logged and swallowed — counting MUST never break
     * or slow the originating public read (ANA-001 / APB-014 posture).
     *
     * The stored object contains ONLY publication reference, date, kind, and
     * count. No request-derived attribute is ever written (ANA-002).
     *
     * @param string      $publicationId The publication UUID being counted.
     * @param string      $kind          KIND_VIEW or KIND_DOWNLOAD.
     * @param string|null $userAgent     Request UA, evaluated for crawler-skip only.
     * @param string|null $catalog       Optional catalog slug for roll-up labelling.
     *
     * @return boolean True when a count was recorded; false when skipped/failed.
     *
     * @spec openspec/changes/publication-usage-analytics/specs/publication-usage-analytics/spec.md
     */
    public function increment(
        string $publicationId,
        string $kind,
        ?string $userAgent=null,
        ?string $catalog=null
    ): bool {
        try {
            if ($publicationId === '' || in_array($kind, [self::KIND_VIEW, self::KIND_DOWNLOAD], true) === false) {
                return false;
            }

            // Best-effort bot filtering — evaluated in memory, UA discarded (ANA-003).
            if ($this->isCrawler($userAgent) === true) {
                return false;
            }

            $objectService = $this->getObjectService();
            if ($objectService === null) {
                return false;
            }

            $register = $this->getRegisterId();
            $schema   = $this->getSchemaId();
            if ($register === null || $schema === null) {
                $this->logger->info('[UsageCounterService] counter register/schema unconfigured; skipping count');
                return false;
            }

            $today    = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
            $existing = $this->findCounter(
                objectService: $objectService,
                register: $register,
                schema: $schema,
                publicationId: $publicationId,
                date: $today,
                kind: $kind
            );

            if ($existing !== null) {
                $data           = $this->normaliseCounter($existing);
                $data['count']  = ((int) ($data['count'] ?? 0) + 1);
                $objectService->saveObject(
                    object: $data,
                    register: $register,
                    schema: $schema,
                    uuid: (string) ($data['id'] ?? $data['uuid'] ?? ''),
                    _rbac: false,
                    _multitenancy: false,
                );
                return true;
            }

            // No counter for today yet — create one. Catalog is an optional
            // roll-up label only (no personal data); it mirrors the publication's
            // own catalog and never carries request-derived attributes.
            $objectService->saveObject(
                object: [
                    'publication' => $publicationId,
                    'catalog'     => ($catalog ?? ''),
                    'date'        => $today,
                    'kind'        => $kind,
                    'count'       => 1,
                ],
                register: $register,
                schema: $schema,
                _rbac: false,
                _multitenancy: false,
            );

            return true;
        } catch (\Throwable $e) {
            // ANA-001: a counting failure MUST be logged and swallowed, never
            // propagated. The user-agent is deliberately NOT included (ANA-003).
            $this->logger->warning(
                '[UsageCounterService] count failed (swallowed)',
                [
                    'kind'  => $kind,
                    'error' => $e->getMessage(),
                ]
            );
            return false;
        }//end try

    }//end increment()

    /**
     * Find today's counter object for a (publication, date, kind) tuple.
     *
     * @param \OCA\OpenRegister\Service\ObjectService $objectService OR object service.
     * @param string                                  $register      Counter register id.
     * @param string                                  $schema        Counter schema id.
     * @param string                                  $publicationId Publication UUID.
     * @param string                                  $date          Day (Y-m-d).
     * @param string                                  $kind          Counter kind.
     *
     * @return array<string, mixed>|object|null The counter, or null if absent.
     *
     * @spec exclude Internal query helper for increment()/aggregate(); the public
     *       counting + aggregation contract is covered by increment() and getPublicationStats().
     */
    private function findCounter(
        \OCA\OpenRegister\Service\ObjectService $objectService,
        string $register,
        string $schema,
        string $publicationId,
        string $date,
        string $kind
    ) {
        $results = $objectService->searchObjects(
            query: [
                '@self'       => [
                    'register' => $register,
                    'schema'   => $schema,
                ],
                'publication' => $publicationId,
                'date'        => $date,
                'kind'        => $kind,
            ],
            _rbac: false,
            _multitenancy: false,
        );

        if (is_array($results) === true && empty($results) === false) {
            return $results[0];
        }

        return null;

    }//end findCounter()

    /**
     * Fetch all counter objects for a publication within a date range.
     *
     * @param string      $publicationId Publication UUID.
     * @param string|null $from          Inclusive start day (Y-m-d), or null.
     * @param string|null $to            Inclusive end day (Y-m-d), or null.
     *
     * @return array<int, array<string, mixed>> Normalised counter rows.
     *
     * @spec openspec/changes/publication-usage-analytics/specs/publication-usage-analytics/spec.md
     */
    public function getCountersForPublication(string $publicationId, ?string $from=null, ?string $to=null): array
    {
        $objectService = $this->getObjectService();
        $register      = $this->getRegisterId();
        $schema        = $this->getSchemaId();
        if ($objectService === null || $register === null || $schema === null) {
            return [];
        }

        $results = $objectService->searchObjects(
            query: [
                '@self'       => [
                    'register' => $register,
                    'schema'   => $schema,
                ],
                'publication' => $publicationId,
            ],
            _rbac: false,
            _multitenancy: false,
        );

        return $this->filterByRange((is_array($results) === true ? $results : []), $from, $to);

    }//end getCountersForPublication()

    /**
     * Fetch all counter objects for a catalog within a date range.
     *
     * @param string      $catalog Catalog slug (roll-up label on the counter).
     * @param string|null $from    Inclusive start day (Y-m-d), or null.
     * @param string|null $to      Inclusive end day (Y-m-d), or null.
     *
     * @return array<int, array<string, mixed>> Normalised counter rows.
     *
     * @spec openspec/changes/publication-usage-analytics/specs/publication-usage-analytics/spec.md
     */
    public function getCountersForCatalog(string $catalog, ?string $from=null, ?string $to=null): array
    {
        $objectService = $this->getObjectService();
        $register      = $this->getRegisterId();
        $schema        = $this->getSchemaId();
        if ($objectService === null || $register === null || $schema === null) {
            return [];
        }

        $results = $objectService->searchObjects(
            query: [
                '@self'   => [
                    'register' => $register,
                    'schema'   => $schema,
                ],
                'catalog' => $catalog,
            ],
            _rbac: false,
            _multitenancy: false,
        );

        return $this->filterByRange((is_array($results) === true ? $results : []), $from, $to);

    }//end getCountersForCatalog()

    /**
     * Compute per-publication statistics: totals plus a daily timeseries.
     *
     * Pure aggregation over counter rows (no SQL) — the result is privacy-safe
     * by construction because the inputs hold only (publication, date, kind,
     * count). Returns the counting-start date so callers can distinguish "zero
     * views" from "not yet measured" (ANA-004).
     *
     * @param string      $publicationId Publication UUID.
     * @param string|null $from          Inclusive start day (Y-m-d), or null.
     * @param string|null $to            Inclusive end day (Y-m-d), or null.
     *
     * @return array{views:int,downloads:int,series:array<int,array{date:string,views:int,downloads:int}>,countingStart:?string}
     *
     * @spec openspec/changes/publication-usage-analytics/specs/publication-usage-analytics/spec.md
     */
    public function getPublicationStats(string $publicationId, ?string $from=null, ?string $to=null): array
    {
        $rows = $this->getCountersForPublication($publicationId, $from, $to);
        return $this->aggregateSeries($rows);

    }//end getPublicationStats()

    /**
     * Compute catalog roll-up: totals plus top-N publications by views/downloads.
     *
     * @param string      $catalog Catalog slug.
     * @param string|null $from    Inclusive start day (Y-m-d), or null.
     * @param string|null $to      Inclusive end day (Y-m-d), or null.
     * @param integer     $top     Number of top publications to return (default 10).
     *
     * @return array{views:int,downloads:int,topViewed:array<int,array{publication:string,views:int,downloads:int}>,topDownloaded:array<int,array{publication:string,views:int,downloads:int}>}
     *
     * @spec openspec/changes/publication-usage-analytics/specs/publication-usage-analytics/spec.md
     */
    public function getCatalogStats(string $catalog, ?string $from=null, ?string $to=null, int $top=10): array
    {
        $rows = $this->getCountersForCatalog($catalog, $from, $to);
        return $this->aggregateCatalog($rows, $top);

    }//end getCatalogStats()

    /**
     * Aggregate counter rows into totals and a sorted daily series.
     *
     * Exposed as a pure function (no OR dependency) so the aggregation maths is
     * independently unit-testable.
     *
     * @param array<int, array<string, mixed>> $rows Counter rows.
     *
     * @return array{views:int,downloads:int,series:array<int,array{date:string,views:int,downloads:int}>,countingStart:?string}
     *
     * @spec openspec/changes/publication-usage-analytics/specs/publication-usage-analytics/spec.md
     */
    public function aggregateSeries(array $rows): array
    {
        $byDate        = [];
        $views         = 0;
        $downloads     = 0;
        $countingStart = null;

        foreach ($rows as $row) {
            $row   = $this->normaliseCounter($row);
            $date  = (string) ($row['date'] ?? '');
            $kind  = (string) ($row['kind'] ?? '');
            $count = max(0, (int) ($row['count'] ?? 0));
            if ($date === '') {
                continue;
            }

            if ($countingStart === null || $date < $countingStart) {
                $countingStart = $date;
            }

            if (isset($byDate[$date]) === false) {
                $byDate[$date] = ['date' => $date, 'views' => 0, 'downloads' => 0];
            }

            if ($kind === self::KIND_VIEW) {
                $byDate[$date]['views'] += $count;
                $views += $count;
            } else if ($kind === self::KIND_DOWNLOAD) {
                $byDate[$date]['downloads'] += $count;
                $downloads += $count;
            }
        }//end foreach

        ksort($byDate);

        return [
            'views'         => $views,
            'downloads'     => $downloads,
            'series'        => array_values($byDate),
            'countingStart' => $countingStart,
        ];

    }//end aggregateSeries()

    /**
     * Aggregate catalog counter rows into totals and top-N rankings.
     *
     * Pure function (no OR dependency) for independent unit testing of the
     * roll-up and ranking maths (ANA-005).
     *
     * @param array<int, array<string, mixed>> $rows Counter rows.
     * @param integer                          $top  Number of top entries (>= 1).
     *
     * @return array{views:int,downloads:int,topViewed:array<int,array{publication:string,views:int,downloads:int}>,topDownloaded:array<int,array{publication:string,views:int,downloads:int}>}
     *
     * @spec openspec/changes/publication-usage-analytics/specs/publication-usage-analytics/spec.md
     */
    public function aggregateCatalog(array $rows, int $top=10): array
    {
        $top         = max(1, $top);
        $byPub       = [];
        $totalViews  = 0;
        $totalDownl  = 0;

        foreach ($rows as $row) {
            $row   = $this->normaliseCounter($row);
            $pub   = (string) ($row['publication'] ?? '');
            $kind  = (string) ($row['kind'] ?? '');
            $count = max(0, (int) ($row['count'] ?? 0));
            if ($pub === '') {
                continue;
            }

            if (isset($byPub[$pub]) === false) {
                $byPub[$pub] = ['publication' => $pub, 'views' => 0, 'downloads' => 0];
            }

            if ($kind === self::KIND_VIEW) {
                $byPub[$pub]['views'] += $count;
                $totalViews += $count;
            } else if ($kind === self::KIND_DOWNLOAD) {
                $byPub[$pub]['downloads'] += $count;
                $totalDownl += $count;
            }
        }//end foreach

        $entries = array_values($byPub);

        $byViews = $entries;
        usort($byViews, static fn(array $a, array $b): int => ($b['views'] <=> $a['views']));

        $byDownloads = $entries;
        usort($byDownloads, static fn(array $a, array $b): int => ($b['downloads'] <=> $a['downloads']));

        return [
            'views'         => $totalViews,
            'downloads'     => $totalDownl,
            'topViewed'     => array_slice($byViews, 0, $top),
            'topDownloaded' => array_slice($byDownloads, 0, $top),
        ];

    }//end aggregateCatalog()

    /**
     * Filter counter rows to those whose `date` falls within [from, to].
     *
     * @param array<int, mixed> $rows Counter rows (arrays or ObjectEntity).
     * @param string|null       $from Inclusive start day, or null for open start.
     * @param string|null       $to   Inclusive end day, or null for open end.
     *
     * @return array<int, array<string, mixed>> Normalised, in-range rows.
     *
     * @spec exclude Pure date-range filter helper for the aggregation queries; the
     *       range contract is asserted through getPublicationStats()/getCatalogStats().
     */
    private function filterByRange(array $rows, ?string $from, ?string $to): array
    {
        $out = [];
        foreach ($rows as $row) {
            $row  = $this->normaliseCounter($row);
            $date = (string) ($row['date'] ?? '');
            if ($date === '') {
                continue;
            }

            if ($from !== null && $date < $from) {
                continue;
            }

            if ($to !== null && $date > $to) {
                continue;
            }

            $out[] = $row;
        }

        return $out;

    }//end filterByRange()

    /**
     * Normalise an OR object (ObjectEntity or array) to a flat counter array.
     *
     * @param mixed $row The counter object as returned by OR search.
     *
     * @return array<string, mixed> Flat counter data.
     *
     * @spec exclude Shape-normalisation plumbing — flattens an OR ObjectEntity/array into
     *       a plain counter array; no domain behaviour.
     */
    private function normaliseCounter(mixed $row): array
    {
        if (is_array($row) === true) {
            return $row;
        }

        if (is_object($row) === true) {
            if (method_exists($row, 'jsonSerialize') === true) {
                $data = $row->jsonSerialize();
                if (is_array($data) === true) {
                    return $data;
                }
            }

            return (array) $row;
        }

        return [];

    }//end normaliseCounter()

    /**
     * Resolve the configured counter register id, or null when unset.
     *
     * @return string|null The register id.
     *
     * @spec exclude Config accessor for the counter register id; pure plumbing.
     */
    public function getRegisterId(): ?string
    {
        $value = (string) $this->appConfig->getValueString('opencatalogi', self::CONFIG_REGISTER, '');
        return ($value !== '' ? $value : null);

    }//end getRegisterId()

    /**
     * Resolve the configured counter schema id, or null when unset.
     *
     * @return string|null The schema id.
     *
     * @spec exclude Config accessor for the counter schema id; pure plumbing.
     */
    public function getSchemaId(): ?string
    {
        $value = (string) $this->appConfig->getValueString('opencatalogi', self::CONFIG_SCHEMA, '');
        return ($value !== '' ? $value : null);

    }//end getSchemaId()

}//end class
