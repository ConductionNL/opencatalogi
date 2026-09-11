---
status: reviewed
---

# Search

## Purpose

The search feature provides an internal search API endpoint that queries publications across all available catalogs. Unlike the public publication endpoints (scoped by catalog slug), the internal search endpoint is for authenticated Nextcloud users and administrative purposes. The `SearchController` delegates to `PublicationService` for all search operations. Note: There is no separate `SearchService` or `ElasticSearchService` class in the OpenCatalogi codebase -- all search and federation logic is handled by `PublicationService`.

## Requirements

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| SCH-001 | Provide an internal search endpoint at `/api/search` for authenticated users | Must | Implemented |
| SCH-002 | Support full-text search via `_search` parameter | Must | Implemented |
| SCH-003 | Support filtering by catalog ID | Should | Implemented |
| SCH-004 | Support pagination (_limit, _page, _offset) | Must | Implemented |
| SCH-005 | Support ordering (_order) | Must | Implemented |
| SCH-006 | Integrate with ElasticSearch when configured | Should | Not Implemented (no ElasticSearchService in OpenCatalogi) |
| SCH-007 | Support distributed search across remote directories via async HTTP | Should | Implemented (via PublicationService federation) |
| SCH-008 | Merge facets/aggregations from multiple sources | Should | Implemented (via PublicationService federation) |
| SCH-009 | Parse complex query strings with nested parameters | Should | Implemented (via ObjectService.buildSearchQuery) |
| SCH-010 | Create MySQL/MongoDB-compatible search filters and sort parameters | Must | Not Applicable (no SearchService exists -- search uses OpenRegister's ObjectService directly) |
| SCH-011 | SearchController has show(), attachments(), download(), uses(), used() methods with no routes | Nice | Dead Code |
| SCH-012 | Support filter syntax with special query parameters (_search, _order, _limit, _page, _offset, _queries) | Must | Implemented |
| SCH-013 | Generate dual MySQL and MongoDB filter/sort parameters from request query parameters | Must | Not Applicable (no SearchService exists in OpenCatalogi) |
| SCH-014 | Parse complex nested query strings with bracket notation (e.g., `_order[title]=asc`, `themes[or]=1,2,3`) | Must | Implemented (via ObjectService.buildSearchQuery in OpenRegister) |
| SCH-015 | Unset all underscore-prefixed special parameters before passing to database filter layer | Must | Implemented (via ObjectService.buildSearchQuery in OpenRegister) |

## Data Model

Search does not have its own schema. It queries across publication objects from all catalogs.

Search response structure:

| Field | Type | Description |
|-------|------|-------------|
| results | array | Publication objects matching the search query |
| facets | object | Aggregation/facet data for filtering UI |
| count | integer | Number of results in current page |
| total | integer | Total matching results |
| limit | integer | Page size |
| page | integer | Current page |
| pages | integer | Total pages |

## User Interface

- **SearchIndex.vue** (`/search`) - Main search page with filters and results
- **SearchResults.vue** - Search results display component
- **SearchSideBar.vue** - Sidebar with facet filters
- **FacetComponent.vue** - Individual facet filter component

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/search` | Internal search across all catalogs (authenticated) |

Note: The search endpoint does NOT have CORS preflight routes, as it is intended for internal/authenticated use only.

## Dead Code: SearchController Extra Methods (Gap 10)

The `SearchController` (`lib/Controller/SearchController.php`) contains the following methods that have **no corresponding routes** in `routes.php`:

| Method | Signature | Delegates To | Status |
|--------|-----------|-------------|--------|
| `show(string $id)` | Get single publication | `PublicationService::show()` | **Dead Code** - No route registered |
| `attachments(string $id)` | Get publication attachments | `PublicationService::attachments()` | **Dead Code** - No route registered |
| `download(string $id)` | Download publication files | `PublicationService::download()` | **Dead Code** - No route registered |
| `uses(string $id)` | Get outgoing relations | `PublicationService::uses()` | **Dead Code** - No route registered |
| `used(string $id)` | Get incoming relations | `PublicationService::used()` | **Dead Code** - No route registered |

Only `SearchController::index()` has a route (`/api/search`). The other methods exist in the controller code and delegate to `PublicationService` but are completely unreachable via HTTP because no routes are defined for them. These likely represent planned features (an authenticated search detail API) that were never completed, or were superseded by the public `PublicationsController` endpoints.

## Filter Syntax and Special Query Parameters (Gap 20)

**Important**: There is no `SearchService` class in the OpenCatalogi codebase. The filter parsing, query building, and search infrastructure described below is provided by **OpenRegister's ObjectService** (`ObjectService::buildSearchQuery()`), not by OpenCatalogi itself. The SearchController delegates directly to `PublicationService`, which in turn uses OpenRegister's ObjectService for all search operations.

### Special Query Parameters

| Parameter | Purpose | Example |
|-----------|---------|---------|
| `_search` | Full-text search term | `?_search=klimaat` |
| `_order` | Sort order (field to direction map) | `?_order[title]=asc&_order[date]=desc` |
| `_limit` | Results per page (default: 20) | `?_limit=50` |
| `_page` | Current page number | `?_page=2` |
| `_offset` | Skip N results | `?_offset=20` |
| `_queries` | Fields to aggregate/facet | `?_queries[]=theme&_queries[]=organization` |
| `_catalogi` | Filter by catalog IDs | `?_catalogi[]=cat1&_catalogi[]=cat2` |

### Query Building (via OpenRegister ObjectService)

`ObjectService::buildSearchQuery()` handles:
- PHP dot-to-underscore conversion (`@self.register` to `@self_register`)
- Nested property conversion (`person.address.street` to `person_address_street`)
- System parameter extraction (removes `id`, `_route`, `rbac`, `multi`, `published`, `deleted`)
- Bracket notation parsing (e.g., `_order[title]=asc`, `themes[or]=1,2,3`)

The actual search, filter generation, and pagination is handled internally by OpenRegister's `searchObjectsPaginated()` method, which supports both magic table (SQL) and blob storage backends.

## Scenarios

### Scenario: Internal publication search
- GIVEN catalogs with publications exist
- WHEN an authenticated user sends GET `/api/search?_search=klimaat`
- THEN PublicationService.index() is called
- AND results from all catalogs are returned with pagination

### Scenario: Search with federation
- GIVEN federated directory listings exist with `default: true`
- WHEN a search is performed via `/api/search` or `/api/federation/publications`
- THEN PublicationService queries local catalogs for publications
- AND remote directories are queried via async HTTP
- AND all results are merged and sorted by relevance score

### Scenario: Facet merging from multiple sources
- GIVEN local search returns facets {theme: [{_id: "milieu", count: 5}]}
- AND a remote source returns facets {theme: [{_id: "milieu", count: 3}, {_id: "energie", count: 2}]}
- WHEN PublicationService merges aggregations
- THEN the merged result is {theme: [{_id: "milieu", count: 8}, {_id: "energie", count: 2}]}

### Scenario: Query building via ObjectService
- GIVEN a query string `_order[title]=asc&themes[or]=1,2,3&_search=test`
- WHEN ObjectService.buildSearchQuery() is called with the request params
- THEN it returns a normalized query with proper bracket/dot notation handled
- AND the query is passed to searchObjectsPaginated() for execution

## Dependencies

- **PublicationService** - `index()` for internal search, `getAggregatedPublications()` for federated search with facet merging and result sorting
- **OpenRegister ObjectService** - `buildSearchQuery()` for query parsing, `searchObjectsPaginated()` for paginated search with facets
- **DirectoryService** - Provides remote listing data for federated search (used by PublicationService)
- **GuzzleHttp** - Async HTTP requests to remote directories (used by PublicationService)

## Catalog-model scope for the public search endpoint (WOO-536, ported to the 1.x stable line by WOO-572)

The public full-text search endpoint `/apps/opencatalogi/api/search` derives its
scope from the catalog model and enforces visibility in SQL through
OpenRegister's schema-level RBAC. This section is the 1.x-line port of the
`fix-fts-catalog-model-alignment` change (`openspec/changes/archive/2026-08-28-fix-fts-catalog-model-alignment/`);
field names follow the 1.x seed (`publicatiedatum` / `depublicatiedatum`).

### Requirement: Accept `_catalog` and `_catalogi[]` scope-narrowing params (SCH-PFTS-CAT-001)

The endpoint MUST accept an optional `_catalog` query parameter (single catalog
slug) and an optional `_catalogi[]` array parameter (multiple catalog slugs).
When either is provided, the search scope MUST be limited to the union of
registers and schemas declared by the matching catalog(s). Catalogs that are not
publicly available (no `published` datetime in the past) MUST be treated as
non-existent. Clients MUST NOT be able to widen scope via `_schema`,
`_registers`, `fq` or `catalogSlug` on this endpoint — those parameters are
stripped before the query reaches OpenRegister.

#### Scenario: single catalog scope via `_catalog`

- **WHEN** a caller sends `GET /apps/opencatalogi/api/search?_search=term&_catalog=my-catalog`,
- **THEN** the search scope MUST be limited to the registers and schemas declared by the catalog with slug `my-catalog`,
- **AND** objects from schemas not in that catalog MUST be absent from the results.

#### Scenario: unpublished catalog is indistinguishable from a missing one

- **WHEN** a caller sends `_catalog=<slug-of-unpublished-catalog>`,
- **THEN** the endpoint MUST return HTTP 200 with `total: 0` and no results.

#### Scenario: disallowed scope widening via `_schema`

- **WHEN** a caller sends `GET /apps/opencatalogi/api/search?_search=term&_schema=42`,
- **THEN** the `_schema` parameter MUST be silently stripped,
- **AND** the scope MUST be resolved from the catalog model as normal.

### Requirement: Default scope is the union of listed and published catalogs (SCH-PFTS-CAT-002)

When neither `_catalog` nor `_catalogi[]` is provided, the scope MUST be the
union of registers and schemas of all catalogs with `listed: true` AND a
`published` datetime in the past. The catalog register and schema are located
through the `catalog_register` / `catalog_schema` app-config values. An empty
resolved scope MUST fail closed to an empty envelope (`results: [], total: 0`)
and MUST log a warning.

#### Scenario: multi-schema catalog returns results from all schemas

- **WHEN** a listed, published catalog declares three schemas (e.g. `publication`, `document`, `besluit`),
- **AND** the caller sends `GET /apps/opencatalogi/api/search?_search=term`,
- **THEN** results from all three schemas MUST be present in the response,
- **AND** each result MUST carry `@self.schema` set to the schema's slug (resolved dynamically via the OpenRegister SchemaMapper).

### Requirement: Catalog-derived scope replaces app-config scope (SCH-PFTS-CAT-003)

The endpoint MUST NOT use the `publication_register`, `publication_schema` or
`document_schema` app-config values to determine its scope.

### Requirement: Visibility is enforced in SQL by schema-level RBAC (SCH-PFTS-004, amended)

The endpoint MUST call `ObjectService::searchObjectsPaginated()` with
`_rbac: true` and `_multitenancy: false`. The former PHP post-filter
(`isObjectPublic()`), the anonymous per-page `total` undercount and the
stripping of `facets` / `facetable` for anonymous callers are REMOVED.
Visibility for anonymous callers is defined by the `public` group's `read`
rules on each schema. For the bundled `publication` and `document` schemas the
seed MUST carry the two-rule shape:

```json
"read": [
  { "group": "public", "match": { "publicatiedatum": { "$lte": "$now" }, "depublicatiedatum": { "$gte": "$now" } } },
  { "group": "public", "match": { "publicatiedatum": { "$lte": "$now" }, "depublicatiedatum": { "$exists": false } } },
  "authenticated"
]
```

Existing installations MUST be brought to this shape by the idempotent repair
step `OCA\OpenCatalogi\Repair\WOO572RepairReadRules`; admin-customised rule
sets MUST be left untouched.

Two drops remain in PHP because OpenRegister cannot express them in a schema
rule: rows with `status: archived` (terminal-hidden state) and document rows
whose linked publication is not visible to the caller (transitive visibility,
resolved through the OpenRegister relation graph with `_relations_contains`).
The envelope `total` is OpenRegister's global count minus the per-page drops,
floored at the number of rows shipped, so `total >= count(results)` always
holds.

Authenticated callers are evaluated by the same RBAC engine (group rules,
`_owner` clause, admin bypass, `inheritFromPublic`), so signed-in staff MAY see
rows anonymous callers do not — the same semantics as the 2.x line after
WOO-551.

#### Scenario: depublished publications are absent for anonymous callers

- **GIVEN** a publication whose `depublicatiedatum` is in the past,
- **WHEN** an anonymous caller issues the public search,
- **THEN** the publication MUST NOT appear in the response.

#### Scenario: document visibility is transitively gated

- **GIVEN** a document D linked to a publication P whose `depublicatiedatum` is in the past,
- **WHEN** an anonymous caller searches for content matching D,
- **THEN** D MUST NOT appear in the response.

#### Scenario: `total` never undercounts the shipped page

- **WHEN** any caller sends `GET /apps/opencatalogi/api/search`,
- **THEN** `total` MUST be greater than or equal to the number of rows in `results`.

#### Scenario: `facets` and `facetable` are forwarded for anonymous callers

- **WHEN** an anonymous caller sends `GET /apps/opencatalogi/api/search?_facetable=true`,
- **THEN** the `facets` and `facetable` blocks returned by OpenRegister MUST be forwarded unchanged.
