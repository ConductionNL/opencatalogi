# Design: prometheus-metrics

## Context

OpenCatalogi already has `MetricsController.php` and `HealthController.php` exposing Prometheus metrics at `GET /api/metrics` and health checks at `GET /api/health`. Routes are registered in `appinfo/routes.php`. Both controllers use direct aggregate SQL queries against OpenRegister tables (`openregister_objects`, `openregister_schemas`, `openregister_metrics`) via Nextcloud's `IDBConnection`.

**What exists in MetricsController:**
- `opencatalogi_info` gauge with `version` and `php_version` labels (missing `nextcloud_version`)
- `opencatalogi_up` gauge (hard-coded to 1, does not reflect actual database health)
- `opencatalogi_publications_total` by status and catalog (JSON_EXTRACT queries with GROUP BY)
- `opencatalogi_catalogs_total` (schema title LIKE pattern match)
- `opencatalogi_listings_total` by status (JSON_EXTRACT queries with GROUP BY)
- `opencatalogi_search_requests_total` (from `openregister_metrics` table, graceful fallback on missing table)
- Prometheus label sanitization (backslash doubling, quote escaping, newline replacement)
- Proper `Content-Type: text/plain; version=0.0.4; charset=utf-8` header

**What exists in HealthController:**
- Database connectivity check via `SELECT 1`
- Filesystem write check on temp directory
- App version in JSON response
- HTTP 200 for OK, HTTP 503 for database error (missing HTTP 200 + "degraded" for filesystem-only failure)

**Key gaps to close:**
- No `nextcloud_version` label on `opencatalogi_info`
- `opencatalogi_up` always 1 regardless of actual DB state
- No federation metrics (directory entries, reachable/unreachable counts)
- No search backend health check in HealthController
- No "degraded" status path (filesystem fail + DB OK)
- No per-check timeouts in HealthController

**Constraints:**
- Metrics response in Prometheus text exposition format (text/plain; version=0.0.4; charset=utf-8)
- Health check returns JSON; HTTP 200 for OK/degraded, HTTP 503 for error
- Admin authentication required for metrics endpoint (Nextcloud default — no annotation needed per ADR-005)
- Health endpoint is public (`#[PublicPage] #[NoCSRFRequired]`) — no metrics data exposed there
- No custom auth flows (ADR-005)
- All DB queries via injected `IDBConnection` — no direct Doctrine calls

## Goals / Non-Goals

**Goals:**
- Add `nextcloud_version` label to `opencatalogi_info` via `IConfig::getSystemValueString('version', 'unknown')`
- Make `opencatalogi_up` reflect actual database health (try/catch around SELECT 1; emit 0 on failure)
- Add `opencatalogi_directory_entries_total` gauge via DirectoryService
- Add `opencatalogi_federation_reachable_total` and `opencatalogi_federation_unreachable_total` gauges via DirectoryService reachability data
- Add `checks.search_backend` to HealthController response with backend type and reachability
- Return HTTP 200 + `status: "degraded"` when filesystem fails but database is OK
- Enforce 3-second timeout per individual health check; overall response within 5 seconds
- Metrics endpoint responds within 2 seconds under normal load

**Non-Goals:**
- Request duration histograms (`request_duration_seconds`) — requires middleware instrumentation beyond controller scope
- Error count metrics (`errors_total`) — no request-level error tracking infrastructure exists yet
- Caching of metrics results — spec requires live data on every call
- Grafana dashboard provisioning or alerting rule templates
- Prometheus push gateway integration

## Decisions

### 1. `opencatalogi_up` reflects real DB health
Wrap a `SELECT 1` in try/catch inside `MetricsController::metrics()`. On exception: emit `opencatalogi_up 0`, set all database-dependent metrics to 0, and return a valid Prometheus response. This matches the OpenRegister HeartbeatController pattern.

### 2. `nextcloud_version` via `IConfig`
`IConfig::getSystemValueString('version', 'unknown')` returns the Nextcloud server version (e.g. `28.0.4`). This is distinct from the app version (via `IAppManager`) already present. Both are labels on `opencatalogi_info`. Fall back to `'unknown'` on any retrieval failure — the metric is always emitted.

