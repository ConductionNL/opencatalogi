---
status: needs-rewrite
or_dep: zoeken-filteren
audit_ref: .claude/audit-2026-05-03/02-spec-rewrite.md
---

# Search

> **NEEDS-REWRITE notice:** This spec was rewritten as part of
> `opencatalogi-adopt-or-abstractions` (Phase 7). The bespoke query
> parsing, faceting, ranking, and filter-generation logic described in
> the previous version of this spec is replaced by a citation of OR's
> `zoeken-filteren` capability. opencatalogi's search surface is now a
> thin orchestrator on top of `zoeken-filteren`. See the REMOVED section.
>
> Upstream dependency: OR `zoeken-filteren` capability.

## Purpose

@e2e exclude OR-abstraction-consumer spec — query parsing/faceting/ranking delegated to OpenRegister's `zoeken-filteren`, verified by PHPUnit/vitest/Newman; the search UI is separately real-UI covered.

opencatalogi's search capability aggregates publications from local
catalogs and federated remote OpenCatalogi instances into a single
search interface. After Phase 7, the query parsing, faceting, and
ranking are owned by OR's `zoeken-filteren` capability; opencatalogi
is responsible only for:

1. Fanning out parallel `zoeken-filteren` calls across all configured
   catalog contexts (local + federated).
2. Merging the ranked result sets in a documented, stable order.
3. Merging facet buckets across sources by `_id`.

opencatalogi MUST NOT re-implement query string parsing, bracket-notation
parsing, facet generation, score computation, or pagination arithmetic.
Those are owned upstream by OR `zoeken-filteren`.

## Requirements

### Requirement: single-catalog search delegates to OR `zoeken-filteren` (SCH-OR-001)

When a user issues a search query within a single catalog, opencatalogi MUST delegate the full query — including `_search`, `_order`, `_limit`,
`_page`, `_offset`, `_filters`, and any `_facetable` / `_aggregate` flags
— to OR's `zoeken-filteren` API unmodified. opencatalogi MUST NOT alter
the query, re-parse bracket notation, or inject custom filter parameters.

> @e2e exclude Backend query-passthrough contract (full query delegated to OR `zoeken-filteren` unmodified; no local filter transformation or score adjustment) — a server-side delegation with no UI surface; verified by PHPUnit/Newman asserting the constructed call and unchanged response. The search UI itself is already real-UI covered under search::run-a-publication-search.

#### Scenario: single-catalog search passes through

- **WHEN** a user issues a search query within a single catalog,
- **THEN** opencatalogi constructs a single `zoeken-filteren` call for
  that catalog context,
- **AND** returns the OR response to the caller unchanged,
- **AND** does NOT apply local filter transformation or score adjustment.

### Requirement: federated search is a thin orchestrator (SCH-OR-002)

When a user issues a federated search across N catalogs or remote directories, opencatalogi MUST act as a thin orchestrator that:

1. Makes N parallel `zoeken-filteren` calls (one per catalog context
   / remote endpoint) using async HTTP.
2. Merges the result arrays in a stable, documented order (descending
   `_score`; ties broken by source order declared in the active listings).
3. Merges facet buckets by `_id`; counts are summed across sources.
4. Returns a single paginated response whose `total` is the sum of all
   source totals.

opencatalogi MUST NOT alter individual ranking scores, re-rank within
a source's result set, or apply cross-source deduplication beyond
merging on `_id` equality.

> @e2e exclude Backend federation-orchestration contract (N parallel `zoeken-filteren` calls; stable descending-_score merge; facet buckets summed by _id; total = sum of source totals; no re-ranking) — server-side merge math with no UI surface; verified by PHPUnit/Newman over the orchestrator with seeded multi-source responses. The federated search UI is already real-UI covered under search::run-a-publication-search and ::toggle-a-facet-from-the-ui.

#### Scenario: cross-catalog search merges OR results

- **WHEN** a user issues a federated search across N catalogs,
- **THEN** opencatalogi makes N parallel `zoeken-filteren` calls,
- **AND** merges the results in stable descending `_score` order,
- **AND** does NOT alter individual ranking scores.

#### Scenario: facet merging

- **GIVEN** catalog A returns `{theme: [{_id: "milieu", count: 5}]}`
  and catalog B returns `{theme: [{_id: "milieu", count: 3}, {_id: "energie", count: 2}]}`,
- **WHEN** the federated results are merged,
- **THEN** the response contains `{theme: [{_id: "milieu", count: 8}, {_id: "energie", count: 2}]}`.

### Requirement: internal search endpoint delegates to `zoeken-filteren` (SCH-OR-003)

The `SearchController::index` (`GET /api/search`) MUST delegate to OR's
`zoeken-filteren` with the `publications` context. It MUST NOT call a
bespoke `buildSearchQuery()` or `searchObjectsPaginated()` method in
opencatalogi itself.

> @e2e exclude Backend controller-delegation contract (`SearchController::index` delegates to `zoeken-filteren` with the publications context, no bespoke buildSearchQuery/searchObjectsPaginated) — a server endpoint with no UI surface; verified by PHPUnit/Newman over `GET /api/search`.

