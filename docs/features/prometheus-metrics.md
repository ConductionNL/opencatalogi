# Prometheus Metrics

## Overview

OpenCatalogi exposes application metrics in Prometheus text exposition format at `GET /api/metrics` and a health check endpoint at `GET /api/health`. These endpoints enable integration with standard observability stacks (Prometheus + Grafana) used by Dutch municipalities and hosting providers.

## Metrics Endpoint

**URL:** `GET /index.php/apps/opencatalogi/api/metrics`
**Content-Type:** `text/plain; version=0.0.4; charset=utf-8`
**Authentication:** Admin required

### Available Metrics

| Metric | Type | Description |
|--------|------|-------------|
| `opencatalogi_info` | gauge | App version, PHP version, Nextcloud version |
| `opencatalogi_up` | gauge | Database health (1 = healthy, 0 = unreachable) |
| `opencatalogi_publications_total` | gauge | Publications by status and catalog |
| `opencatalogi_catalogs_total` | gauge | Total catalog count |
| `opencatalogi_listings_total` | gauge | Listings by status |
| `opencatalogi_search_requests_total` | counter | Cumulative search requests |
| `opencatalogi_directory_entries_total` | gauge | Federated directory entries |

## Health Endpoint

**URL:** `GET /index.php/apps/opencatalogi/api/health`
**Content-Type:** `application/json`

### Response

```json
{
  "status": "ok|degraded|error",
  "version": "1.0.0",
  "checks": {
    "database": "ok",
    "filesystem": "ok",
    "search_backend": "database"
  }
}
```

### Status Codes

- **200 OK** - All checks pass or degraded (non-critical failure)
- **503 Service Unavailable** - Database unreachable (critical failure)
