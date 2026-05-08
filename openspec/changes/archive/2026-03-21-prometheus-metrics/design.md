# Design: prometheus-metrics

## Context

OpenCatalogi already has `MetricsController.php` and `HealthController.php` exposing Prometheus metrics at `GET /api/metrics` and health checks at `GET /api/health`. Routes are registered in `appinfo/routes.php`. The controllers use direct database queries against OpenRegister tables.

**Constraints:**
- Metrics must be in Prometheus text exposition format (text/plain; version=0.0.4)
- Health check returns JSON with status codes (200 OK, 503 error)
- All queries use OpenRegister's database tables via IDBConnection
- Admin authentication required for metrics endpoint

## Goals / Non-Goals

**Goals:**
- Add `nextcloud_version` label to info metric
- Add federation metrics (directory entries, reachability)
- Add search backend health check to HealthController
- Make `opencatalogi_up` reflect actual database health
- Ensure performance within 2 second response time

**Non-Goals:**
- Request duration histograms (would require middleware)
- Caching of metrics (always live queries)
- Custom dashboards or Grafana provisioning

## Decisions

### 1. Metrics follow Prometheus naming conventions
All metrics use `opencatalogi_` prefix with snake_case. Counters end in `_total`, gauges represent current state.

### 2. Federation metrics use DirectoryService
Directory entry counts and reachability are derived from the existing directory entries in the database.

### 3. Health check search backend
The health check inspects ElasticSearchService availability when configured, falling back to database-only status.

## File Changes

- `lib/Controller/MetricsController.php` — Add nextcloud_version label, federation metrics, health-based up gauge
- `lib/Controller/HealthController.php` — Add search backend check, degraded status for filesystem
- `tests/Unit/Controller/MetricsControllerTest.php` — Exists, verify coverage
- `tests/Unit/Controller/HealthControllerTest.php` — Exists, verify coverage
