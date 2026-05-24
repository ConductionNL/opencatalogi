---
status: reviewed
---

# Search

## Purpose

The search feature provides an internal search API endpoint that queries publications across all available catalogs. Unlike the public publication endpoints (scoped by catalog slug), the internal search endpoint is for authenticated Nextcloud users and administrative purposes. The `SearchController` delegates to `PublicationService` for all search operations. Note: There is no separate `SearchService` or `ElasticSearchService` class in the OpenCatalogi codebase -- all search and federation logic is handled by `PublicationService`.

## Requirements

### Requirement: Provide an internal search endpoint at `/api/search` for authenticated users
The system MUST provide an internal search endpoint at `/api/search` for authenticated users.

**ID:** SCH-001 — Priority: Must — Status: Implemented

### Requirement: Support full-text search via `_search` parameter
The system MUST support full-text search via the `_search` parameter.

**ID:** SCH-002 — Priority: Must — Status: Implemented

### Requirement: Support filtering by catalog ID
The system SHOULD support filtering by catalog ID.

**ID:** SCH-003 — Priority: Should — Status: Implemented

### Requirement: Support pagination (_limit, _page, _offset)
The system MUST support pagination (_limit, _page, _offset).

**ID:** SCH-004 — Priority: Must — Status: Implemented

### Requirement: Support ordering (_order)
The system MUST support ordering (_order).

**ID:** SCH-005 — Priority: Must — Status: Implemented

### Requirement: Integrate with ElasticSearch when configured
The system SHOULD integrate with ElasticSearch when configured.

**ID:** SCH-006 — Priority: Should — Status: Not Implemented (no ElasticSearchService in OpenCatalogi)

### Requirement: Support distributed search across remote directories via async HTTP
The system SHOULD support distributed search across remote directories via async HTTP.

**ID:** SCH-007 — Priority: Should — Status: Implemented (via PublicationService federation)

### Requirement: Merge facets/aggregations from multiple sources
The system SHOULD merge facets/aggregations from multiple sources.

**ID:** SCH-008 — Priority: Should — Status: Implemented (via PublicationService federation)

### Requirement: Parse complex query strings with nested parameters
The system SHOULD parse complex query strings with nested parameters.

**ID:** SCH-009 — Priority: Should — Status: Implemented (via ObjectService.buildSearchQuery)

### Requirement: Create MySQL/MongoDB-compatible search filters and sort parameters
The system MUST create MySQL/MongoDB-compatible search filters and sort parameters.

**ID:** SCH-010 — Priority: Must — Status: Not Applicable (no SearchService exists -- search uses OpenRegister's ObjectService directly)

### Requirement: SearchController has show(), attachments(), download(), uses(), used() methods with no routes
SearchController SHOULD have show(), attachments(), download(), uses(), used() methods with no routes.

**ID:** SCH-011 — Priority: Nice — Status: Dead Code

### Requirement: Support filter syntax with special query parameters (_search, _order, _limit, _page, _offset, _queries)
The system MUST support filter syntax with special query parameters (_search, _order, _limit, _page, _offset, _queries).

**ID:** SCH-012 — Priority: Must — Status: Implemented

### Requirement: Generate dual MySQL and MongoDB filter/sort parameters from request query parameters
The system MUST generate dual MySQL and MongoDB filter/sort parameters from request query parameters.

**ID:** SCH-013 — Priority: Must — Status: Not Applicable (no SearchService exists in OpenCatalogi)

### Requirement: Parse complex nested query strings with bracket notation (e.g., `_order[title]=asc`, `themes[or]=1,2,3`)
The system MUST parse complex nested query strings with bracket notation (e.g., `_order[title]=asc`, `themes[or]=1,2,3`).

**ID:** SCH-014 — Priority: Must — Status: Implemented (via ObjectService.buildSearchQuery in OpenRegister)

### Requirement: Unset all underscore-prefixed special parameters before passing to database filter layer
The system MUST unset all underscore-prefixed special parameters before passing them to the database filter layer.

**ID:** SCH-015 — Priority: Must — Status: Implemented (via ObjectService.buildSearchQuery in OpenRegister)

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
