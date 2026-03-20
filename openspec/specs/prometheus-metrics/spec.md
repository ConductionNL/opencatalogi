---
status: reviewed
---

# Prometheus Metrics Endpoint

## Purpose
Expose application metrics in Prometheus text exposition format at `GET /api/metrics` and a health check at `GET /api/health` for monitoring, alerting, and operational dashboards. These endpoints enable integration with standard observability stacks (Prometheus + Grafana) used by Dutch municipalities and hosting providers.

## Context
OpenCatalogi serves as the public-facing publication platform for government transparency (WOO). Uptime, publication throughput, search performance, and federation health are critical operational metrics. This spec defines the metrics and health endpoints that enable proactive monitoring and alerting.

**Relation to existing OpenCatalogi infrastructure:**
- `MetricsController.php` already exists with basic metrics (publications, catalogs, listings, search counts)
- `HealthController.php` already exists with database and filesystem checks
- Routes are registered in `appinfo/routes.php` at `/api/metrics` and `/api/health`
- Both controllers use direct database queries against OpenRegister tables

**Relation to other apps:**
- OpenRegister has a reference MetricsService and HeartbeatController that can serve as a shared pattern
- All Conduction apps should expose the same standard metrics set for unified monitoring

## ADDED Requirements

### Requirement: Metrics endpoint MUST expose standard metrics
The `GET /api/metrics` endpoint MUST return metrics in Prometheus text exposition format covering app health, request counts, and error tracking.

#### Scenario: Metrics endpoint returns valid Prometheus format
- GIVEN an admin user is authenticated
- WHEN `GET /index.php/apps/opencatalogi/api/metrics` is called
- THEN the response MUST have Content-Type `text/plain; version=0.0.4; charset=utf-8`
- AND the body MUST contain metrics in Prometheus text exposition format
- AND each metric MUST have a `# HELP` line and a `# TYPE` line

#### Scenario: App info metric is present
- GIVEN the metrics endpoint is called
- WHEN the response is parsed
- THEN `opencatalogi_info` gauge MUST be present with labels `version`, `php_version`
- AND the value MUST be 1
- AND `version` MUST match the installed app version from `IAppManager`

#### Scenario: App up metric indicates health
- GIVEN the app is functioning normally
- WHEN the metrics endpoint is called
- THEN `opencatalogi_up` gauge MUST have value 1
- AND when the database is unreachable, the value MUST be 0

#### Scenario: Metrics endpoint requires admin authentication
- GIVEN an unauthenticated request
- WHEN `GET /api/metrics` is called
- THEN the response MUST have status 401 or redirect to login
- AND no metrics data MUST be returned

#### Scenario: Metrics endpoint handles database errors gracefully
- GIVEN the database connection is temporarily unavailable
- WHEN `GET /api/metrics` is called
- THEN the response MUST still return valid Prometheus format
- AND `opencatalogi_up` MUST be 0
- AND metrics that require database queries MUST show 0 or be omitted
- AND no PHP error MUST be exposed in the response

### Requirement: Publication metrics MUST be exposed
The metrics endpoint MUST expose publication counts grouped by status and catalog.

#### Scenario: Publication counts by status and catalog
- GIVEN 50 publications across 3 catalogs with various statuses
- WHEN the metrics endpoint is called
- THEN `opencatalogi_publications_total` gauge MUST be present
- AND it MUST have labels `status` and `catalog`
- AND each unique combination of status and catalog MUST have its own metric line
- AND the sum of all lines MUST equal 50

#### Scenario: No publications returns zero metric
- GIVEN zero publications exist in the system
- WHEN the metrics endpoint is called
- THEN `opencatalogi_publications_total` MUST either be absent or show value 0

#### Scenario: Publication metric labels are sanitized
- GIVEN a publication with status containing special characters (e.g., newlines, quotes)
- WHEN the metrics endpoint generates the label
- THEN special characters MUST be escaped per Prometheus label value rules
- AND backslashes MUST be doubled, quotes MUST be escaped, newlines MUST become `\n`

### Requirement: Catalog metrics MUST be exposed
The metrics endpoint MUST expose the total number of catalogs.

#### Scenario: Catalog count is accurate
- GIVEN 5 catalogs exist in the system
- WHEN the metrics endpoint is called
- THEN `opencatalogi_catalogs_total` gauge MUST have value 5

#### Scenario: Catalog count after creation and deletion
- GIVEN 5 catalogs exist, then 1 is deleted and 2 are created
- WHEN the metrics endpoint is called after the changes
- THEN `opencatalogi_catalogs_total` MUST have value 6

