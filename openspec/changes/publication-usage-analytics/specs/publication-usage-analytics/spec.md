---
status: draft
---

# publication-usage-analytics Specification

## Purpose
Provide publication officers with privacy-safe, per-publication reach
statistics — daily aggregate view and download counts, catalog roll-ups,
top-N lists, and a CSV export suitable for WOO annual reporting — counted at
the public API read paths where every frontend, federated peer, and direct
download converges. Counters hold `(publication, date, kind, count)` and
nothing else: no IP addresses, user agents, cookies, sessions, or any
personal data are ever stored. Storage, RBAC, and querying are OpenRegister
(counter objects in a `usageCounter` schema — no bespoke tables, hydra
ADR-022); operational totals extend the existing `prometheus-metrics`
endpoint rather than adding a second one.

## Context
`prometheus-metrics` exposes operational totals (publication/catalog/listing
counts, search volume, federation health) for ops dashboards — it cannot say
which publications are reached, and Prometheus label cardinality forbids
per-publication metrics there. External web analytics sees only a frontend,
misses API/federated consumers, and raises privacy questions. CKAN and
data.overheid.nl ship per-dataset view/download statistics as a baseline
capability of the category.

**Relation to existing specs:**
- `publications` PUB-002 (public detail fetch) and PUB-007 +
  `download-service` (public file download): the two — and only two —
  counted reach signals.
- `prometheus-metrics`: extended with catalog-labelled view/download totals;
  its endpoint, format, and performance requirements are unchanged.
- `dashboard` / `retrofit-2026-05-26-dashboard-widgets`: hosts the "most
  viewed" widget.
- `generic-object-modals` / detail-page widget surface: hosts the
  per-publication stats panel.
- `federation`: requests arriving from federated peers hit the same public
  endpoints and are counted as ordinary requests; cross-instance counter
  aggregation is out of scope.

## ADDED Requirements

### Requirement: Count public publication views and file downloads (ANA-001)
The system MUST increment a view counter on each successful public
publication-detail response (PUB-002) and a download counter on each
successful public file download (PUB-007 / download-service). Counting MUST
be fire-and-forget: a counting failure MUST be logged and swallowed and MUST
never fail, slow beyond negligible overhead, or alter the public response
(the APB-014 "never break the originating operation" posture). The following
MUST NOT be counted: non-2xx responses, `304 Not Modified` responses, HEAD
requests, list-endpoint hits (PUB-001), and authenticated back-office reads
through the internal UI.

#### Scenario: Public detail view is counted
<!-- @e2e exclude Server-side counting hook on the public API read path; verified by PHPUnit (UsageCounterService) + Newman, not drivable through the officer UI. -->
- GIVEN a publicly visible publication
- WHEN an anonymous client fetches its public detail endpoint successfully
- THEN the publication's view counter for today MUST increase by 1

#### Scenario: Failed fetch is not counted
<!-- @e2e exclude Server-side guard (non-2xx not counted); asserted by PHPUnit + Newman, no officer-facing UI. -->
- GIVEN a request for a non-existent publication returning `404`
- WHEN the response is sent
- THEN no counter MUST be incremented

#### Scenario: Counter failure never breaks a download
<!-- @e2e exclude Fire-and-forget failure-swallow behaviour on the server; covered by PHPUnit (increment swallows failure), not a UI surface. -->
- GIVEN the counter write path is failing (e.g. OR temporarily unavailable
  for writes)
- WHEN an anonymous client downloads a publication file
- THEN the download MUST succeed normally
- AND the failure MUST be logged, not propagated

#### Scenario: Back-office reads are not reach
<!-- @e2e exclude Server-side scoping (count hooks only on public read paths, not internal endpoints); verified by code review + PHPUnit, no dedicated UI. -->
- GIVEN a publication officer opens a publication in the authenticated admin
  UI
- WHEN the internal endpoints serve the object
- THEN no view counter MUST be incremented

### Requirement: Aggregate-only, privacy-safe counter storage in OR (ANA-002)
Usage data MUST be stored exclusively as daily aggregate counter objects in
OpenRegister — a `usageCounter` schema with fields `publication` (reference),
`date` (day), `kind` (enum: `view`, `download`, optionally per-file
`downloadFile` reference), and `count` (non-negative integer) — and nothing
else. No IP address, user agent, session identifier, referrer, or any other
request attribute MUST ever be persisted. The schema MUST be internal
plumbing: never published (`@self.published` never set), excluded from
catalogs, sitemaps, DCAT, search, and federation, and RBAC-restricted via OR
to the app and officer roles. OpenCatalogi MUST NOT create bespoke database
tables for counters (hydra ADR-022).

#### Scenario: Counter object shape
<!-- @e2e exclude Privacy invariant on the stored OR object; asserted by PHPUnit (stored counter contains no request data), not a UI surface. -->
- GIVEN a counted view
- WHEN today's counter object for that publication is inspected in OR
- THEN it MUST contain only publication reference, date, kind, and count
- AND no request-derived attribute beyond those fields

