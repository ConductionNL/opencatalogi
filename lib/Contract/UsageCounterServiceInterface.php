<?php

/**
 * Contract of UsageCounterService, for callers in other components.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
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

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

/**
 * Contract of UsageCounterService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface UsageCounterServiceInterface {
	/**
	 * Counter kind: a public publication file download.
	 *
	 * @var string
	 */
	public const KIND_DOWNLOAD = 'download';

	/**
	 * Counter kind: a public publication-detail view.
	 *
	 * @var string
	 */
	public const KIND_VIEW = 'view';

	/**
	 * Resolve the OpenRegister ObjectService, or null when it is unavailable.
	 *
	 * @return \OCA\OpenRegister\Service\ObjectService|null The ObjectService, or null.
	 *
	 * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
	 *       ObjectService from the container; pure framework plumbing, no domain behaviour.
	 */
	public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService;

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
	 * @spec openspec/specs/publication-usage-analytics/spec.md
	 */
	public function isCrawler(?string $userAgent): bool;

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
	 * @param string $publicationId The publication UUID being counted.
	 * @param string $kind KIND_VIEW or KIND_DOWNLOAD.
	 * @param string|null $userAgent Request UA, evaluated for crawler-skip only.
	 * @param string|null $catalog Optional catalog slug for roll-up labelling.
	 *
	 * @return boolean True when a count was recorded; false when skipped/failed.
	 *
	 * @spec openspec/specs/publication-usage-analytics/spec.md
	 */
	public function increment(
		string $publicationId,
		string $kind,
		?string $userAgent = null,
		?string $catalog = null,
	): bool;

	/**
	 * Fetch all counter objects for a publication within a date range.
	 *
	 * @param string $publicationId Publication UUID.
	 * @param string|null $from Inclusive start day (Y-m-d), or null.
	 * @param string|null $to Inclusive end day (Y-m-d), or null.
	 *
	 * @return array<int, array<string, mixed>> Normalised counter rows.
	 *
	 * @spec openspec/specs/publication-usage-analytics/spec.md
	 */
	public function getCountersForPublication(string $publicationId, ?string $from = null, ?string $to = null): array;

	/**
	 * Fetch all counter objects for a catalog within a date range.
	 *
	 * @param string $catalog Catalog slug (roll-up label on the counter).
	 * @param string|null $from Inclusive start day (Y-m-d), or null.
	 * @param string|null $to Inclusive end day (Y-m-d), or null.
	 *
	 * @return array<int, array<string, mixed>> Normalised counter rows.
	 *
	 * @spec openspec/specs/publication-usage-analytics/spec.md
	 */
	public function getCountersForCatalog(string $catalog, ?string $from = null, ?string $to = null): array;

	/**
	 * Fetch every counter object on the instance within a date range.
	 *
	 * Instance-wide reads feed the dashboard usage card: all publications, all
	 * catalogs. The rows are the same privacy-safe aggregates as the per-object
	 * queries (publication, date, kind, count), never request data.
	 *
	 * @param string|null $from Inclusive start day (Y-m-d), or null.
	 * @param string|null $to Inclusive end day (Y-m-d), or null.
	 *
	 * @return array<int, array<string, mixed>> Normalised counter rows.
	 *
	 * @spec openspec/specs/publication-usage-analytics/spec.md#requirement-dashboard-usage-card-shows-publication-views-and-downloads-ana-009
	 */
	public function getCountersForInstance(?string $from = null, ?string $to = null): array;

	/**
	 * Compute per-publication statistics: totals plus a daily timeseries.
	 *
	 * Pure aggregation over counter rows (no SQL) — the result is privacy-safe
	 * by construction because the inputs hold only (publication, date, kind,
	 * count). Returns the counting-start date so callers can distinguish "zero
	 * views" from "not yet measured" (ANA-004).
	 *
	 * @param string $publicationId Publication UUID.
	 * @param string|null $from Inclusive start day (Y-m-d), or null.
	 * @param string|null $to Inclusive end day (Y-m-d), or null.
	 *
	 * @return array{views:int,downloads:int,series:array<int,array{date:string,views:int,downloads:int}>,countingStart:?string}
	 *
	 * @spec openspec/specs/publication-usage-analytics/spec.md
	 */
	public function getPublicationStats(string $publicationId, ?string $from = null, ?string $to = null): array;

	/**
	 * Compute catalog roll-up: totals plus top-N publications by views/downloads.
	 *
	 * @param string $catalog Catalog slug.
	 * @param string|null $from Inclusive start day (Y-m-d), or null.
	 * @param string|null $to Inclusive end day (Y-m-d), or null.
	 * @param integer $top Number of top publications to return (default 10).
	 *
	 * @return array{views:int,downloads:int,topViewed:array<int,array{publication:string,views:int,downloads:int}>,topDownloaded:array<int,array{publication:string,views:int,downloads:int}>}
	 *
	 * @spec openspec/specs/publication-usage-analytics/spec.md
	 */
	public function getCatalogStats(string $catalog, ?string $from = null, ?string $to = null, int $top = 10): array;

	/**
	 * Compute the instance-wide daily usage series for the dashboard card.
	 *
	 * Sums every counter object per day into one series of publication views
	 * and file downloads. The window defaults to the last 30 days (inclusive,
	 * UTC). The counting-start marker is taken from the FULL counter set, not
	 * the window, so a card showing an empty month can still say when
	 * measurement began (ANA-004 "zero" versus "not measured").
	 *
	 * These are request counts, never unique visitors, and they cover only the
	 * public publication and download reads that the usage counters record.
	 * Page views on a Portaliq-served portal are measured by Portaliq's traffic
	 * analytics, not here.
	 *
	 * @param string|null $from Inclusive start day (Y-m-d); null means 29 days before `$to`.
	 * @param string|null $to Inclusive end day (Y-m-d); null means today (UTC).
	 *
	 * @return array{views:int,downloads:int,series:array<int,array{date:string,views:int,downloads:int}>,countingStart:?string,from:string,to:string}
	 *
	 * @spec openspec/specs/publication-usage-analytics/spec.md#requirement-dashboard-usage-card-shows-publication-views-and-downloads-ana-009
	 */
	public function aggregateInstanceSeries(?string $from = null, ?string $to = null): array;

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
	 * @spec openspec/specs/publication-usage-analytics/spec.md
	 */
	public function aggregateSeries(array $rows): array;

	/**
	 * Aggregate catalog counter rows into totals and top-N rankings.
	 *
	 * Pure function (no OR dependency) for independent unit testing of the
	 * roll-up and ranking maths (ANA-005).
	 *
	 * @param array<int, array<string, mixed>> $rows Counter rows.
	 * @param integer $top Number of top entries (>= 1).
	 *
	 * @return array{views:int,downloads:int,topViewed:array<int,array{publication:string,views:int,downloads:int}>,topDownloaded:array<int,array{publication:string,views:int,downloads:int}>}
	 *
	 * @spec openspec/specs/publication-usage-analytics/spec.md
	 */
	public function aggregateCatalog(array $rows, int $top = 10): array;

	/**
	 * Resolve the configured counter register id, or null when unset.
	 *
	 * @return string|null The register id.
	 *
	 * @spec exclude Config accessor for the counter register id; pure plumbing.
	 */
	public function getRegisterId(): ?string;

	/**
	 * Resolve the configured counter schema id, or null when unset.
	 *
	 * @return string|null The schema id.
	 *
	 * @spec exclude Config accessor for the counter schema id; pure plumbing.
	 */
	public function getSchemaId(): ?string;
}//end interface
