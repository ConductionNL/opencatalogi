# Prometheus Metrics Endpoint

## Purpose
Expose application metrics in Prometheus text exposition format at `GET /api/metrics` for monitoring, alerting, and operational dashboards.

## Requirements

### REQ-PROM-001: Metrics Endpoint
- MUST expose `GET /index.php/apps/opencatalogi/api/metrics` returning `text/plain; version=0.0.4; charset=utf-8`
- MUST require admin authentication (Nextcloud admin or API token)
- MUST return metrics in Prometheus text exposition format

### REQ-PROM-002: Standard Metrics
Every app MUST expose these standard metrics:
- `opencatalogi_info` (gauge, labels: version, php_version, nextcloud_version) — always 1
- `opencatalogi_up` (gauge) — 1 if app is healthy, 0 if degraded
- `opencatalogi_requests_total` (counter, labels: method, endpoint, status) — HTTP request count
- `opencatalogi_request_duration_seconds` (histogram, labels: method, endpoint) — request latency
- `opencatalogi_errors_total` (counter, labels: type) — error count by type

### REQ-PROM-003: App-Specific Metrics
- `opencatalogi_publications_total` (gauge, labels: status, catalog) — total publications
- `opencatalogi_catalogs_total` (gauge) — total catalogs
- `opencatalogi_listings_total` (gauge, labels: status) — total listings
- `opencatalogi_search_requests_total` (counter) — search queries
- `opencatalogi_search_duration_seconds` (histogram) — search latency

### REQ-PROM-004: Health Check
- MUST expose `GET /index.php/apps/opencatalogi/api/health` returning JSON `{"status": "ok"|"degraded"|"error", "checks": {...}}`
- Checks: database connectivity, required dependencies available, search backend (Elastic/Solr) reachability

## Current Implementation Status
- **Not implemented**: No MetricsController, HealthController, or metrics/monitoring code exists in the app.

## Standards & References
- Prometheus text exposition format: https://prometheus.io/docs/instrumenting/exposition_formats/
- OpenMetrics specification: https://openmetrics.io/
- Nextcloud server monitoring patterns
- OpenRegister MetricsService and HeartbeatController as reference implementation

## Specificity Assessment
Highly specific — metric names, types, and labels are fully defined. Implementation follows a standard pattern that can be shared via a base MetricsService trait/class from OpenRegister.
