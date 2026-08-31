---
kind: code
depends_on: []
---

# Proposal: search

## Summary

Specify and clean up the internal search API in OpenCatalogi. The `SearchController` exposes a single authenticated endpoint (`GET /api/search`) that delegates all query logic to `PublicationService`, which in turn uses OpenRegister's `ObjectService` for paginated, faceted full-text search across all catalogs. This change documents the implemented search infrastructure, removes five dead-code methods from `SearchController` that have no registered routes, and closes the specification gap around filter syntax, federation, and facet merging.

## Motivation

The `SearchController` contains five methods (`show`, `attachments`, `download`, `uses`, `used`) that delegate to `PublicationService` but have no corresponding route entries in `routes.php`. They are completely unreachable via HTTP and create a misleading picture of the controller's surface area. Additionally, the search feature lacks a formal OpenSpec spec linking requirements (SCH-001–SCH-015) to implementation, making it impossible to gate the controller against the route-reachability and semantic-auth quality gates.

A secondary concern: the spec previously listed a `SearchService` and `ElasticSearchService` that do not exist in the codebase. All search and federation logic lives in `PublicationService` (delegating to OpenRegister's `ObjectService`). The specification must reflect this accurately so that future contributors do not attempt to locate or implement non-existent classes.

## Scope

- Remove dead-code methods from `SearchController` (`show`, `attachments`, `download`, `uses`, `used`)
- Verify `SearchController::index()` route and annotations match ADR-005 (authenticated, no CORS)
- Document the filter/query parameter contract (`_search`, `_order`, `_limit`, `_page`, `_offset`, `_queries`, `_catalogi`)
- Document federation flow: `PublicationService::getAggregatedPublications()` + async GuzzleHttp calls to remote directories
- Document facet merging algorithm in `PublicationService`
- Update front-end search components (`SearchIndex.vue`, `SearchResults.vue`, `SearchSideBar.vue`, `FacetComponent.vue`) to use proper i18n wrapping (ADR-007)
- Add `@spec` PHPDoc tags to `SearchController` and `PublicationService` search methods (ADR-003)

## Out of Scope

- ElasticSearch integration (SCH-006) — no `ElasticSearchService` exists; deferred to a separate spec if ever needed
- Adding routes for the removed dead-code methods — those capabilities are already covered by `PublicationsController`'s public endpoints
- Changes to OpenRegister's `ObjectService` or `searchObjectsPaginated()` internals

## Success Criteria

- `SearchController` contains only `index()` and its constructor; all dead-code methods removed
- `GET /api/search` returns paginated publication results with facets for an authenticated request
- `GET /api/search?_search=klimaat` returns results filtered by full-text term
- Federation merges results and facets from remote directories with `default: true`
- `npm run check:l10n` passes with zero MISSING or UNWRAPPED keys for search UI strings
- All Hydra quality gates pass (route-auth, semantic-auth, SPDX, forbidden-patterns)