#### Scenario: Catalog count uses pattern matching on schema title
- GIVEN OpenRegister stores catalog objects with schema titles containing "atalog"
- WHEN the metrics endpoint queries catalog count
- THEN the query MUST use a LIKE pattern on the schema title
- AND it MUST not count non-catalog objects that happen to match other patterns

### Requirement: Listing metrics MUST be exposed
The metrics endpoint MUST expose listing counts grouped by status.

#### Scenario: Listing counts by status
- GIVEN 100 listings with statuses: 60 published, 30 draft, 10 archived
- WHEN the metrics endpoint is called
- THEN `opencatalogi_listings_total` gauge MUST be present with label `status`
- AND `opencatalogi_listings_total{status="published"} 60` MUST be in the output
- AND `opencatalogi_listings_total{status="draft"} 30` MUST be in the output
- AND `opencatalogi_listings_total{status="archived"} 10` MUST be in the output

#### Scenario: No listings returns zero metric
- GIVEN zero listings exist in the system
- WHEN the metrics endpoint is called
- THEN `opencatalogi_listings_total` MUST show value 0 (without status label)

#### Scenario: Listing metric handles null status
- GIVEN a listing stored without a status field in its JSON object
- WHEN the metrics endpoint aggregates listing counts
- THEN the listing MUST be counted under status "unknown"
- AND no error MUST occur

### Requirement: Search metrics MUST be exposed
The metrics endpoint MUST expose search request counts, reflecting how actively the catalog is being searched.

#### Scenario: Search request count
- GIVEN 500 search requests have been logged in the metrics table
- WHEN the metrics endpoint is called
- THEN `opencatalogi_search_requests_total` counter MUST have value 500

#### Scenario: Search metrics table does not exist
- GIVEN the `openregister_metrics` table does not exist (e.g., fresh installation)
- WHEN the metrics endpoint queries search counts
- THEN `opencatalogi_search_requests_total` MUST show value 0
- AND no error MUST be exposed in the response

#### Scenario: Search count reflects cumulative total
- GIVEN 100 search requests at time T1 and 50 more by time T2
- WHEN the metrics endpoint is called at T2
- THEN `opencatalogi_search_requests_total` MUST have value 150
- AND the value MUST NOT decrease (counters are monotonically increasing)

### Requirement: Federation metrics MUST be exposed
The metrics endpoint MUST expose metrics about the federation network (directory entries and federation health).

#### Scenario: Directory entry count
- GIVEN the OpenCatalogi instance has 15 federated directory entries
- WHEN the metrics endpoint is called
- THEN `opencatalogi_directory_entries_total` gauge MUST have value 15

#### Scenario: Federation reachability
- GIVEN 15 directory entries, 12 are reachable and 3 return errors on last check
- WHEN the metrics endpoint is called
- THEN `opencatalogi_federation_reachable_total` gauge MUST have value 12
- AND `opencatalogi_federation_unreachable_total` gauge MUST have value 3

#### Scenario: No federation configured
- GIVEN no directory entries are configured
- WHEN the metrics endpoint is called
- THEN `opencatalogi_directory_entries_total` MUST have value 0
- AND federation reachability metrics MUST show 0

### Requirement: Health check endpoint MUST report system status
The `GET /api/health` endpoint MUST return a JSON health status covering database connectivity, filesystem access, and dependency availability.

#### Scenario: All checks pass
- GIVEN the database is reachable and the filesystem is writable
- WHEN `GET /api/health` is called
- THEN the response MUST have status 200
- AND the JSON body MUST contain `{"status": "ok", "version": "...", "checks": {"database": "ok", "filesystem": "ok"}}`

#### Scenario: Database check fails
- GIVEN the database connection is unavailable
- WHEN `GET /api/health` is called
- THEN the response MUST have status 503
- AND `status` MUST be "error"
- AND `checks.database` MUST contain a failure message

#### Scenario: Filesystem check fails
- GIVEN the temp directory is not writable
- WHEN `GET /api/health` is called
- THEN the response MUST have status 200 (degraded is not critical)
- AND `status` MUST be "degraded"
- AND `checks.filesystem` MUST contain a failure message

#### Scenario: Health check includes search backend status
- GIVEN ElasticSearch is configured as the search backend
- WHEN `GET /api/health` is called
- THEN `checks.search_backend` MUST indicate whether ElasticSearch is reachable
- AND the check MUST include the backend type ("elasticsearch" or "database")

#### Scenario: Health check response time
- GIVEN all checks are healthy
- WHEN `GET /api/health` is called
- THEN the response MUST be returned within 5 seconds
- AND individual check timeouts MUST NOT exceed 3 seconds

