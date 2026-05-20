# Tasks: federation

## 1. Backend — FederationController

- [ ] 1.1 Verify `FederationController.php` has all 6 methods: `publications()`, `publication()`, `publicationUses()`, `publicationUsed()`, `publicationAttachments()`, `publicationDownload()`
- [ ] 1.2 Verify all 6 methods carry `@PublicPage`, `@NoCSRFRequired`, and `@NoAdminRequired` annotations
- [ ] 1.3 Verify `publications()` delegates to `PublicationService::getAggregatedPublications()` with `queryParams`, `requestParams`, and `baseUrl`
- [ ] 1.4 Verify `publication()` delegates to `PublicationService::getFederatedPublication($id)`
- [ ] 1.5 Verify `publicationUses()` delegates to `PublicationService::getFederatedUses($id)`
- [ ] 1.6 Verify `publicationUsed()` delegates to `PublicationService::getFederatedUsed($id)`
- [ ] 1.7 Verify `publicationAttachments()` delegates to `PublicationService::attachments($id)` (local-only)
- [ ] 1.8 Verify `publicationDownload()` delegates to `PublicationService::download($id)` (local-only)

## 2. Backend — PublicationService Aggregation

- [ ] 2.1 Verify `getAggregatedPublications()` queries local catalogs via ObjectService
- [ ] 2.2 Verify `getAggregatedPublications()` fetches remote directory URLs from DirectoryService
- [ ] 2.3 Verify remote queries are executed in parallel via `GuzzleHttp\Promise\Utils::settle()`
- [ ] 2.4 Verify the local instance URL is skipped during remote iteration
- [ ] 2.5 Verify only Listings with `integrationLevel: "search"` and `default: true` are queried remotely
- [ ] 2.6 Verify facet merging sums `count` values for buckets with the same `_id`
- [ ] 2.7 Verify merged results are sorted by `_score` descending using `usort()`
- [ ] 2.8 Verify `getFederatedPublication()` searches local catalogs first, falls back to remote directories, returns 404 if not found anywhere
- [ ] 2.9 Verify a single remote failure (timeout or HTTP error) does not propagate as a 500 on the federation endpoint

## 3. Backend — Routes

- [ ] 3.1 Verify `appinfo/routes.php` contains `federation#publications` at `GET /api/federation/publications`
- [ ] 3.2 Verify `appinfo/routes.php` contains `federation#publication` at `GET /api/federation/publications/{id}`
- [ ] 3.3 Verify `appinfo/routes.php` contains `federation#publicationUses` at `GET /api/federation/publications/{id}/uses`
- [ ] 3.4 Verify `appinfo/routes.php` contains `federation#publicationUsed` at `GET /api/federation/publications/{id}/used`
- [ ] 3.5 Verify `appinfo/routes.php` contains `federation#publicationAttachments` at `GET /api/federation/publications/{id}/attachments`
- [ ] 3.6 Verify `appinfo/routes.php` contains `federation#publicationDownload` at `GET /api/federation/publications/{id}/download`

## 4. Unit Tests (ADR-008)

- [ ] 4.1 Test `publications()` returns 200 with merged result set when local + remote sources are available
- [ ] 4.2 Test `publications()` returns 200 with only local results when no remote Listings are configured
- [ ] 4.3 Test `publication()` returns 200 when publication found locally
- [ ] 4.4 Test `publication()` returns 200 when publication found on remote directory (local miss)
- [ ] 4.5 Test `publication()` returns 404 when publication not found in any source
- [ ] 4.6 Test `publicationAttachments()` returns local file metadata (no remote lookup)
- [ ] 4.7 Test `publicationDownload()` returns local download response (no remote lookup)
- [ ] 4.8 Test that remote failure (GuzzleHttp exception) is handled gracefully — partial results returned, no 500
- [ ] 4.9 Test facet merging sums bucket counts correctly for duplicate `_id` values
- [ ] 4.10 Test merged results are sorted by `_score` descending

## 5. Integration / API Tests (ADR-008)

- [ ] 5.1 Smoke-test `GET /api/federation/publications` without authentication — verify 200 (not 401/403)
- [ ] 5.2 Smoke-test `GET /api/federation/publications/{id}` without authentication — verify 200 or 404 (not 401/403)
- [ ] 5.3 Verify CORS headers are present on all 6 federation endpoints (ADR-002)
- [ ] 5.4 Test with tilburg-woo-ui frontend consuming the federation endpoint to verify pagination and facet compatibility

## 6. Documentation (ADR-009)

- [ ] 6.1 Feature documentation at `docs/features/federation.md` covering endpoint descriptions, aggregation flow, and configuration of remote Listings

## 7. Internationalization (ADR-007)

- [ ] 7.1 Verify error messages in FederationController use `$this->l10n->t()` wrapping — federation endpoints return machine-readable JSON errors, no user-facing UI strings
