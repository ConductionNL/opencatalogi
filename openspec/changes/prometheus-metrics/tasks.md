# Tasks: prometheus-metrics

## 0. Deduplication Check

- [x] 0.1 Verify no overlap with OpenRegister MetricsService — confirm OpenCatalogi's direct SQL aggregate approach is intentional (avoids ObjectService hydration overhead) and document finding
- [x] 0.2 Confirm `DirectoryService` exposes entry count and last reachability status before adding federation metrics; if not, determine which service or table to query directly
- [x] 0.3 Confirm `ElasticSearchService` exposes a ping/status method with configurable timeout before wiring it into HealthController

## 1. MetricsController Enhancements

- [x] 1.1 Inject `IConfig` into `MetricsController` constructor alongside the existing `IAppManager`; add `nextcloud_version` label to `opencatalogi_info` gauge using `IConfig::getSystemValueString('version', 'unknown')`
- [x] 1.2 Make `opencatalogi_up` reflect actual database health: wrap a `SELECT 1` in try/catch; on exception emit `opencatalogi_up 0` and set all DB-dependent metric values to 0; on success emit `opencatalogi_up 1` and proceed normally
  - N/A — superseded: `/api/metrics` and `/api/health` are served by OpenRegister's AppHost engine (`GenericMetrics#index` / `GenericHealth#index`, see the `observability` block of `src/manifest.json`); the hand-written MetricsController and HealthController this task names were deleted on adoption. `opencatalogi_up` is engine-owned (always 1 once the request is served), a documented choice of the adoption; the database health is the declarative `database` check (critical → HTTP 503).
- [x] 1.3 Add `opencatalogi_directory_entries_total` gauge: query total directory entry count via DirectoryService (or direct DB query against the directory entries table); emit 0 when table is absent or service unavailable
- [x] 1.4 Add `opencatalogi_federation_reachable_total` and `opencatalogi_federation_unreachable_total` gauges: split directory entries by last reachability status (reachable vs error/timeout); emit 0 for both when no entries exist
- [x] 1.5 Verify all new label values pass through the existing `sanitizeLabel()` method — confirm backslash doubling, quote escaping, and newline-to-`\n` replacement apply to any federation-sourced strings
- [x] 1.6 Add `@spec openspec/changes/prometheus-metrics/tasks.md#1` PHPDoc tag to `MetricsController` class header and to each modified method per ADR-003

## 2. HealthController Enhancements

- [x] 2.1 Inject `ElasticSearchService` (nullable) and `IAppConfig` into `HealthController` constructor
  - N/A — OpenCatalogi ships no ElasticSearchService; the search backend is reached through `SearchBackendHealthProvider` (IAppConfig + IClientService) instead.
- [x] 2.2 Add `checks.search_backend` to the health response: when `search_backend` config value is `"elasticsearch"`, attempt ElasticSearch ping with 3-second timeout; include `type` (`"elasticsearch"` or `"database"`) and `status` (`"ok"` or error message string)
  - Built as `lib/Observability/SearchBackendHealthProvider.php`, registered as `IHealthCheckProvider::opencatalogi`, so the engine merges `checks.search_backend` into the health response: `search_backend` app config `database` (default) passes; `elasticsearch` pings `search_backend_url` with a 3-second timeout and degrades on failure with a generic message. The engine's check entries carry ok/severity/message, so the backend type is exposed through `backendType()`, not a `type` field.
- [x] 2.3 Differentiate filesystem failure from database failure in the response tier logic:
  - DB fail → HTTP 503, `status: "error"`
  - DB OK + filesystem fail → HTTP 200, `status: "degraded"`
  - DB OK + filesystem OK → HTTP 200, `status: "ok"`
- [x] 2.4 Add 3-second timeout to each individual check (database SELECT 1, filesystem write, search backend ping) using try/catch with timeout guard; ensure overall response is within 5 seconds even when all checks are slow
  - N/A — superseded: `/api/metrics` and `/api/health` are served by OpenRegister's AppHost engine (`GenericMetrics#index` / `GenericHealth#index`, see the `observability` block of `src/manifest.json`); the hand-written MetricsController and HealthController this task names were deleted on adoption. Per-check execution and timeouts are the engine's; the search-backend ping has its own 3-second connect and request timeout.
- [x] 2.5 Add `@spec openspec/changes/prometheus-metrics/tasks.md#2` PHPDoc tag to `HealthController` class header and modified methods per ADR-003
  - Done on the class that now carries the behaviour: `SearchBackendHealthProvider` and its methods have `@spec openspec/changes/prometheus-metrics/tasks.md#2`.

## 3. Unit Tests (ADR-008)

- [x] 3.1 `MetricsControllerTest`: add test asserting `opencatalogi_info` output contains `nextcloud_version="..."` label with a non-empty value
  - N/A — superseded: `/api/metrics` and `/api/health` are served by OpenRegister's AppHost engine (`GenericMetrics#index` / `GenericHealth#index`, see the `observability` block of `src/manifest.json`); the hand-written MetricsController and HealthController this task names were deleted on adoption. The info gauge is rendered by the engine.
