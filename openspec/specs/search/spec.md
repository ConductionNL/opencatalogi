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
