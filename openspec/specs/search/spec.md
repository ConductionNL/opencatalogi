---
status: reviewed
retrofit_extensions:
  - SCH-016
  - SCH-017
  - SCH-018
  - SCH-019
  - SCH-020
---

# Search

## Purpose

The search feature provides an internal search API endpoint that queries publications across all available catalogs. Unlike the public publication endpoints (scoped by catalog slug), the internal search endpoint is for authenticated Nextcloud users and administrative purposes. The `SearchController` delegates to `PublicationService` for all search operations. Note: There is no separate `SearchService` or `ElasticSearchService` class in the OpenCatalogi codebase -- all search and federation logic is handled by `PublicationService`.
## Requirements
### Requirement: Provide an internal search endpoint at `/api/search` for authenticated users (SCH-001)
The system MUST provide an internal search endpoint at `/api/search` for authenticated users.

**Priority:** Must **Status:** Implemented

### Requirement: Support full-text search via `_search` parameter (SCH-002)
The system MUST support full-text search via the `_search` parameter.

**Priority:** Must **Status:** Implemented

### Requirement: Support filtering by catalog ID (SCH-003)
The system SHOULD support filtering by catalog ID.

**Priority:** Should **Status:** Implemented

### Requirement: Support pagination (_limit, _page, _offset) (SCH-004)
The system MUST support pagination (_limit, _page, _offset).

**Priority:** Must **Status:** Implemented

### Requirement: Support ordering (_order) (SCH-005)
The system MUST support ordering (_order).

**Priority:** Must **Status:** Implemented

### Requirement: Integrate with ElasticSearch when configured (SCH-006)
The system SHOULD integrate with ElasticSearch when configured.

**Priority:** Should **Status:** Not Implemented (no ElasticSearchService in OpenCatalogi)

### Requirement: Support distributed search across remote directories via async HTTP (SCH-007)
The system SHOULD support distributed search across remote directories via async HTTP.

**Priority:** Should **Status:** Implemented (via PublicationService federation)

### Requirement: Merge facets/aggregations from multiple sources (SCH-008)
The system SHOULD merge facets/aggregations from multiple sources.

**Priority:** Should **Status:** Implemented (via PublicationService federation)

### Requirement: Parse complex query strings with nested parameters (SCH-009)
The system SHOULD parse complex query strings with nested parameters.

**Priority:** Should **Status:** Implemented (via ObjectService.buildSearchQuery)

### Requirement: Create MySQL/MongoDB-compatible search filters and sort parameters (SCH-010)
The system MUST create MySQL/MongoDB-compatible search filters and sort parameters.