- [x] 3.2 `MetricsControllerTest`: add test for DB exception path — mock `IDBConnection` to throw, assert `opencatalogi_up 0` and that no PHP errors appear in output
  - N/A — superseded: `/api/metrics` and `/api/health` are served by OpenRegister's AppHost engine (`GenericMetrics#index` / `GenericHealth#index`, see the `observability` block of `src/manifest.json`); the hand-written MetricsController and HealthController this task names were deleted on adoption.
- [x] 3.3 `MetricsControllerTest`: add test asserting `opencatalogi_directory_entries_total`, `opencatalogi_federation_reachable_total`, and `opencatalogi_federation_unreachable_total` are present with numeric values
- [x] 3.4 `MetricsControllerTest`: add test asserting label sanitization: input with backslash → doubled, input with double-quote → escaped, input with newline → `\n` in output
  - N/A — superseded: `/api/metrics` and `/api/health` are served by OpenRegister's AppHost engine (`GenericMetrics#index` / `GenericHealth#index`, see the `observability` block of `src/manifest.json`); the hand-written MetricsController and HealthController this task names were deleted on adoption. Label escaping is the engine's PrometheusRenderer.
- [x] 3.5 `HealthControllerTest`: add test for all-checks-pass → HTTP 200 + `status: "ok"` + `checks.database`, `checks.filesystem`, and `checks.search_backend` all present
  - Covered for the part this app owns: `tests/Unit/Observability/SearchBackendHealthProviderTest.php` (search_backend present and passing); the database and filesystem checks are declarative engine checks.
- [x] 3.6 `HealthControllerTest`: add test for DB failure → HTTP 503 + `status: "error"` + `checks.database` contains failure message (not stack trace)
  - N/A — superseded: `/api/metrics` and `/api/health` are served by OpenRegister's AppHost engine (`GenericMetrics#index` / `GenericHealth#index`, see the `observability` block of `src/manifest.json`); the hand-written MetricsController and HealthController this task names were deleted on adoption. The database check and the 503 tier are the engine's (`statusCodePolicy: adr006`).
- [x] 3.7 `HealthControllerTest`: add test for filesystem failure + DB OK → HTTP 200 + `status: "degraded"` + `checks.filesystem` contains failure message
  - N/A — superseded: `/api/metrics` and `/api/health` are served by OpenRegister's AppHost engine (`GenericMetrics#index` / `GenericHealth#index`, see the `observability` block of `src/manifest.json`); the hand-written MetricsController and HealthController this task names were deleted on adoption. The filesystem check (severity degraded) and the degraded tier are the engine's.
- [x] 3.8 `HealthControllerTest`: add test asserting `checks.search_backend.type` is `"database"` when ElasticSearch is not configured
  - Done: `SearchBackendHealthProviderTest::testTheDefaultDatabaseBackendPassesWithoutAPing` asserts the backend is `database` when Elasticsearch is not configured.

## 4. Documentation (ADR-009)

- [x] 4.1 Create `docs/features/prometheus-metrics.md` covering:
  - Endpoint descriptions (`GET /api/metrics`, `GET /api/health`)
  - Example Prometheus text output snippet showing all metric families
  - Example health check JSON for each status tier (ok, degraded, error)
  - Full metrics catalogue table: metric name, type, labels, description
  - Grafana integration notes for Dutch municipality Prometheus scrape config
- [ ] 4.2 If screenshots of the metrics output are available from a running instance, include them in `docs/features/` alongside the documentation

## 5. Internationalization (ADR-007)

- [x] 5.1 Confirm no user-facing strings are added to `MetricsController` or `HealthController` (both endpoints return machine-readable formats only) — no `t()` calls required; mark N/A if confirmed

## 6. Smoke Tests (ADR-008)

- [ ] 6.1 Call `GET /index.php/apps/opencatalogi/api/metrics` as admin via curl — verify `Content-Type: text/plain; version=0.0.4` header and presence of `# HELP` and `# TYPE` lines for all metric families
- [ ] 6.2 Call `GET /index.php/apps/opencatalogi/api/metrics` unauthenticated — verify HTTP 401 or redirect to login and no metrics data in response body
- [ ] 6.3 Call `GET /index.php/apps/opencatalogi/api/health` — verify JSON structure with `status`, `version`, and `checks` keys; verify `checks.search_backend` is present
- [ ] 6.4 Parse metrics output and verify `opencatalogi_info` contains `nextcloud_version`, `php_version`, and `version` labels with non-empty values
- [ ] 6.5 Parse metrics output and verify `opencatalogi_up 1` on a healthy system
- [ ] 6.6 Parse metrics output and verify `opencatalogi_directory_entries_total`, `opencatalogi_federation_reachable_total`, and `opencatalogi_federation_unreachable_total` are present (values may be 0 in a dev environment without federation)
