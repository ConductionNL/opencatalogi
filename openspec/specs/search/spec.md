# Search

## Purpose

The search feature provides an internal search API endpoint that queries publications across all available catalogs. Unlike the public publication endpoints (scoped by catalog slug), the internal search endpoint is for authenticated Nextcloud users and administrative purposes. The SearchService also provides the underlying search infrastructure used by federation, including distributed search across remote directories via Elasticsearch integration and async HTTP.

## Requirements

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| SCH-001 | Provide an internal search endpoint at `/api/search` for authenticated users | Must | Implemented |
| SCH-002 | Support full-text search via `_search` parameter | Must | Implemented |
| SCH-003 | Support filtering by catalog ID | Should | Implemented |
| SCH-004 | Support pagination (_limit, _page, _offset) | Must | Implemented |
| SCH-005 | Support ordering (_order) | Must | Implemented |
| SCH-006 | Integrate with ElasticSearch when configured | Should | Implemented |
| SCH-007 | Support distributed search across remote directories via async HTTP | Should | Implemented |
| SCH-008 | Merge facets/aggregations from multiple sources | Should | Implemented |
| SCH-009 | Parse complex query strings with nested parameters | Should | Implemented |
| SCH-010 | Create MySQL/MongoDB-compatible search filters and sort parameters | Must | Implemented |
| SCH-011 | SearchController has show(), attachments(), download(), uses(), used() methods with no routes | Nice | Dead Code |
| SCH-012 | Support filter syntax with special query parameters (_search, _order, _limit, _page, _offset, _queries) | Must | Implemented |
| SCH-013 | Generate dual MySQL and MongoDB filter/sort parameters from request query parameters | Must | Implemented |
| SCH-014 | Parse complex nested query strings with bracket notation (e.g., `_order[title]=asc`, `themes[or]=1,2,3`) | Must | Implemented |
| SCH-015 | Unset all underscore-prefixed special parameters before passing to database filter layer | Must | Implemented |

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

The SearchService provides filter parsing infrastructure used across the application:

### Special Query Parameters

| Parameter | Purpose | Example |
|-----------|---------|---------|
| `_search` | Full-text search term | `?_search=klimaat` |
| `_order` | Sort order (field to direction map) | `?_order[title]=asc&_order[date]=desc` |
| `_limit` | Results per page (default: 30) | `?_limit=50` |
| `_page` | Current page number | `?_page=2` |
| `_offset` | Skip N results | `?_offset=20` |
| `_queries` | Fields to aggregate/facet | `?_queries[]=theme&_queries[]=organization` |
| `_catalogi` | Filter by catalog IDs | `?_catalogi[]=cat1&_catalogi[]=cat2` |

### MySQL Filter Generation

`createMySQLSearchConditions()` generates SQL WHERE conditions:
- `_search` is converted to `LOWER(field) LIKE :search` for each searchable field (OR-joined)
- Comma-separated filter values generate OR conditions: `field = :field_0 OR field = :field_1`
- `IS NOT NULL` and `IS NULL` string values are converted to appropriate SQL conditions (MongoDB only)

`createMySQLSearchParams()` generates parameter bindings:
- `_search` becomes `%{lowercased_search_term}%` bound to `:search`

`createSortForMySQL()` generates ORDER BY parameters:
- Validates direction as ASC or DESC (defaults to ASC)
- Returns `{ field: direction }` map

### MongoDB Filter Generation

`createMongoDBSearchFilter()` generates MongoDB query conditions:
- `_search` is converted to `$regex` with case-insensitive `$options: 'i'`
- Multiple search fields are joined with `$or`
- `IS NOT NULL` becomes `$ne: null`, `IS NULL` becomes `$eq: null`

`createSortForMongoDB()` generates MongoDB sort:
- DESC becomes -1, ASC becomes 1

### Query String Parsing

`parseQueryString()` provides custom query string parsing that preserves bracket notation:
- `_order[title]=asc` parses to `{ _order: { title: "asc" } }`
- `themes[or]=1,2,3` parses to `{ themes: { or: "1,2,3" } }`
- `queryParam[]` (trailing brackets) builds arrays: `{ queryParam: [value1, value2] }`
- Supports deep nesting: `a[b][c]=val` parses to `{ a: { b: { c: "val" } } }`
- Uses `recursiveRequestQueryKey()` for recursive bracket parsing

`unsetSpecialQueryParams()` removes all underscore-prefixed parameters and `search` from filter arrays before they are passed to database queries, preventing system parameters from being treated as field filters.

## Scenarios

### Scenario: Internal publication search
- GIVEN catalogs with publications exist
- WHEN an authenticated user sends GET `/api/search?_search=klimaat`
- THEN PublicationService.index() is called
- AND results from all catalogs are returned with pagination

### Scenario: Search with ElasticSearch
- GIVEN ElasticSearch is configured with a non-empty location
- WHEN a search is performed
- THEN SearchService.search() queries ElasticSearch first for local results
- AND directory listings are checked for federated search
- AND remote directories are queried via async HTTP
- AND all results are merged and sorted by relevance score

### Scenario: Facet merging from multiple sources
- GIVEN local search returns facets {theme: [{_id: "milieu", count: 5}]}
- AND a remote source returns facets {theme: [{_id: "milieu", count: 3}, {_id: "energie", count: 2}]}
- WHEN SearchService.mergeAggregations() is called
- THEN the merged result is {theme: [{_id: "milieu", count: 8}, {_id: "energie", count: 2}]}

### Scenario: Complex query string parsing
- GIVEN a query string `_order[title]=asc&themes[or]=1,2,3&_search=test`
- WHEN SearchService.parseQueryString() is called
- THEN it returns `{_order: {title: "asc"}, themes: {or: "1,2,3"}, _search: "test"}`

### Scenario: MySQL filter generation with comma-separated values
- GIVEN filters `{ theme: "milieu,energie", _search: "klimaat" }`
- AND fieldsToSearch = ["title", "description"]
- WHEN createMySQLSearchConditions() is called
- THEN searchConditions contains `(LOWER(title) LIKE :search OR LOWER(description) LIKE :search)`
- AND `(theme = :theme_0 OR theme = :theme_1)` is added
- AND searchParams contains `{ search: "%klimaat%", theme_0: "milieu", theme_1: "energie" }`

## Dependencies

- **PublicationService** - index() for internal search
- **ElasticSearchService** - Elasticsearch integration (optional, see [elasticsearch spec](../elasticsearch/spec.md))
- **DirectoryService** - listDirectory() for remote search endpoints
- **SearchService** - Query parsing, filter creation, facet merging, distributed search
- **GuzzleHttp** - Async HTTP requests to remote directories
