---
status: draft
---

# Search Specification

## Purpose

The search feature provides an internal search API endpoint (`GET /api/search`) that queries publications across all available catalogs for authenticated Nextcloud users. `SearchController` delegates all search and federation logic to `PublicationService`, which in turn relies on OpenRegister's `ObjectService` for paginated full-text search, filter normalisation, and facet aggregation. There is no separate `SearchService` or `ElasticSearchService` in the OpenCatalogi codebase.

## Context

OpenCatalogi's public-facing publication queries are scoped by catalog slug (`GET /api/{catalogSlug}`). The internal search endpoint differs in two ways: it is authenticated (not public) and it queries across all catalogs rather than a single one. The frontend search view (`SearchIndex.vue`) uses this endpoint to provide a cross-catalog search experience for admin users.

**Relation to existing specs:**
- `publications` spec: defines the public catalog-scoped endpoints. The internal search endpoint is a complement, not a replacement.
- OpenRegister `ObjectService`: provides `buildSearchQuery()` and `searchObjectsPaginated()` — these are the actual query execution primitives. `SearchController` never calls `ObjectService` directly; it delegates to `PublicationService`.

**Relation to existing entities:**
- `Publication`: the sole entity type returned by search results. Search has no schema of its own.
- `Catalog`: search queries across all registered catalogs simultaneously.
- `Directory` (Listing): federated directories with `default: true` are queried by `PublicationService` during federation.

## Requirements

### REQ-SCH-001: Internal search endpoint

The system MUST provide an authenticated search endpoint at `GET /api/search` that returns publications from all catalogs.

#### Scenario SCH-001-A: Authenticated user receives paginated results
- GIVEN catalogs with publications exist in the system
- WHEN an authenticated Nextcloud user sends `GET /api/search`
- THEN the response MUST have HTTP status 200
- AND the response body MUST contain `results`, `count`, `total`, `limit`, `page`, and `pages` fields
- AND `results` MUST be an array of Publication objects from all catalogs

#### Scenario SCH-001-B: Unauthenticated request is rejected
- GIVEN no valid Nextcloud session is present
- WHEN a request is sent to `GET /api/search`
- THEN the response MUST have HTTP status 401
- AND no publication data MUST be returned

#### Scenario SCH-001-C: No CORS headers on search endpoint
- GIVEN a browser sends a cross-origin preflight OPTIONS request to `/api/search`
- WHEN the request is processed
- THEN the server MUST NOT respond with `Access-Control-Allow-Origin` headers
- AND there MUST be no OPTIONS route registered for `/api/search` in `routes.php`

---

### REQ-SCH-002: Full-text search via `_search` parameter

The endpoint MUST accept a `_search` query parameter for full-text filtering of publications.

#### Scenario SCH-002-A: Full-text search returns matching publications
- GIVEN publications exist with titles and descriptions containing the word "klimaat"
- WHEN an authenticated user sends `GET /api/search?_search=klimaat`
- THEN `PublicationService::index()` MUST be called with `_search=klimaat`
- AND only publications matching the full-text term MUST appear in `results`

#### Scenario SCH-002-B: Search with no matching results returns empty array
- GIVEN no publications contain the term "xyznonexistentterm"
- WHEN an authenticated user sends `GET /api/search?_search=xyznonexistentterm`
- THEN the response MUST have HTTP status 200
- AND `results` MUST be an empty array
- AND `total` MUST be 0

#### Scenario SCH-002-C: Search without `_search` returns all publications
- GIVEN 50 publications exist across all catalogs
- WHEN an authenticated user sends `GET /api/search` without `_search`
- THEN all 50 publications MUST be retrievable via pagination
- AND `total` MUST equal 50

---

### REQ-SCH-003: Filter by catalog via `_catalogi` parameter

The endpoint MUST support filtering results to one or more specific catalogs by catalog ID.

#### Scenario SCH-003-A: Filter by single catalog ID
- GIVEN catalog A (id=1) has 10 publications and catalog B (id=2) has 5 publications
- WHEN an authenticated user sends `GET /api/search?_catalogi[]=1`
- THEN only the 10 publications from catalog A MUST appear in results
- AND `total` MUST be 10

#### Scenario SCH-003-B: Filter by multiple catalog IDs
- GIVEN catalog A (id=1) has 10 publications and catalog B (id=2) has 5 publications
- WHEN an authenticated user sends `GET /api/search?_catalogi[]=1&_catalogi[]=2`
- THEN publications from both catalogs MUST appear in results
- AND `total` MUST be 15

---

### REQ-SCH-004: Pagination via `_limit`, `_page`, and `_offset`

The endpoint MUST support standard pagination controls. Results MUST include pagination metadata.

#### Scenario SCH-004-A: Default page size is 20
- GIVEN 50 publications exist
- WHEN an authenticated user sends `GET /api/search` without pagination parameters
- THEN `limit` in the response MUST be 20
- AND `results` MUST contain at most 20 publications
- AND `pages` MUST be 3

