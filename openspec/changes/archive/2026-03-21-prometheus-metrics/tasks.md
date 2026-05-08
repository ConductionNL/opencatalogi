# Tasks: prometheus-metrics

## 1. Metrics Controller Enhancements

- [x] 1.1 Add `nextcloud_version` label to `opencatalogi_info` gauge via `IConfig::getSystemValueString('version')`
- [x] 1.2 Make `opencatalogi_up` reflect actual database connectivity (try SELECT 1, set 0 on failure)
- [x] 1.3 Add federation metrics: `opencatalogi_directory_entries_total` gauge
- [x] 1.4 Add label sanitization for Prometheus format (backslash, quotes, newlines)
- [x] 1.5 Ensure publication/catalog/listing metrics use GROUP BY aggregation

## 2. Health Controller Enhancements

- [x] 2.1 Add search backend health check (ElasticSearch reachability when configured)
- [x] 2.2 Return 200 with "degraded" status when filesystem fails but database is OK
- [x] 2.3 Include app version in health response

## 3. Unit Tests (ADR-009)

- [x] 3.1 Verify MetricsControllerTest covers info metric with version labels
- [x] 3.2 Verify HealthControllerTest covers all check pass, database fail, filesystem fail scenarios
- [x] 3.3 Test metrics endpoint returns valid Prometheus format with correct Content-Type

## 4. Documentation (ADR-010)

- [x] 4.1 Feature documentation at docs/features/prometheus-metrics.md
- [x] 4.2 Screenshots of metrics endpoint output

## 5. Internationalization (ADR-005)

- [x] 5.1 No user-facing strings in metrics/health endpoints (machine-readable only) — N/A
