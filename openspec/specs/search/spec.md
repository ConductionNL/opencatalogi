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
| SCH-PFTS-001 | Public search endpoint enforces uniform visibility for all callers (SQL RBAC via `_rbac_as_public`, per ADR-022) | Must | Implemented (WOO-536) |
| SCH-PFTS-CAT-001 | Accept `_catalog` / `_catalogi[]` scope-narrowing params | Must | Implemented (WOO-536) |
| SCH-PFTS-CAT-002 | Default scope = union of listed and published catalogs | Must | Implemented (WOO-536) |
| SCH-PFTS-CAT-003 | Catalog-derived scope replaces app-config scope | Must | Implemented (WOO-536) |

### Requirement: Public search endpoint enforces uniform visibility for all callers (SCH-PFTS-001)

The `/apps/opencatalogi/api/search` endpoint SHALL return identical result sets regardless of the caller's authentication state. Authenticated callers (including Nextcloud admins and object owners) SHALL see the same results as anonymous callers. This SHALL be enforced by passing `_rbac_as_public: true` alongside `_rbac: true` to `ObjectService::searchObjectsPaginated()` (consuming the `rbac-as-public-toggle` primitive from OpenRegister, per ADR-022). The previous mechanism — a PHP post-filter (`isObjectPublic()`) — is removed; visibility is now enforced in SQL by the RBAC engine using only the `public` group's matching rules from each schema's `authorization.read` configuration. This change preserves the security intent of SCH-PFTS-001 while extending it to arbitrary schemas (not only `publication` and `document`).

#### Scenario: Admin caller sees same results as anonymous caller
- **WHEN** an authenticated Nextcloud admin sends `GET /apps/opencatalogi/api/search?_search=term`
- **THEN** the result set is identical to that returned for an anonymous (unauthenticated) caller with the same query
- **AND** the admin's own unpublished objects are absent from the results

#### Scenario: Object owner cannot see their own unpublished objects via search
- **WHEN** a user who owns a draft `publication` object (publicatiedatum in the future) sends `GET /apps/opencatalogi/api/search?_search=term`
- **THEN** the draft object is absent from the results
- **AND** the result set matches what an anonymous caller would see

#### Scenario: Published objects within window are visible to all callers
- **WHEN** a `publication` object has `publicatiedatum` in the past and `depublicatiedatum` absent or in the future
- **AND** any caller (anonymous or authenticated) sends `GET /apps/opencatalogi/api/search?_search=term`
- **THEN** the publication is present in the results

#### Scenario: Depublished objects are absent for all callers
- **WHEN** a `publication` object has `depublicatiedatum` in the past
- **AND** any caller sends `GET /apps/opencatalogi/api/search?_search=term`
- **THEN** the publication is absent from the results

#### Scenario: `total` reflects the true visible count
- **WHEN** any caller sends `GET /apps/opencatalogi/api/search?_search=term`
- **THEN** the `total` field in the response equals the actual count of objects visible under public RBAC
- **AND** `total` is NOT an undercount caused by PHP post-filtering

#### Scenario: `facets` and `facetable` are populated for anonymous callers
- **WHEN** an anonymous caller sends `GET /apps/opencatalogi/api/search?_search=term`
- **THEN** the `facets` and `facetable` fields are present and populated in the response
- **AND** facet counts reflect only publicly visible objects

### Requirement: Accept `_catalog` and `_catalogi[]` scope-narrowing params (SCH-PFTS-CAT-001)

The `/apps/opencatalogi/api/search` endpoint SHALL accept an optional `_catalog` query parameter (single catalog UUID or slug) and an optional `_catalogi[]` array parameter (multiple catalog UUIDs or slugs). When either parameter is provided, the search scope SHALL be limited to the union of registers and schemas declared by the matching catalog(s). When both are absent, the default scope applies (see SCH-PFTS-CAT-002). Clients MAY NOT widen scope via `_schema`, `_registers`, or `fq` on this endpoint (those parameters remain stripped, per existing discipline). Links to CAT-010, PUB-003, PUB-004.

#### Scenario: Single catalog scope via `_catalog`
- **WHEN** a caller sends `GET /apps/opencatalogi/api/search?_search=term&_catalog=my-catalog`
- **THEN** the search scope is limited to the registers and schemas declared by the catalog with slug `my-catalog`
- **AND** objects from schemas not in that catalog are absent from the results

#### Scenario: Multi-catalog scope via `_catalogi[]`
- **WHEN** a caller sends `GET /apps/opencatalogi/api/search?_search=term&_catalogi[]=cat-a&_catalogi[]=cat-b`
- **THEN** the search scope is the union of all registers and schemas declared by catalogs `cat-a` and `cat-b`
- **AND** objects from either catalog are present in the results

#### Scenario: Disallowed scope widening via `_schema`
- **WHEN** a caller sends `GET /apps/opencatalogi/api/search?_search=term&_schema=42`
- **THEN** the `_schema` parameter is silently stripped
- **AND** the scope is resolved from the catalog model as normal

### Requirement: Default scope is union of listed and published catalogs (SCH-PFTS-CAT-002)

When neither `_catalog` nor `_catalogi[]` is provided, the `/apps/opencatalogi/api/search` endpoint SHALL compute its scope as the union of all catalogs where `listed: true` AND the catalog object itself is published (passes its own `read` authorization rules under public context). Schemas without any explicit `read` authorization configuration SHALL be excluded from the anonymous search scope and the system SHALL log a warning for each such schema encountered. Links to CAT-010, PUB-003.

#### Scenario: Default scope includes all listed published catalogs
- **WHEN** a caller sends `GET /apps/opencatalogi/api/search?_search=term` with no `_catalog` or `_catalogi[]` params
- **THEN** the search scope is the union of registers and schemas from all catalogs with `listed: true` that are themselves published
- **AND** objects from schemas in non-listed or unpublished catalogs are absent from the results

#### Scenario: Schema without explicit read rules is excluded
- **WHEN** a schema in a listed published catalog has no `authorization.read` configuration
- **THEN** that schema is excluded from the anonymous search scope
- **AND** the system logs a warning identifying the schema by ID and slug

### Requirement: Catalog-derived scope replaces app-config scope (SCH-PFTS-CAT-003)

The `/apps/opencatalogi/api/search` endpoint SHALL NOT use `publication_register`, `publication_schema`, or `document_schema` app-config values to determine search scope. Scope SHALL be derived entirely from the catalog model via `buildCatalogSearchQuery()` + `resolveSchemaAndRegisterObjects()`. A misconfigured or missing app-config value SHALL NOT cause the endpoint to return an empty result set. Links to CAT-010, PUB-003, PUB-004.

#### Scenario: Scope independent of app-config
- **WHEN** the `publication_register` / `publication_schema` / `document_schema` app-config values are absent or incorrect
- **THEN** the search still returns results from the catalog-model-derived scope
- **AND** the endpoint does not return HTTP 200 with an empty result set due to a missing config value

#### Scenario: Multi-schema catalog returns results from all schemas
- **WHEN** a catalog declares three schemas (e.g. `publication`, `document`, `besluit`)
- **AND** the caller sends `GET /apps/opencatalogi/api/search?_search=term`
- **THEN** results from all three schemas are present in the response
- **AND** each result carries `@self.schema` set to the correct schema slug

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
