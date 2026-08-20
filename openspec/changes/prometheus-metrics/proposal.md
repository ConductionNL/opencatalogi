# Prometheus Metrics Endpoint

## Problem
OpenCatalogi serves as the public-facing publication platform for government transparency (WOO). Uptime, publication throughput, search performance, and federation health are critical operational metrics that Dutch municipalities and hosting providers need to monitor via standard observability stacks (Prometheus + Grafana).

Both `MetricsController.php` and `HealthController.php` exist but have significant gaps: `opencatalogi_up` always returns 1 regardless of actual database health, there are no federation metrics (directory entries or reachability), the info metric is missing the `nextcloud_version` label, the health endpoint has no search backend check, and there are no performance guarantees or per-check timeouts.

## Proposed Solution
Complete the Prometheus Metrics Endpoint by closing all identified gaps in the existing controllers. Key requirements include:
- Requirement: Metrics endpoint MUST expose standard metrics (with `opencatalogi_up` reflecting real health)
- Requirement: Metrics MUST include Nextcloud version context (`nextcloud_version` label on info metric)
- Requirement: Federation metrics MUST be exposed (directory entries, reachable, unreachable counts)
- Requirement: Health check endpoint MUST report system status (including search backend check and degraded state)
- Requirement: Metrics endpoint MUST be performant (≤2 second response under 10k publication load)
- Requirement: Metrics MUST follow Prometheus naming conventions throughout

## Scope
This change covers all requirements defined in the prometheus-metrics specification. No new entities, schemas, or frontend components are introduced — all work is confined to `MetricsController.php`, `HealthController.php`, and their unit tests.

## Success Criteria
- `opencatalogi_info` includes `nextcloud_version` label sourced from `IConfig::getSystemValueString('version')`
- `opencatalogi_up` emits 0 when the database is unreachable, 1 when healthy
- `opencatalogi_directory_entries_total`, `opencatalogi_federation_reachable_total`, and `opencatalogi_federation_unreachable_total` are present and accurate
- `GET /api/health` includes `checks.search_backend` with backend type and reachability status when ElasticSearch is configured
- Filesystem failure returns HTTP 200 + `status: "degraded"`; database failure returns HTTP 503 + `status: "error"`
- Metrics endpoint responds within 2 seconds with 10,000 publications, 50 catalogs, and 5,000 listings