### Requirement: Metrics MUST include Nextcloud version context
The metrics endpoint MUST include Nextcloud server version information for compatibility tracking across a fleet of instances.

#### Scenario: Nextcloud version label on info metric
- GIVEN the Nextcloud server version is 28.0.4
- WHEN the metrics endpoint is called
- THEN `opencatalogi_info` MUST include a `nextcloud_version` label with value "28.0.4"

#### Scenario: PHP version label on info metric
- GIVEN the PHP version is 8.2.15
- WHEN the metrics endpoint is called
- THEN `opencatalogi_info` MUST include a `php_version` label with value "8.2.15"

#### Scenario: Version information is always available
- GIVEN the app version retrieval fails (unlikely but possible)
- WHEN the metrics endpoint is called
- THEN the `version` label MUST fall back to "unknown"
- AND the metric MUST still be emitted

### Requirement: Metrics endpoint MUST be performant
The metrics collection MUST not significantly impact request latency or database load.

#### Scenario: Metrics response time under normal load
- GIVEN the system has 10,000 publications, 50 catalogs, and 5,000 listings
- WHEN `GET /api/metrics` is called
- THEN the response MUST be returned within 2 seconds

#### Scenario: Metrics queries use efficient aggregation
- GIVEN the metrics endpoint queries publication counts
- WHEN the SQL queries execute
- THEN they MUST use GROUP BY aggregation (not fetching all rows)
- AND they MUST use JOINs on indexed columns (schema id, object id)

#### Scenario: Metrics endpoint does not cache stale data
- GIVEN a publication was just created
- WHEN the metrics endpoint is called immediately after
- THEN the publication count MUST reflect the new total
- AND metrics MUST query live data (no caching beyond database-level query cache)

### Requirement: Metrics MUST follow Prometheus naming conventions
All metric names MUST follow Prometheus naming best practices.

#### Scenario: Metric names use app prefix
- GIVEN any metric emitted by the endpoint
- THEN the metric name MUST start with `opencatalogi_`
- AND the name MUST use snake_case

#### Scenario: Counter metrics have _total suffix
- GIVEN counter-type metrics (e.g., search requests, errors)
- THEN the metric name MUST end with `_total`
- AND the `# TYPE` declaration MUST be `counter`

#### Scenario: Histogram metrics have _seconds suffix for duration
- GIVEN duration/latency metrics
- THEN the metric name MUST end with `_seconds`
- AND the `# TYPE` declaration MUST be `histogram`
- AND the unit MUST be seconds (not milliseconds)

#### Scenario: Gauge metrics represent current state
- GIVEN gauge-type metrics (publications total, catalogs total)
- THEN the `# TYPE` declaration MUST be `gauge`
- AND the value MUST represent the current count (can go up and down)

## MODIFIED Requirements

_None._

## REMOVED Requirements

_None._

## Current Implementation Status
- **Partially implemented**: Both `MetricsController.php` and `HealthController.php` exist with basic functionality.
- **What exists in MetricsController**:
  - `opencatalogi_info` gauge with version and php_version labels
  - `opencatalogi_up` gauge (always 1, does not reflect actual health)
  - `opencatalogi_publications_total` by status and catalog (via JSON_EXTRACT queries)
  - `opencatalogi_catalogs_total` (via schema pattern matching)
  - `opencatalogi_listings_total` by status (via JSON_EXTRACT queries)
  - `opencatalogi_search_requests_total` (from openregister_metrics table)
  - Label sanitization for Prometheus format
  - Proper Content-Type header
- **What exists in HealthController**:
  - Database connectivity check
  - Filesystem write check
  - Version information in response
  - Proper HTTP status codes (200 for ok, 503 for error)
- **Key gaps**:
  - No `nextcloud_version` label on info metric
  - No `opencatalogi_up` based on actual health checks (always returns 1)
  - No federation metrics (directory entries, reachability)
  - No search backend health check in HealthController
  - No request duration or error count metrics (request_duration_seconds, errors_total)
  - No performance guarantees or timeout handling on health checks

## Standards & References
- Prometheus text exposition format: https://prometheus.io/docs/instrumenting/exposition_formats/
- OpenMetrics specification: https://openmetrics.io/
- Prometheus naming best practices: https://prometheus.io/docs/practices/naming/
- Nextcloud server monitoring patterns
- OpenRegister MetricsService and HeartbeatController as reference implementation

## Dependencies
- OpenRegister database tables (openregister_objects, openregister_schemas, openregister_metrics)
- Nextcloud IDBConnection for database queries
- Nextcloud IAppManager for version information
- ElasticSearchService for search backend health checks (optional)
- DirectoryService for federation metrics