**Priority:** Must **Status:** Not Applicable (no SearchService exists -- search uses OpenRegister's ObjectService directly)

### Requirement: SearchController has show(), attachments(), download(), uses(), used() methods with no routes (SCH-011)
SearchController SHOULD have show(), attachments(), download(), uses(), used() methods with no routes.

**Priority:** Nice **Status:** Dead Code

### Requirement: Support filter syntax with special query parameters (_search, _order, _limit, _page, _offset, _queries) (SCH-012)
The system MUST support filter syntax with special query parameters (_search, _order, _limit, _page, _offset, _queries).

**Priority:** Must **Status:** Implemented

### Requirement: Generate dual MySQL and MongoDB filter/sort parameters from request query parameters (SCH-013)
The system MUST generate dual MySQL and MongoDB filter/sort parameters from request query parameters.

**Priority:** Must **Status:** Not Applicable (no SearchService exists in OpenCatalogi)

### Requirement: Parse complex nested query strings with bracket notation (e.g., `_order[title]=asc`, `themes[or]=1,2,3`) (SCH-014)
The system MUST parse complex nested query strings with bracket notation (e.g., `_order[title]=asc`, `themes[or]=1,2,3`).

**Priority:** Must **Status:** Implemented (via ObjectService.buildSearchQuery in OpenRegister)

### Requirement: Unset all underscore-prefixed special parameters before passing to database filter layer (SCH-015)
The system MUST unset all underscore-prefixed special parameters before passing them to the database filter layer.

**Priority:** Must **Status:** Implemented (via ObjectService.buildSearchQuery in OpenRegister)

### Requirement: Frontend search store queries federated publications (SCH-016)
The frontend search store SHALL query publications via the federation endpoint
`GET /index.php/apps/opencatalogi/api/federation/publications`, building query parameters
from the current search term (`_search`), pagination (`_page` / `_limit`), active filters,
ordering (`_order[field]=direction`), and the federation flags `_facetable=true`,
`_aggregate=true`, plus `_extend[]` of `@self.schema` (and `@self.register`). Loading and
error state are tracked on the store; results, total, and facet data are stored for the UI.

**Priority:** Must **Status:** Implemented

#### Scenario: Run a publication search
- GIVEN a search term and optional filters
- WHEN `searchStore.searchPublications()` is called
- THEN a request MUST be sent to `/api/federation/publications` with `_search`, pagination,
  `_facetable=true`, `_aggregate=true`, and the active filters/ordering encoded
- AND results, total, and facets MUST be stored on success

### Requirement: Facet discovery and active-facet query building (SCH-017)
The frontend search store SHALL discover facetable fields via
`discoverFacetableFields()` (populating the facetable-fields map and tracking
`facetsLoading`), and SHALL translate the user's enabled facets into request parameters via
`buildFacetQuery()`, including `@self` metadata facets, so that enabling a facet narrows the
next search.

**Priority:** Should **Status:** Implemented

#### Scenario: Discover facetable fields
- GIVEN the search view loads
- WHEN `discoverFacetableFields()` runs
- THEN the store's facetable-fields map MUST be populated and `facetsLoading` toggled
- @e2e exclude Pinia `searchStore` HTTP behaviour — asserts `discoverFacetableFields()` populates the facetable-fields map and toggles `facetsLoading` (a store-internal data-fetch side-effect); verified by Vitest store test (mocked axios). The search route + facet UI shell are covered by the live `search::run-a-publication-search` / `search::toggle-a-facet-from-the-ui` Playwright tests.

#### Scenario: Build a facet query from active facets
- GIVEN one or more active facets
- WHEN a search runs
- THEN `buildFacetQuery()` MUST encode them (including `@self` facets) into the request
- @e2e exclude Pure `searchStore.buildFacetQuery()` request-encoding logic (active facets + `@self` facets → request params) — deterministic input→output with no UI surface; verified by Vitest store unit test.

### Requirement: Search UI components (SCH-018)
The system SHALL provide a search frontend comprising a `SearchSideBar` (facet filter
controls), a `SearchResults` component (renders the result list), and a `FacetComponent`
(renders an individual facet filter and toggles it on the store).

**Priority:** Should **Status:** Implemented

#### Scenario: Toggle a facet from the UI
- GIVEN a facet rendered by `FacetComponent`
- WHEN the user enables it
- THEN the store's active facets MUST update and a re-search MUST be triggerable

### Requirement: Internal/admin publication search endpoint (SCH-019)
The system SHALL expose an internal `SearchController` whose `index` action
(`GET /api/search`, `@NoAdminRequired` / `@NoCSRFRequired`) returns a list of publications
across all catalogs (optionally filtered by `catalogId`) by delegating to
`PublicationService::index`. This is documented as an internal endpoint for testing and
administrative purposes.

**Priority:** Should **Status:** Implemented

@e2e exclude Internal backend HTTP endpoint (`GET /api/search`, `SearchController::index` delegating to `PublicationService::index`) — a JSON API with no rendered UI surface; verified by PHPUnit (SearchController/PublicationService) and Newman `GET /api/search` API contract.

#### Scenario: List publications via the internal search endpoint
- GIVEN an authenticated request to `GET /api/search`
- WHEN `SearchController::index` runs
- THEN it MUST delegate to `PublicationService::index` and return the JSON publication list

### Requirement: Internal per-publication retrieval, attachment, download and relation actions (SCH-020)
The internal `SearchController` SHALL expose per-publication actions that delegate to
`PublicationService`, each documented as internal/administrative and declared
`@NoAdminRequired` / `@NoCSRFRequired`:
`show(id)` returns a single publication, `attachments(id)` returns its files,
`download(id)` returns a `DataDownloadResponse` (or `JSONResponse` on error) for its files,
`uses(id)` returns the objects this publication references (A → B), and
`used(id)` returns the objects that reference this publication (B → A).

**Priority:** Should **Status:** Implemented

@e2e exclude Internal backend controller actions (`SearchController::show`/`attachments`/`download`/`uses`/`used` delegating to `PublicationService`) — JSON/data-download HTTP responses with no rendered UI surface, and per the spec notes these are routeless/unreachable orphan actions; verified by PHPUnit (SearchController/PublicationService) and, where routed, Newman API contract.

#### Scenario: Retrieve a single publication and its files
- GIVEN an authenticated request to `SearchController::show`, `attachments`, or `download` with a publication `id`
- WHEN the action runs
- THEN it MUST delegate to the corresponding `PublicationService` method and return its result

#### Scenario: Inspect publication relations
- GIVEN an authenticated request to `SearchController::uses` or `used` with a publication `id`
- WHEN the action runs
- THEN `uses` MUST return objects the publication references and `used` MUST return objects that reference the publication, via `PublicationService`

> **Notes (observed orphan — not fixed by this retrofit):**
> `src/store/modules/search.js` exists alongside the live `src/store/modules/search.ts`,
> but `src/store/store.js` imports `./modules/search` which resolves to the `.ts` file.
> The `.js` copy is not referenced anywhere and is dead/orphaned code (the coverage report
> flags it as a "possible duplicate"). These REQs describe the live `.ts` store; the orphan
> `.js` file is intentionally **not** annotated. Removing it is a separate code change.

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