### 3. Federation metrics via DirectoryService
`DirectoryService` (existing OpenCatalogi service) manages the federation directory. It exposes the directory entries and their last reachability status. The metrics controller queries total entries, reachable entries (last status = ok), and unreachable entries (last status = error/timeout). If DirectoryService is unavailable or the directory table does not exist, all three gauges emit 0.

### 4. Search backend health check with timeout
HealthController injects `ElasticSearchService` (optional, nullable) and `IAppConfig`. If ElasticSearch is configured (`IAppConfig::getValueString(Application::APP_ID, 'search_backend') === 'elasticsearch'`), attempt a ping with a 3-second timeout using `set_time_limit` or an HTTP client timeout. Include `checks.search_backend` in every response — `type` is `"elasticsearch"` or `"database"`, `status` is `"ok"` or the error message.

### 5. Degraded status for partial failure
Three response tiers:
- DB OK + filesystem OK → HTTP 200, `status: "ok"`
- DB OK + filesystem FAIL → HTTP 200, `status: "degraded"`
- DB FAIL → HTTP 503, `status: "error"` (filesystem state irrelevant)

This matches the spec scenario requirements and the OpenRegister HeartbeatController degraded pattern.

### 6. No seed data required
This change modifies only controller/service logic with no new OpenRegister schemas or registers. Per ADR-001, seed data is not required for changes that only modify non-schema backend logic.

## Reuse Analysis

Per ADR-001 deduplication requirements:

| Component | Source | How reused |
|---|---|---|
| `IDBConnection` | Nextcloud | Already injected in both controllers; all new DB queries use the same instance |
| `IAppManager` | Nextcloud | Already injected in MetricsController for `version` label; unchanged |
| `IConfig` | Nextcloud | Injected additionally in MetricsController for `nextcloud_version` system value |
| `DirectoryService` | OpenCatalogi | Existing service for federation directory; reused for entry count + reachability |
| `ElasticSearchService` | OpenCatalogi | Existing optional service; conditionally used in HealthController |
| OpenRegister `MetricsService` | OpenRegister | Identified as reference pattern; not imported — direct SQL queries are retained for performance |

No overlap with OpenRegister's `ObjectService`, `RegisterService`, `SchemaService`, or `ConfigurationService`. The metrics endpoint intentionally uses raw aggregate SQL against OpenRegister tables to avoid the overhead of full object hydration — this is the same approach already in place for `publications_total`, `catalogs_total`, and `listings_total`.

## File Changes

- `lib/Controller/MetricsController.php` — Inject `IConfig`; add `nextcloud_version` label to info metric; make `opencatalogi_up` reflect DB health (try/catch SELECT 1); add `opencatalogi_directory_entries_total`, `opencatalogi_federation_reachable_total`, `opencatalogi_federation_unreachable_total` via DirectoryService; add `@spec` PHPDoc tags per ADR-003
- `lib/Controller/HealthController.php` — Inject `ElasticSearchService` (nullable) and `IAppConfig`; add `checks.search_backend`; differentiate degraded (filesystem fail) from error (DB fail) HTTP status; add per-check 3-second timeout; add `@spec` PHPDoc tags per ADR-003
- `tests/Unit/Controller/MetricsControllerTest.php` — Add tests: `nextcloud_version` label present, `opencatalogi_up 0` on DB exception (mock IDBConnection to throw), federation metrics present and numeric, label sanitization escapes backslash/quotes/newlines
- `tests/Unit/Controller/HealthControllerTest.php` — Add tests: all-pass → HTTP 200 + `status: "ok"`, DB fail → HTTP 503 + `status: "error"`, filesystem fail + DB OK → HTTP 200 + `status: "degraded"`, search backend check field present in response
- `docs/features/prometheus-metrics.md` — Feature documentation: endpoint descriptions, example Prometheus output, example health JSON, Grafana integration notes for Dutch municipality context, full metrics catalogue table
