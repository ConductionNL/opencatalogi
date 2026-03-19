## Why

OpenCatalogi should be a thin federation client on top of OpenRegister, not a search engine. Currently it bundles Elasticsearch as a direct dependency (`elasticsearch/elasticsearch` v8.14.0), duplicating search infrastructure that belongs in OpenRegister. OpenRegister already provides `ObjectService::searchObjectsPaginated()` which `PublicationsController` uses for catalog-based search. The ES layer in OpenCatalogi is unused dead weight — no controller actually calls `SearchService->search()` through the ES path in the current codebase. Removing it simplifies the architecture, reduces the dependency footprint, and establishes a clear boundary: OpenRegister owns search/indexing, OpenCatalogi owns federation.

## What Changes

- **BREAKING** Remove `ElasticSearchService.php` and `ElasticSearchClientAdapter.php` entirely
- **BREAKING** Remove `elasticsearch/elasticsearch` from `composer.json` dependencies
- **BREAKING** Remove `useElastic`/`useMongo` configuration flags from frontend (`configuration.ts`, `configuration.types.ts`, `configuration.mock.ts`, `configuration.spec.ts`)
- **BREAKING** Remove ES config fields (`elastic_location`, `elastic_key`, `elasticLocation`, `elasticKey`, `elasticIndex`) from `MainMenu.vue`
- Rewrite `SearchService` to use OpenRegister's `ObjectService` for local search instead of Elasticsearch, keeping federation logic (async remote directory queries + result/facet merging) intact
- Remove MongoDB search helpers from `SearchService` (`createMongoDBSearchFilter`, `createSortForMongoDB`) — OpenRegister handles this
- Remove MySQL search helpers from `SearchService` (`createMySQLSearchConditions`, `createMySQLSearchFilter`, `createMySQLSearchParams`, `createSortForMySQL`) — OpenRegister handles this
- Delete ES-related test files (`ElasticSearchServiceTest.php`, `ElasticSearchClientAdapterTest.php`)
- Rewrite `SearchServiceTest.php` for the new OpenRegister-based implementation
- Update architecture documentation to reflect OpenRegister as the search backend

## Capabilities

### New Capabilities
- `openregister-federated-search`: Federated search that combines local OpenRegister results with remote directory results. Replaces the current Elasticsearch-based local search with OpenRegister API calls while preserving the async federation layer.

### Modified Capabilities
_(none — no existing spec-level requirements are changing, only implementation)_

## Impact

- **Dependencies**: Removes `elasticsearch/elasticsearch` (~50+ transitive packages), `react/async`, `react/promise` from composer.json. Significant reduction in vendor size.
- **PHP code**: 3 files deleted (`ElasticSearchService.php`, `ElasticSearchClientAdapter.php`, 2 test files), 1 file rewritten (`SearchService.php`), 1 test file rewritten
- **Frontend**: Configuration entity simplified (remove `useElastic`/`useMongo`), MainMenu cleaned up
- **API**: `/api/search` endpoint behavior unchanged from consumer perspective — still returns `{results, facets, count, limit, page, pages, total}`. Federation behavior preserved.
- **Docker**: No OpenCatalogi Docker changes needed — ES/Solr profiles live in OpenRegister's docker-compose and remain there as future OpenRegister features
- **Breaking for**: Anyone relying on direct ES configuration in OpenCatalogi app settings. Migration path: configure search in OpenRegister instead.
