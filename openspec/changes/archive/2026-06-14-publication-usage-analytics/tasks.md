# Tasks: publication-usage-analytics

Counting is the only genuinely in-app part; storage/RBAC/aggregation are OR
(hydra ADR-022), ops totals extend the existing prometheus-metrics endpoint,
and all UI lands on existing widget surfaces. Privacy is a hard constraint:
counters are `(publication, date, kind, count)` and nothing else, ever.

## Task 1: Implementation planning
- **Spec ref**: specs/publication-usage-analytics/spec.md
- **Status**: done
- **Decisions taken**: counter write path = in-request deferred upsert
  (fire-and-forget, swallow + log on failure; swap-ready for a future OR atomic
  increment); stats visibility = catalog editors + admins via OR RBAC on the
  `usageCounter` schema (read restricted to `admin`/officer roles) plus a
  per-object authorization check on `GET /api/publications/{id}/stats` matching
  the publication's own RBAC (no IDOR).
- **Acceptance criteria**: Requirements decomposed respecting the
  consume-vs-build split; counter write path and stats-visibility role
  confirmed.

## Task 2: `usageCounter` schema + counting service
- **Spec ref**: specs/publication-usage-analytics/spec.md — ANA-001,
  ANA-002, ANA-003
- **Status**: done
- **Acceptance criteria**:
  - `usageCounter` schema (publication, date, kind, count) registered on the
    OpenCatalogi register; internal plumbing: never published, excluded from
    catalogs/sitemaps/DCAT/search/federation, OR-RBAC-restricted. NO bespoke
    tables.
  - `UsageCounterService.increment()` upserts the daily counter; documented
    lost-update tolerance; swap-ready for a future OR atomic increment.
  - Count hooks on PUB-002 detail and PUB-007/download paths only,
    fire-and-forget (log + swallow), skipping non-2xx, 304, HEAD,
    list-endpoint, and authenticated back-office reads; streaming downloads
    not buffered or delayed (DWN-OR-001).
  - Config-backed known-crawler list (shipped default) evaluated in memory;
    user agent never stored, hashed, or logged from the counting path.

## Task 3: Stats API (per-publication + catalog roll-ups)
- **Spec ref**: specs/publication-usage-analytics/spec.md — ANA-004, ANA-005
- **Status**: done
- **Acceptance criteria**:
  - `GET /api/publications/{id}/stats` (range + granularity, timeseries +
    totals + counting-start date) and `GET /api/catalogs/{slug}/stats`
    (totals + top-N), both authenticated, both aggregating via OR object
    search — no bespoke SQL.
  - Per-object authorization on publication stats matches the publication's
    own OR RBAC rule (no IDOR — same-guard requirement as gate
    no-admin-idor).
  - Routes registered with correct auth posture annotations.

## Task 4: UI — detail stats panel + most-viewed dashboard widget
- **Spec ref**: specs/publication-usage-analytics/spec.md — ANA-006
- **Status**: done
- **Acceptance criteria**:
  - Stats panel on the publication detail page (totals + trend +
    counting-start note) on the existing detail-widget surface.
  - "Most viewed publications" dashboard widget (period-selectable top-N,
    deep links) on the existing dashboard-widget infrastructure.
  - Playwright UI coverage for both surfaces; all API assertions in Newman
    (Playwright UI-only / Newman API rule); en + nl strings via the l10n
    tooling.

## Task 5: CSV export
- **Spec ref**: specs/publication-usage-analytics/spec.md — ANA-007
- **Status**: done
- **Acceptance criteria**:
  - Authenticated per-catalog, per-range CSV (UTF-8 BOM; Publication,
    Category, Published date, Views, Downloads), zero-usage publications
    included; derived purely from counter objects.

## Task 6: Prometheus extension
- **Spec ref**: specs/publication-usage-analytics/spec.md — ANA-008
- **Status**: done
- **Acceptance criteria**:
  - `opencatalogi_publication_views_total` and
    `opencatalogi_file_downloads_total` (catalog label only) added to the
    existing `/api/metrics`, meeting prometheus-metrics naming + performance
    requirements; no per-publication labels; no second endpoint.
  - Newman assertions on the extended scrape output.

## Task 7: Privacy verification + docs
- **Spec ref**: specs/publication-usage-analytics/spec.md — ANA-002, ANA-003
- **Status**: partial
- **Acceptance criteria**:
  - [x] Tests prove counter objects and the counting-path failure log contain
    no request-derived attributes beyond (publication, date, kind, count):
    `UsageCounterServiceTest::testStoredCounterContainsNoRequestData` and
    `::testCounterFailureLogDoesNotContainUserAgent`.
  - [x] In-product honest-limitation note: the detail stats panel states
    "Counts are requests, not unique visitors." and surfaces the
    counting-start caveat (`countingStartNote`).
  - [ ] Standalone README/docs page deferred — the in-product notes + spec
    cover the user-facing caveat; a docs page can follow in a sync-docs pass.