#### Scenario: Counters are invisible on public surfaces
<!-- @e2e exclude Schema configured as internal (searchable:false, no public read, excluded from DCAT/sitemap/federation); enforced by OR RBAC + the schema seed, verified via Newman on the public surfaces. -->
- GIVEN counter objects exist
- WHEN public publications API, search, sitemap, DCAT, and federation
  surfaces are queried
- THEN no counter object MUST appear on any of them

#### Scenario: One object per publication per day per kind
<!-- @e2e exclude Daily upsert/aggregation behaviour in the counting service; covered by PHPUnit (increment bumps existing counter), no UI. -->
- GIVEN 50 views of one publication on one day
- WHEN the day ends
- THEN exactly one `view` counter object MUST exist for that
  (publication, day) with `count: 50` (small lost-update tolerance from
  concurrent increments is acceptable and documented)

### Requirement: Best-effort bot filtering without storing request data (ANA-003)
At count time the system MUST skip increments for requests whose user agent
matches a maintained known-crawler list (search engines, monitoring probes,
the DCAT/sitemap harvesters themselves). The user agent MUST be evaluated in
memory only and discarded — it MUST NOT be stored, hashed, or logged as part
of counting. The crawler list MUST be maintainable without a code release
(config-backed, with a shipped default).

#### Scenario: Crawler download not counted
<!-- @e2e exclude In-memory crawler filtering at count time; asserted by PHPUnit (increment skipped for crawler), no UI. -->
- GIVEN a request with user agent `Googlebot/2.1`
- WHEN it downloads a publication file successfully
- THEN no download counter MUST be incremented

#### Scenario: User agent is not persisted
<!-- @e2e exclude Privacy invariant (UA never stored/logged); asserted by PHPUnit (counter + failure-log contain no user agent), no UI. -->
- GIVEN any counted or skipped request
- WHEN counter objects and application logs from the counting path are
  inspected
- THEN the user-agent value MUST NOT appear in either

### Requirement: Authenticated per-publication statistics API (ANA-004)
The system MUST expose an authenticated endpoint
`GET /api/publications/{id}/stats` returning, for a requested date range and
granularity (day/week/month), the view and download timeseries and totals
for that publication, computed by querying/aggregating the counter objects
through OR object search — no bespoke SQL. The endpoint MUST NOT be public
in this change, and MUST be authorization-checked so only users permitted on
the underlying publication (per OR RBAC) can read its stats. The response
MUST include the counting-start date so consumers can distinguish "zero
views" from "not yet measured".

#### Scenario: Officer fetches a publication's reach
<!-- @e2e exclude Stats API response shape (timeseries + totals + counting-start); asserted by PHPUnit (StatsController) + Newman, the UI rendering of it is covered by the stats-panel scenario. -->
- GIVEN a publication with 120 counted views and 40 downloads in May 2026
- WHEN an authorized officer requests its stats for May with `granularity=day`
- THEN the response MUST contain per-day series summing to 120 views and 40
  downloads, plus the totals and the counting-start date

#### Scenario: Anonymous request rejected
<!-- @e2e exclude Server-side auth posture (endpoint not public); enforced by the NC route auth annotation + asserted via Newman, no UI. -->
- GIVEN an unauthenticated request to a stats endpoint
- WHEN it is processed
- THEN it MUST be rejected (this surface is not public in this change)

#### Scenario: Unauthorized user cannot read stats (no IDOR)
<!-- @e2e exclude Per-object authorization (no-IDOR) on the server; asserted by PHPUnit (stats denied for unauthorized user) + Newman, no UI. -->
- GIVEN an authenticated user without access to publication X
- WHEN they request `GET /api/publications/X/stats`
- THEN the request MUST be denied by the same authorization rule that
  governs the publication itself

### Requirement: Catalog roll-ups and top-N (ANA-005)
The system MUST expose an authenticated endpoint
`GET /api/catalogs/{slug}/stats` returning, for a requested period: total
views and downloads for the catalog, and the top-N most viewed and most
downloaded publications (default N=10), aggregated from the counter objects
via OR queries.

#### Scenario: Top-10 of a catalog
<!-- @e2e exclude Top-N ranking maths in the aggregation service; asserted by PHPUnit (aggregateCatalog top-N ranking), surfaced in the most-viewed widget scenario. -->
- GIVEN a catalog with 200 publications with varying counted usage
- WHEN an officer requests the catalog stats for the last 30 days
- THEN the response MUST contain catalog totals and the 10 publications with
  the highest view counts, each with its own totals

#### Scenario: Period without data
<!-- @e2e exclude Server-side zeros-plus-counting-start response for empty periods; asserted by PHPUnit (aggregateSeries empty) + Newman, no dedicated UI. -->
- GIVEN a period before counting started
- WHEN catalog stats are requested for it
- THEN the response MUST return zeros together with the counting-start date
  (not an error)