#### Scenario SCH-004-B: Custom page size via `_limit`
- GIVEN 50 publications exist
- WHEN an authenticated user sends `GET /api/search?_limit=10`
- THEN `limit` MUST be 10
- AND `results` MUST contain at most 10 publications
- AND `pages` MUST be 5

#### Scenario SCH-004-C: Navigate to page 2 via `_page`
- GIVEN 50 publications exist and `_limit=20`
- WHEN an authenticated user sends `GET /api/search?_page=2&_limit=20`
- THEN `page` MUST be 2
- AND `results` MUST contain publications 21 through 40

#### Scenario SCH-004-D: Offset-based pagination via `_offset`
- GIVEN 50 publications exist
- WHEN an authenticated user sends `GET /api/search?_offset=10&_limit=10`
- THEN `results` MUST start from the 11th publication
- AND `results` MUST contain 10 publications

---

### REQ-SCH-005: Result ordering via `_order` parameter

The endpoint MUST support ordering results by one or more fields using bracket notation.

#### Scenario SCH-005-A: Order by title ascending
- GIVEN multiple publications with different titles exist
- WHEN an authenticated user sends `GET /api/search?_order[title]=asc`
- THEN `results` MUST be sorted alphabetically by `title` ascending

#### Scenario SCH-005-B: Order by multiple fields
- GIVEN multiple publications with varying `organization` and `title` values
- WHEN an authenticated user sends `GET /api/search?_order[organization]=asc&_order[title]=asc`
- THEN results MUST be sorted by `organization` first, then by `title`

#### Scenario SCH-005-C: Invalid order field is ignored gracefully
- GIVEN a request with an unrecognised order field
- WHEN an authenticated user sends `GET /api/search?_order[nonexistent_field]=asc`
- THEN the request MUST NOT return an error
- AND results MUST be returned in default order

---

### REQ-SCH-007: Federated search across remote directories

When remote directories are configured with `default: true`, the search endpoint MUST include their results in the response.

#### Scenario SCH-007-A: Federation queries default remote directories
- GIVEN one remote directory listing exists with `default: true`
- WHEN an authenticated user sends `GET /api/search?_search=klimaat`
- THEN `PublicationService` MUST issue an async HTTP request to the remote directory
- AND remote results MUST be merged with local results in the response

#### Scenario SCH-007-B: Federation excludes non-default directories
- GIVEN a remote directory exists with `default: false`
- WHEN an authenticated user sends `GET /api/search`
- THEN no request MUST be made to that remote directory
- AND only local results MUST be returned

#### Scenario SCH-007-C: Remote directory timeout does not fail the response
- GIVEN a remote directory times out during a federated search
- WHEN the search request is processed
- THEN local results MUST still be returned
- AND the response MUST have HTTP status 200
- AND a warning MUST be logged server-side for the failed remote query

---

### REQ-SCH-008: Facet merging from multiple sources

When results come from multiple sources (local and/or federated), the facet counts MUST be merged correctly.

#### Scenario SCH-008-A: Facet counts are summed across sources
- GIVEN local search returns `{theme: [{_id: "milieu", count: 5}]}`
- AND a remote source returns `{theme: [{_id: "milieu", count: 3}, {_id: "energie", count: 2}]}`
- WHEN `PublicationService` merges the aggregations
- THEN the merged facets MUST be `{theme: [{_id: "milieu", count: 8}, {_id: "energie", count: 2}]}`

#### Scenario SCH-008-B: Facets response field is always present
- GIVEN a search with no federated sources
- WHEN the response is returned
- THEN the response MUST contain a `facets` field
- AND `facets` MUST be an object (empty object `{}` when no aggregations are configured)

---

### REQ-SCH-009: Complex query string parsing via ObjectService

The search endpoint MUST correctly parse complex query strings including bracket notation, OR operators, and nested parameters, delegated to OpenRegister's `ObjectService::buildSearchQuery()`.

#### Scenario SCH-009-A: Bracket notation for order is parsed correctly
- GIVEN a request with `_order[title]=asc&_order[date]=desc`
- WHEN `ObjectService::buildSearchQuery()` is called
- THEN the returned query MUST map `title` → `asc` and `date` → `desc` in the sort parameters

#### Scenario SCH-009-B: OR operator for filter arrays is parsed correctly
- GIVEN a request with `themes[or]=1,2,3`
- WHEN `ObjectService::buildSearchQuery()` is called
- THEN the filter MUST include all three theme IDs as an OR condition

#### Scenario SCH-009-C: Special underscore parameters are stripped before database query
- GIVEN a request with `_search=klimaat&_order[title]=asc&_limit=20&title=Verslag`
- WHEN `ObjectService::buildSearchQuery()` processes the parameters
- THEN `_search`, `_order`, and `_limit` MUST be extracted and removed from the database filter map
- AND only `title=Verslag` MUST remain as a direct column filter

---

### REQ-SCH-011: Dead code removal from SearchController

`SearchController` MUST contain only the `index()` method (plus constructor). All unreachable methods with no route registration MUST be removed.

#### Scenario SCH-011-A: Dead methods are absent from SearchController
- GIVEN the current `SearchController.php` file
- WHEN the file is inspected
- THEN the methods `show()`, `attachments()`, `download()`, `uses()`, and `used()` MUST NOT exist
- AND only `__construct()` and `index()` MUST be present as public methods

