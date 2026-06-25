---
status: done
---

# adopt-apphost Specification

## Purpose

@e2e exclude pure backend/observability spec — `/api/health` returns JSON and `/api/metrics` returns Prometheus text; both are machine endpoints with no browser-observable UI surface, covered by API/parity checks rather than browser tests.

OpenCatalogi serves its ADR-006 health and Prometheus metrics endpoints through OpenRegister's AppHost observability engine (ADR-040) instead of bespoke controllers. The declarative `observability` block in `src/manifest.json` plus a single `IMetricsProvider` reproduce the pre-adoption contract; the hand-written `HealthController` + `MetricsController` are deleted.

## Requirements
### Requirement: Health endpoint served by the AppHost engine (OBS-001)
The system SHALL serve `GET /api/health` via OpenRegister's
`AppHost\Controller\GenericHealthController#index`, configured by the
`observability.health` block of `src/manifest.json` declaring a `database`
check (severity `critical`) and a `filesystem` check (severity `degraded`)
under the `adr006` status-code policy. The route SHALL remain registered in
`appinfo/routes.php` at the unchanged `/api/health` URL, and the endpoint SHALL
be public (`#[PublicPage]`, engine-owned).

#### Scenario: Healthy instance
- GIVEN the database and filesystem checks both succeed
- WHEN `GET /api/health` is requested
- THEN the response SHALL be `200` with body `{status, app, version, checks}`
  where `status` is `ok` and `checks.database` / `checks.filesystem` are `ok`

#### Scenario: Database failure is critical
- GIVEN the `database` check fails
- WHEN `GET /api/health` is requested
- THEN the response SHALL be `503` with `status: error`

#### Scenario: Filesystem failure is degraded
- GIVEN the `filesystem` check fails but the database is healthy
- WHEN `GET /api/health` is requested
- THEN the response SHALL be `200` with `status: degraded`

### Requirement: Metrics endpoint served by the AppHost engine (OBS-002)
The system SHALL serve `GET /api/metrics` via OpenRegister's
`AppHost\Controller\GenericMetricsController#index`, configured by the
`observability.metrics` block of `src/manifest.json`. The route SHALL remain
registered in `appinfo/routes.php` at the unchanged `/api/metrics` URL, the
endpoint SHALL be admin-only (engine-owned, ADR-006), and the body SHALL be
Prometheus text exposition 0.0.4 with the `opencatalogi_` prefix.

#### Scenario: Anonymous caller is rejected
- GIVEN no admin session
- WHEN `GET /api/metrics` is requested
- THEN the engine SHALL reject the request (login redirect / 401), never metric data

#### Scenario: Implicit info + up metrics
- WHEN `GET /api/metrics` is served
- THEN the body SHALL include `opencatalogi_info{version,php_version,nextcloud_version} 1`
  and `opencatalogi_up 1`

### Requirement: Domain metrics parity via the provider escape hatch (OBS-003)
The system SHALL register `OpenCatalogiMetricsProvider` (implementing
`OCA\OpenRegister\AppHost\IMetricsProvider`) under the container alias
`OCA\OpenRegister\AppHost\IMetricsProvider::opencatalogi`, and the
`observability.metrics` block SHALL include a `{kind:provider}` descriptor that
merges its samples. The provider SHALL reproduce the pre-adoption
`MetricsController` domain families byte-for-byte, using the same
OpenRegister-backed queries: `opencatalogi_publications_total{status,catalog}`,
`opencatalogi_catalogs_total`, `opencatalogi_listings_total{status}` (with a
single unlabelled `0` fallback when empty), `opencatalogi_directory_entries_total`,
`opencatalogi_publication_views_total{catalog}` and
`opencatalogi_file_downloads_total{catalog}` (each with a `{catalog=""} 0`
fallback when empty). `opencatalogi_search_requests_total` SHALL be expressed
declaratively as a `tableCount` over `openregister_metrics` filtered by
`metric_type like search_%`.

#### Scenario: Publications grouped by status and catalog
- GIVEN publications exist in two catalogs with mixed statuses
- WHEN `GET /api/metrics` is served
- THEN the body SHALL contain one `opencatalogi_publications_total{status,catalog}`
  sample per `(status, catalog)` group with its count

#### Scenario: Empty dataset keeps the zero-fallback lines
- GIVEN no listings and no usageCounter objects exist
- WHEN `GET /api/metrics` is served
- THEN `opencatalogi_listings_total 0`, `opencatalogi_publication_views_total{catalog=""} 0`
  and `opencatalogi_file_downloads_total{catalog=""} 0` SHALL be present

### Requirement: Bespoke observability controllers removed (OBS-004)
The system SHALL NOT ship `lib/Controller/HealthController.php` or
`lib/Controller/MetricsController.php`; the observability contract SHALL be
served exclusively by the AppHost engine. The `/api/health` and `/api/metrics`
URLs and their response contracts SHALL be unchanged from the deleted
controllers, except for the documented intentional improvements (the health
JSON `app` field and the engine-owned `opencatalogi_up` gauge), matching
OpenRegister's own adoption of the engine.

#### Scenario: No bespoke controller remains
- WHEN the app tree is inspected
- THEN neither `HealthController` nor `MetricsController` SHALL exist under `lib/Controller/`
- AND the `/api/health` + `/api/metrics` routes SHALL resolve to the AppHost generics

