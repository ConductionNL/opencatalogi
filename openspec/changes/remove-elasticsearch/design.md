## Context

OpenCatalogi currently bundles Elasticsearch as a direct dependency for local search. However, `PublicationsController` already uses `ObjectService::searchObjectsPaginated()` from OpenRegister for catalog-based search — the ES path through `SearchService->search()` is not called by any controller. The codebase has evolved past ES but the dead code and dependency remain.

OpenRegister provides full-text search, filtering, pagination, and faceting via its `ObjectService`. OpenCatalogi's role is federation: combining local OpenRegister results with async queries to remote catalog directories.

**Current search data flow:**
1. `SearchController->index()` → `PublicationService->index()` → OpenRegister (no ES)
2. `PublicationsController->index()` → `ObjectService->searchObjectsPaginated()` → OpenRegister (no ES)
3. `SearchService->search()` → ES local + federation (dead code — no caller)

**Target search data flow:**
1. `SearchController->index()` → `PublicationService->index()` → OpenRegister (unchanged)
2. `PublicationsController->index()` → `ObjectService->searchObjectsPaginated()` → OpenRegister (unchanged)
3. `SearchService->search()` → OpenRegister local + federation (rewritten)

## Goals / Non-Goals

**Goals:**
- Remove all Elasticsearch code and dependencies from OpenCatalogi
- Rewrite `SearchService` to use OpenRegister's `ObjectService` for local search
- Preserve federated search behavior (async remote directory queries + result/facet merging)
- Maintain the `/api/search` response contract (`{results, facets, count, limit, page, pages, total}`)
- Remove frontend configuration flags for ES/MongoDB
- Clean separation: OpenCatalogi = federation logic, OpenRegister = search/indexing

**Non-Goals:**
- Adding Elasticsearch/Solr support to OpenRegister (separate future initiative)
- Changing the federation protocol or directory sync behavior
- Modifying `PublicationsController` or `PublicationService` (already using OpenRegister)
- Changing the public search API contract

## Decisions

### 1. Delete ES files entirely rather than deprecate
**Decision:** Remove `ElasticSearchService.php`, `ElasticSearchClientAdapter.php`, and their tests immediately.
**Rationale:** No controller calls these classes. They're dead code. A deprecation period adds complexity with zero benefit since there are no external consumers.
**Alternative considered:** Marking `@deprecated` and removing in next major version — rejected because no code path reaches these classes.

### 2. Rewrite SearchService to delegate local search to OpenRegister
**Decision:** Replace the `ElasticSearchService` dependency in `SearchService` with OpenRegister's `ObjectService`. The `search()` method will call `ObjectService::searchObjectsPaginated()` for local results.
**Rationale:** OpenRegister already handles full-text search, filtering, pagination, and faceting. OpenCatalogi should not duplicate this.
**Alternative considered:** Removing `SearchService` entirely — rejected because it still owns the federation logic (async HTTP to remote directories, facet merging).

### 3. Remove DB-specific helpers from SearchService
**Decision:** Remove `createMongoDBSearchFilter`, `createMySQLSearchConditions`, `createMySQLSearchParams`, `createSortForMySQL`, `createSortForMongoDB`, and `unsetSpecialQueryParams` from `SearchService`.
**Rationale:** These methods build database-specific queries that belong in OpenRegister, not in a federation client. `PublicationsController` already uses `ObjectService::buildSearchQuery()` for this purpose.
**Alternative considered:** Keeping them as utility methods — rejected because they're tightly coupled to database internals that OpenCatalogi shouldn't know about.

### 4. Remove useElastic/useMongo configuration flags
**Decision:** Remove these flags from the frontend `Configuration` entity entirely.
**Rationale:** OpenCatalogi no longer manages search backends. The choice of search backend (MySQL, PostgreSQL, future ES/Solr) is an OpenRegister concern configured at the OpenRegister level.

### 5. Simplify SearchService constructor
**Decision:** `SearchService` will depend only on `DirectoryService`, `IURLGenerator`, and OpenRegister's `ObjectService`. Remove `ElasticSearchService` dependency and the `$elasticConfig`/`$dbConfig` parameters from `search()`.
**Rationale:** The search method should take search parameters and return federated results. Backend configuration is an OpenRegister concern.

## Risks / Trade-offs

- **[Risk] Existing deployments with ES configured** → No migration needed since no code path actually uses the ES configuration in the current codebase. App settings keys can be left in the database (harmless) or cleaned up via a repair step.
- **[Risk] Search performance regression** → Mitigated: OpenRegister's `searchObjectsPaginated` is already the primary search path used by `PublicationsController`. No new code path, just removing the unused alternative.
- **[Risk] Facet format mismatch between OpenRegister and federation** → The merged results must use a consistent facet format. OpenRegister returns facets in its own format; the federation merge logic may need minor adaptation to normalize formats.
- **[Trade-off] Reduced search flexibility** → OpenCatalogi loses the ability to configure ES directly. This is intentional — search configuration moves to OpenRegister where it belongs.