#### Scenario: internal endpoint delegates

- **GIVEN** an authenticated request to `GET /api/search`,
- **WHEN** `SearchController::index` runs,
- **THEN** it calls `zoeken-filteren` with the `publications` context
  and the caller's query parameters,
- **AND** returns the OR response.

### Requirement: search frontend store calls the federation endpoint (SCH-OR-004)

The frontend search store MUST query publications via the federation
endpoint `GET /api/federation/publications`, building query parameters
from the current search term, pagination, active filters, ordering, and
the federation flags `_facetable=true`, `_aggregate=true`. The federation
endpoint in turn calls `zoeken-filteren` per catalog context. The frontend
store MUST NOT call OR's `zoeken-filteren` endpoint directly.

> @e2e exclude Frontend network-target contract (search store queries `/api/federation/publications` with the term/pagination/filters/order + `_facetable`/`_aggregate` flags, never calling `zoeken-filteren` directly) — the assertion is the request target/params, not a distinct browsable surface; verified by vitest mocking the federation endpoint and asserting the URL + params. The search UI is already real-UI covered under search::run-a-publication-search.

#### Scenario: frontend search runs through the federation endpoint

- **GIVEN** a search term and optional filters,
- **WHEN** the search store runs a search,
- **THEN** it sends the request to `/api/federation/publications`,
- **AND** does NOT call `zoeken-filteren` directly from the frontend.

### Requirement: facet discovery and active-facet query building (SCH-OR-005)

The search frontend MUST provide facet discovery (`discoverFacetableFields()`)
and active-facet encoding (`buildFacetQuery()`). These translate the user's
enabled facets into the `_facetable` / `_aggregate` parameters on the
`zoeken-filteren` call — they do NOT compute facet buckets locally.

#### Scenario: discover facetable fields

- **GIVEN** the search view loads,
- **WHEN** `discoverFacetableFields()` runs,
- **THEN** the facetable-fields map is populated from the OR response's
  `facetable` metadata.

### Requirement: search UI components (SCH-OR-006)

opencatalogi MUST provide a `SearchSideBar` (facet filter controls),
a `SearchResults` component (result list), and a `FacetComponent`
(individual facet toggle). These components render the OR `zoeken-filteren`
response; they do NOT contain local filter computation.

#### Scenario: search UI renders OR results without local computation

- **GIVEN** a `zoeken-filteren` response is returned to the search view,
- **WHEN** `SearchSideBar`, `SearchResults`, and `FacetComponent` render,
- **THEN** they MUST display the OR-provided results and facets,
- **AND** they MUST NOT compute filters, facet buckets, or scores locally.

## REMOVED Requirements

The following requirements described bespoke implementations that OR's
`zoeken-filteren` capability now owns. They are retained for traceability;
implementation MUST NOT re-introduce them.

| ID | Title | Reason removed |
|----|-------|----------------|
| SCH-010 | Create MySQL/MongoDB-compatible search filters and sort parameters | REMOVED — re-implements OR's `zoeken-filteren` query layer; consume OR per ADR-022. OpenRegister's `ObjectService::buildSearchQuery()` is the authoritative implementation. |
| SCH-013 | Generate dual MySQL and MongoDB filter/sort parameters | REMOVED — same rationale as SCH-010. |
| SCH-014 | Parse complex nested query strings with bracket notation | REMOVED — bracket notation parsing (`_order[title]=asc`, `themes[or]=1,2,3`) is owned by OR `zoeken-filteren`; opencatalogi MUST NOT re-implement or duplicate it. |
| SCH-015 | Unset underscore-prefixed special parameters before passing to database layer | REMOVED — owned by OR `zoeken-filteren`; opencatalogi passes the raw query to OR unmodified. |

SCH-001 through SCH-009, SCH-011, SCH-012, SCH-016 through SCH-020 are superseded
by SCH-OR-001 through SCH-OR-006. The observable behaviours they describe are
preserved; the implementation path now routes through `zoeken-filteren`.

## Breaking Changes

| Breaking change | Old behaviour | New behaviour |
|---|---|---|
| Local `buildSearchQuery()` removed | opencatalogi applied bracket-notation parsing before calling OR | Raw query parameters forwarded to OR `zoeken-filteren`; OR parses them. Operators or clients relying on opencatalogi-side parsing must verify compatibility with the OR implementation. |
| `ElasticSearch` integration removed | SCH-006 noted "not implemented"; any future bespoke ElasticSearch integration is forbidden | Search goes through OR `zoeken-filteren`; OR owns the storage backend choice. |

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/search` | Internal search — delegates to OR `zoeken-filteren` with the `publications` context (authenticated) |

## References

- OR `zoeken-filteren` capability (upstream dependency)
- `.claude/audit-2026-05-03/02-spec-rewrite.md` (Stream 2 MISSING-OR-DEP rationale)
- `openspec/changes/opencatalogi-adopt-or-abstractions/` (Phase 7 implementation change)
- `openspec/specs/federation/spec.md` (federated search orchestration)
- ADR-022 — Apps consume OR abstractions
