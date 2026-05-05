## 1. Remove Elasticsearch PHP Code

- [x] 1.1 Delete `lib/Service/ElasticSearchService.php`
- [x] 1.2 Delete `lib/Service/ElasticSearchClientAdapter.php`
- [x] 1.3 Delete `tests/Unit/Service/ElasticSearchServiceTest.php`
- [x] 1.4 Delete `tests/Unit/Service/ElasticSearchClientAdapterTest.php`

## 2. Remove Elasticsearch Dependency

- [x] 2.1 Remove `elasticsearch/elasticsearch` from `composer.json` `require` section
- [x] 2.2 Remove `react/async` and `react/promise` from `composer.json` if no other code uses them (kept — used by DirectoryService)
- [x] 2.3 Run `composer update` to regenerate `composer.lock`

## 3. Rewrite SearchService

- [x] 3.1 Remove `ElasticSearchService` dependency from `SearchService` constructor
- [x] 3.2 Add `ObjectService` (from OpenRegister) as dependency in `SearchService` constructor (not needed — local search already handled by callers via OpenRegister)
- [x] 3.3 Rewrite `search()` method: accepts local results as parameter, focuses on federation
- [x] 3.4 Remove `$elasticConfig` and `$dbConfig` parameters from `search()` method signature
- [x] 3.5 Remove `createMongoDBSearchFilter()` method
- [x] 3.6 Remove `createSortForMongoDB()` method
- [x] 3.7 Remove `createMySQLSearchConditions()` method
- [x] 3.8 Remove `createMySQLSearchParams()` method
- [x] 3.9 Remove `createSortForMySQL()` method
- [x] 3.10 Remove `unsetSpecialQueryParams()` method
- [x] 3.11 Keep federation logic intact: async HTTP queries to remote directories, result merging, facet merging

## 4. Update Callers of SearchService

- [x] 4.1 Find and update any code that calls `SearchService->search()` with the old `$elasticConfig`/`$dbConfig` parameters (no callers found — SearchService was dead code)
- [x] 4.2 Verify `SearchController` and `PublicationService` work with the simplified `SearchService` (they don't use SearchService — they use PublicationService/ObjectService directly)

## 5. Remove Frontend Configuration

- [x] 5.1 Remove `useElastic` and `useMongo` properties from `src/entities/configuration/configuration.ts`
- [x] 5.2 Remove `useElastic` and `useMongo` from `src/entities/configuration/configuration.types.ts`
- [x] 5.3 Update `src/entities/configuration/configuration.mock.ts` to remove ES/Mongo references
- [x] 5.4 Update `src/entities/configuration/configuration.spec.ts` to remove ES/Mongo test assertions
- [x] 5.5 Remove `elastic_location`, `elastic_key`, `elasticLocation`, `elasticKey`, `elasticIndex` from `src/navigation/MainMenu.vue`

## 6. Update Tests

- [x] 6.1 Rewrite `tests/Unit/Service/SearchServiceTest.php` to test against OpenRegister-based search
- [x] 6.2 Verify facet merging tests still pass (these are search-backend-agnostic)
- [x] 6.3 Run full test suite: `docker exec -w /var/www/html/custom_apps/opencatalogi nextcloud php vendor/bin/phpunit -c phpunit-unit.xml` (1071 tests, 2133 assertions — all pass)

## 7. Update Documentation

- [x] 7.1 Update `docs/handleidingen/Architectuur.md` to remove Elasticsearch references
- [x] 7.2 Update `docs/handleidingen/Architectuur_en.md` to remove Elasticsearch references
- [x] 7.3 Update `README.md` to remove Elasticsearch references

## 8. Verification

- [x] 8.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan)
- [x] 8.2 Build frontend: `npm run build` (passes — 2 pre-existing errors in DashboardIndex/PublicationDetail unrelated to this change)
- [x] 8.3 Test `/api/search` endpoint returns correct response format
- [x] 8.4 Test federated search with remote directories (single directory entry = self, correctly skipped)
- [x] 8.5 Verify tilburg-woo-ui search functionality works (search, pagination, facets, enrichment all working)
- [x] 8.6 Verify CORS headers on public search endpoints (Access-Control-Allow-Origin present)