#### Scenario SCH-011-B: Removing dead methods does not break the search route
- GIVEN the dead-code methods have been removed
- WHEN an authenticated user sends `GET /api/search`
- THEN the response MUST still return HTTP 200 with publication results
- AND no PHP fatal errors or missing-method exceptions MUST occur

---

### REQ-SCH-012: Filter syntax and special query parameters

The endpoint MUST support the full set of special query parameters defined by OpenRegister's `ObjectService`.

#### Scenario SCH-012-A: `_queries` parameter requests facet aggregations
- GIVEN a request with `_queries[]=themes&_queries[]=organization`
- WHEN `PublicationService::index()` is called
- THEN the `facets` in the response MUST contain entries for `themes` and `organization`
- AND each entry MUST include `_id` and `count` values

#### Scenario SCH-012-B: Unknown special parameters are ignored safely
- GIVEN a request with an unknown parameter `_unknown=value`
- WHEN `ObjectService::buildSearchQuery()` processes it
- THEN the parameter MUST be ignored without causing an error
- AND a valid paginated response MUST be returned

---

### REQ-SCH-014: Bracket notation parsing for nested parameters

The filter parsing MUST support bracket-notation keys (e.g., `_order[field]=asc`, `themes[or]=1,2,3`) as implemented by OpenRegister's `ObjectService::buildSearchQuery()`.

#### Scenario SCH-014-A: Dot notation is converted to underscore notation
- GIVEN a query string with `@self.register=1`
- WHEN `ObjectService::buildSearchQuery()` normalises the parameters
- THEN the key MUST be converted to `@self_register=1`
- AND the filter MUST be applied correctly

#### Scenario SCH-014-B: Nested property access is flattened
- GIVEN a query string with `person.address.city=Amsterdam`
- WHEN `ObjectService::buildSearchQuery()` normalises the parameters
- THEN the key MUST be converted to `person_address_city=Amsterdam`

---

### REQ-SCH-015: Strip underscore-prefixed special parameters before database layer

All underscore-prefixed parameters (`_search`, `_order`, `_limit`, `_page`, `_offset`, `_queries`, `_catalogi`) MUST be extracted and removed from the raw filter map before passing it to the database query layer.

#### Scenario SCH-015-A: Underscore parameters do not appear in database column filters
- GIVEN a request with `_search=klimaat&_limit=10&title=Verslag`
- WHEN `ObjectService::buildSearchQuery()` processes the parameters
- THEN `_search` and `_limit` MUST NOT appear as column-level filter conditions
- AND `title=Verslag` MUST remain as a filter condition

#### Scenario SCH-015-B: System parameters are also stripped
- GIVEN a request with `id=abc-123&_route=search&rbac=true`
- WHEN `ObjectService::buildSearchQuery()` processes the parameters
- THEN `id`, `_route`, and `rbac` MUST be stripped from the filter map
- AND no SQL/query injection MUST be possible through these parameters

## MODIFIED Requirements

_None — this change adds specification coverage to an existing implementation._

## REMOVED Requirements

- **SCH-006** (ElasticSearch integration): Removed from scope. No `ElasticSearchService` exists in the OpenCatalogi codebase. If ElasticSearch support is required in the future, it must be addressed via a dedicated spec with a concrete implementation plan.
- **SCH-010** (MySQL/MongoDB filter generation in SearchService): Not Applicable — no `SearchService` exists. Filter generation is handled entirely by OpenRegister's `ObjectService`.
- **SCH-013** (Dual MySQL/MongoDB filter generation): Not Applicable — same reason as SCH-010.

## Current Implementation Status

| Requirement | Status |
|-------------|--------|
| REQ-SCH-001 (Authenticated endpoint) | Implemented |
| REQ-SCH-002 (Full-text `_search`) | Implemented |
| REQ-SCH-003 (Filter by `_catalogi`) | Implemented |
| REQ-SCH-004 (Pagination) | Implemented |
| REQ-SCH-005 (Ordering) | Implemented |
| REQ-SCH-007 (Federation) | Implemented (via PublicationService) |
| REQ-SCH-008 (Facet merging) | Implemented (via PublicationService) |
| REQ-SCH-009 (Complex query parsing) | Implemented (via ObjectService) |
| REQ-SCH-011 (Dead code removal) | **Not yet done** — five methods present with no routes |
| REQ-SCH-012 (Filter syntax) | Implemented |
| REQ-SCH-014 (Bracket notation) | Implemented (via ObjectService) |
| REQ-SCH-015 (Strip underscore params) | Implemented (via ObjectService) |

## Dependencies

- **PublicationService** (`lib/Service/PublicationService.php`) — `index()` for local search, `getAggregatedPublications()` for federated search with facet merging
- **OpenRegister ObjectService** — `buildSearchQuery()` for query normalisation, `searchObjectsPaginated()` for paginated execution
- **DirectoryService** — provides remote listing data for federation (consumed by PublicationService)
- **GuzzleHttp** — async HTTP client for remote directory queries (used by PublicationService)
