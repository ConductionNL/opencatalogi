# Prometheus Metrics

## Overview

OpenCatalogi exposes application metrics in Prometheus text exposition format at `GET /api/metrics` and a health check endpoint at `GET /api/health`. These endpoints enable integration with standard observability stacks (Prometheus + Grafana) used by Dutch municipalities and hosting providers.

Both endpoints are served by OpenRegister's AppHost observability engine (ADR-040). The engine reads the `observability` block of `src/manifest.json`; the OpenCatalogi-specific families come from `OpenCatalogiMetricsProvider`.

## Metrics Endpoint

**URL:** `GET /index.php/apps/opencatalogi/api/metrics`
**Content-Type:** `text/plain; version=0.0.4; charset=utf-8`
**Authentication:** Admin required

### Available Metrics

| Metric | Type | Labels | Description |
|--------|------|--------|-------------|
| `opencatalogi_info` | gauge | `version`, `php_version`, `nextcloud_version` | App, PHP and Nextcloud versions (always 1) |
| `opencatalogi_up` | gauge | | 1 whenever the engine serves the request |
| `opencatalogi_search_requests_total` | counter | | Cumulative search requests |
| `opencatalogi_publications_total` | gauge | `status`, `catalog` | Publications by status and catalog |
| `opencatalogi_catalogs_total` | gauge | | Total catalog count |
| `opencatalogi_listings_total` | gauge | `status` | Listings by status |
| `opencatalogi_directory_entries_total` | gauge | | Federated directory entries |
| `opencatalogi_federation_reachable_total` | gauge | | Listings whose last directory sync answered 2xx |
| `opencatalogi_federation_unreachable_total` | gauge | | Listings whose last directory sync recorded any other status code |
| `opencatalogi_publication_views_total` | counter | `catalog` | Counted publication views by catalog |
| `opencatalogi_file_downloads_total` | counter | `catalog` | Counted file downloads by catalog |

A listing that was never synced counts in neither federation gauge. Both federation gauges are always present, with value 0 when there are no listings. Label values are escaped by the engine: a backslash is doubled, a double quote becomes `\"` and a newline becomes `\n`.

### Example output (excerpt)

```text
opencatalogi_info{version="1.4.0",php_version="8.3.6",nextcloud_version="32.0.1.2"} 1
opencatalogi_up 1
# HELP opencatalogi_listings_total Total listings by status
# TYPE opencatalogi_listings_total gauge
opencatalogi_listings_total{status="active"} 12
# HELP opencatalogi_federation_reachable_total Federated listings whose last directory sync succeeded
# TYPE opencatalogi_federation_reachable_total gauge
opencatalogi_federation_reachable_total 10
# HELP opencatalogi_federation_unreachable_total Federated listings whose last directory sync failed
# TYPE opencatalogi_federation_unreachable_total gauge
opencatalogi_federation_unreachable_total 2
```

## Health Endpoint

**URL:** `GET /index.php/apps/opencatalogi/api/health`
**Content-Type:** `application/json`
**Authentication:** none (no metrics data is exposed)

The health response carries two checks: `database` (critical) and `filesystem` (degraded). OpenCatalogi searches through OpenRegister and has no search backend of its own, so there is no separate search-backend check.

| Database | Filesystem | HTTP | `status` |
|----------|------------|------|----------|
| ok | ok | 200 | `ok` |
| ok | failing | 200 | `degraded` |
| failing | any | 503 | `error` |

A failing check carries a short message, never a stack trace.

## Prometheus scrape configuration

The metrics endpoint needs an admin. Use a dedicated admin account with an app password, so the password can be revoked on its own:

```yaml
scrape_configs:
  - job_name: opencatalogi
    metrics_path: /index.php/apps/opencatalogi/api/metrics
    scheme: https
    basic_auth:
      username: metrics
      password_file: /etc/prometheus/opencatalogi-app-password
    static_configs:
      - targets: ['catalogus.gemeente.example']
```

In Grafana, `opencatalogi_federation_unreachable_total > 0` is a useful alert: it means at least one federated directory failed its last sync.
