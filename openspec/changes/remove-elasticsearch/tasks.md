## 1. Remove Elasticsearch PHP Code

- [ ] 1.1 Delete `lib/Service/ElasticSearchService.php`
- [ ] 1.2 Delete `lib/Service/ElasticSearchClientAdapter.php`
- [ ] 1.3 Delete `tests/Unit/Service/ElasticSearchServiceTest.php`
- [ ] 1.4 Delete `tests/Unit/Service/ElasticSearchClientAdapterTest.php`

## 2. Remove Elasticsearch Dependency

- [ ] 2.1 Remove `elasticsearch/elasticsearch` from `composer.json` `require` section
- [ ] 2.2 Remove `react/async` and `react/promise` from `composer.json` if no other code uses them
- [ ] 2.3 Run `composer update` to regenerate `composer.lock`

## 3. Rewrite SearchService

- [ ] 3.1 Remove `ElasticSearchService` dependency from `SearchService` constructor
- [ ] 3.2 Add `ObjectService` (from OpenRegister) as dependency in `SearchService` constructor
- [ ] 3.3 Rewrite `search()` method: replace ES call with `ObjectService::searchObjectsPaginated()` for local results
- [ ] 3.4 Remove `$elasticConfig` and `$dbConfig` parameters from `search()` method signature
- [ ] 3.5 Remove `createMongoDBSearchFilter()` method
- [ ] 3.6 Remove `createSortForMongoDB()` method
- [ ] 3.7 Remove `createMySQLSearchConditions()` method
- [ ] 3.8 Remove `createMySQLSearchParams()` method
- [ ] 3.9 Remove `createSortForMySQL()` method
- [ ] 3.10 Remove `unsetSpecialQueryParams()` method
- [ ] 3.11 Keep federation logic intact: async HTTP queries to remote directories, result merging, facet merging

## 4. Update Callers of SearchService

- [ ] 4.1 Find and update any code that calls `SearchService->search()` with the old `$elasticConfig`/`$dbConfig` parameters
- [ ] 4.2 Verify `SearchController` and `PublicationService` work with the simplified `SearchService`

## 5. Remove Frontend Configuration

- [ ] 5.1 Remove `useElastic` and `useMongo` properties from `src/entities/configuration/configuration.ts`
- [ ] 5.2 Remove `useElastic` and `useMongo` from `src/entities/configuration/configuration.types.ts`
- [ ] 5.3 Update `src/entities/configuration/configuration.mock.ts` to remove ES/Mongo references
- [ ] 5.4 Update `src/entities/configuration/configuration.spec.ts` to remove ES/Mongo test assertions
- [ ] 5.5 Remove `elastic_location`, `elastic_key`, `elasticLocation`, `elasticKey`, `elasticIndex` from `src/navigation/MainMenu.vue`

## 6. Update Tests

- [ ] 6.1 Rewrite `tests/Unit/Service/SearchServiceTest.php` to test against OpenRegister-based search
- [ ] 6.2 Verify facet merging tests still pass (these are search-backend-agnostic)
- [ ] 6.3 Run full test suite: `docker exec -w /var/www/html/custom_apps/opencatalogi nextcloud php vendor/bin/phpunit -c phpunit-unit.xml`

## 7. Update Documentation

- [ ] 7.1 Update `docs/handleidingen/Architectuur.md` to remove Elasticsearch references
- [ ] 7.2 Update `docs/handleidingen/Architectuur_en.md` to remove Elasticsearch references
- [ ] 7.3 Update `README.md` to remove Elasticsearch references

## 8. Verification

- [ ] 8.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan)
- [ ] 8.2 Build frontend: `npm run build`
- [ ] 8.3 Test `/api/search` endpoint returns correct response format
- [ ] 8.4 Test federated search with remote directories
- [ ] 8.5 Verify tilburg-woo-ui search functionality works
- [ ] 8.6 Verify CORS headers on public search endpoints