### Requirement: Publisher-facing UI — stats panel and dashboard widget (ANA-006)
The publication detail page (authenticated UI) MUST show a stats panel with
the publication's views/downloads (sparkline or simple series + totals +
counting-start note), and the dashboard MUST offer a "most viewed
publications" widget (top-N for a selectable period, deep-linking to the
publications). Both MUST be built on the existing detail-widget and
dashboard-widget surfaces — no new view framework.

#### Scenario: Stats panel on the detail page
- GIVEN a publication with counted usage
- WHEN an officer opens its detail page
- THEN the stats panel MUST show view/download totals and a recent trend
- AND indicate the counting-start date when the publication predates it

#### Scenario: Most-viewed dashboard widget
- GIVEN counted usage across catalogs
- WHEN the officer views the dashboard with the widget configured for
  "last 30 days"
- THEN the widget MUST list the top publications by views with their counts
- AND clicking an entry MUST open that publication

### Requirement: CSV export for WOO reporting (ANA-007)
The system MUST provide an authenticated CSV export (UTF-8 with BOM) per
catalog and date range: one row per publication with columns Publication,
Category, Published date, Views, Downloads — derived entirely from counter
objects; no separate reporting store. Multiple-month ranges MUST be
supported (a year for the WOO annual report).

#### Scenario: Annual report export
<!-- @e2e exclude CSV byte-level contract (UTF-8 BOM, columns, zero-usage rows); asserted by PHPUnit (buildCsv BOM + columns) + Newman, file-download not a render surface. -->
- GIVEN catalog "woo-besluiten" with counted usage during 2026
- WHEN the officer exports the usage CSV for 2026
- THEN the CSV MUST contain one row per publication that was publicly
  visible in the period, with the columns above
- AND publications with zero usage MUST appear with zeros (reach reporting
  needs the unread ones most)

### Requirement: Prometheus totals extended, not duplicated (ANA-008)
Catalog-level usage totals MUST be added to the existing `GET /api/metrics`
output as two metric families — `opencatalogi_publication_views_total` and
`opencatalogi_file_downloads_total`, labelled by catalog (slug) only — per
the prometheus-metrics spec's naming and performance requirements.
Per-publication labels MUST NOT be emitted (cardinality), and no second
metrics endpoint MUST be introduced.

#### Scenario: Metrics endpoint exposes usage families
<!-- @e2e exclude Prometheus text-format scrape output; asserted by Newman against /api/metrics + PHPUnit on the metrics controller, not a render surface. -->
- GIVEN counted usage exists
- WHEN `GET /api/metrics` is scraped
- THEN both new families MUST appear in valid Prometheus text format with
  one series per catalog
- AND no series MUST carry a per-publication label

## Non-Requirements
- This spec does NOT provide unique-visitor, session, referrer, funnel, or
  any visitor-level analytics — rejected by design (would require personal
  data), not deferred. Counts are requests, not people, and the UI states
  so.
- This spec does NOT make any usage figures publicly visible — publishing
  reach numbers is a policy decision deferred to a future change.
- This spec does NOT aggregate usage across federated instances — each
  instance counts what it serves; cross-instance roll-ups are a future
  change on the federation surface.
- This spec does NOT count search queries or list-endpoint hits.
- This spec does NOT build bespoke storage, SQL, or a second metrics
  endpoint (ADR-022; prometheus-metrics is extended).

## Dependencies
- OpenRegister: `usageCounter` schema on the OpenCatalogi register; object
  search/facets for all aggregation; RBAC on the counter schema and on the
  per-publication stats authorization (ADR-022).
- `publications` PUB-002 and PUB-007 / `download-service` — the counted
  public read paths (count hooks live at these seams).
- `prometheus-metrics` — hosts the extended metric families
  (naming/performance requirements apply).
- Dashboard-widget and detail-widget surfaces (dashboard +
  retrofit-2026-05-26-dashboard-widgets, generic-object-modals/detail) —
  host the UI.
- `cross-origin-api-access` — unchanged; stats endpoints are authenticated
  and not CORS-public.

### Current Implementation Status
- **Not yet implemented**: no counting hooks, counter schema, stats
  endpoints, UI surfaces, export, or usage metric families exist.
- **Building blocks that exist**: the two public read paths
  (PublicationsController detail + download/DownloadService),
  MetricsController with Prometheus text output and catalog labels
  (prometheus-metrics), dashboard widget infrastructure, CSV export
  precedent (inventarislijst pattern in `woo-transparency`), OR object
  search/facets for the aggregation queries.
- **Known constraints to respect**: download responses may stream — the
  count hook must not buffer or delay streaming (DWN-OR-001); increment
  lost-update tolerance documented in ANA-002 until OR offers an atomic
  increment.
