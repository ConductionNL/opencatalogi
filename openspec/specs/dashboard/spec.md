---
status: reviewed
or_dep: x-openregister-aggregations
audit_ref: .claude/audit-2026-05-03/02-spec-rewrite.md
retrofit_extensions:
  - DSH-009
  - DSH-010
  - DSH-011
  - DIR-012
  - LST-007
---

# Dashboard and Directory

> **OR aggregations citation (Phase 7):** This spec is updated as part of
> `opencatalogi-adopt-or-abstractions` (Phase 7) to cite OR's aggregations
> annotation (`x-openregister-aggregations`) as the source of dashboard
> metrics. opencatalogi MUST NOT compute histogram/count aggregations in PHP;
> the computation is declared on the schema and executed by OR.
>
> Upstream dependency: OR `x-openregister-aggregations` schema extension.

## Purpose

The dashboard provides the main entry point for the OpenCatalogi Nextcloud app,
serving the Vue SPA for all internal views. The directory system manages the
network of interconnected OpenCatalogi instances, enabling federation through
listings, directory synchronization, and broadcast notifications.

After Phase 7, dashboard widgets that display aggregate counts (publications
by status, attachment counts, etc.) MUST source those counts from OR schema
aggregations (`x-openregister-aggregations`) rather than from hand-rolled PHP
count queries.

## ADDED Requirements

### Requirement: dashboard widgets consume OR aggregations (DSH-OR-001)

Every dashboard widget that displays a count or histogram (e.g. publications
by status, unpublished attachments count) MUST consume the corresponding OR
schema aggregation declared on the relevant schema via
`x-openregister-aggregations`. opencatalogi MUST NOT compute aggregation
results in PHP.

> @e2e exclude Backend data-source contract (widgets read OR `x-openregister-aggregations` rather than computing histograms in opencatalogi PHP, and degrade gracefully when the aggregation is absent) — the assertion is about the data-source mechanism and the absence of a bespoke PHP count query, not a UI surface; verified by PHPUnit (no aggregation query in PHP) and vitest (graceful N/A fallback). The widgets' visual rendering is already real-UI covered under dashboard::load-dashboard-data and ::load-unpublished-widgets.

#### Scenario: a dashboard widget is backed by an OR aggregation

- **GIVEN** a widget shows "publications by status",
- **WHEN** the widget loads,
- **THEN** it consumes the corresponding `x-openregister-aggregations`
  declaration on the publications schema,
- **AND** does NOT compute the histogram in opencatalogi PHP.

#### Scenario: OR aggregation is absent

- **GIVEN** OR does not yet expose the requested aggregation,
- **WHEN** the widget loads,
- **THEN** the widget degrades gracefully (e.g. shows "N/A") rather than
  falling back to a bespoke PHP count query.

## Requirements

### Requirement: Serve the Vue SPA template for the main app page (DSH-001)
The system MUST serve the Vue SPA template for the main app page.

**Priority:** Must **Status:** Implemented

### Requirement: Support deep-link routing for all SPA pages (DSH-002)
The system MUST support deep-link routing for all SPA pages (catalogi,
publications, search, themes, glossary, pages, menus, directory, organizations).

**Priority:** Must **Status:** Implemented

### Requirement: Content Security Policy allows connect to all domains (DSH-003)
The Content Security Policy MUST allow connect to all domains (for federation
HTTP requests).

**Priority:** Must **Status:** Implemented

### Requirement: Application.php bootstrap registers dashboard widgets (DSH-005)
Application.php bootstrap MUST register dashboard widgets (CatalogWidget,
UnpublishedPublicationsWidget, UnpublishedAttachmentsWidget). Each widget
MUST source its aggregate counts from OR schema aggregations (DSH-OR-001).

**Priority:** Must **Status:** Implemented (aggregation citation added by Phase 7)

### Requirement: Application.php bootstrap registers event listeners for OpenRegister events (DSH-006)
Application.php bootstrap MUST register event listeners for OpenRegister events.

**Priority:** Must **Status:** Implemented

### Requirement: Application.php bootstrap registers tool registration listener for AI agents (DSH-007)
Application.php bootstrap MUST register the tool registration listener for AI agents.

**Priority:** Must **Status:** Implemented

### Requirement: Application.php bootstrap loads vendor autoload for Composer dependencies (DSH-008)
Application.php bootstrap MUST load vendor autoload for Composer dependencies.

**Priority:** Must **Status:** Implemented

### Requirement: List all listings with pagination (LST-001)
The system MUST list all listings with pagination.

**Priority:** Must **Status:** Implemented

### Requirement: Get a single listing by ID (public endpoint) (LST-002)
The system MUST get a single listing by ID (public endpoint).

**Priority:** Must **Status:** Implemented

### Requirement: Create a new listing (LST-003)
The system MUST allow creating a new listing.

**Priority:** Must **Status:** Implemented

### Requirement: Update an existing listing (LST-004)
The system MUST allow updating an existing listing.

**Priority:** Must **Status:** Implemented

### Requirement: Delete a listing (LST-005)
The system MUST allow deleting a listing.

**Priority:** Must **Status:** Implemented

### Requirement: Listing configuration stored in IAppConfig (LST-006)
Listing configuration MUST be stored in IAppConfig as `listing_schema` and
`listing_register`, following the OR `IAppConfig` naming convention per the
[admin-settings spec](../admin-settings/spec.md).

**Priority:** Must **Status:** Implemented

### Requirement: Get combined directory data from all listings (DIR-001)
The system MUST get combined directory data from all listings.

**Priority:** Must **Status:** Implemented

### Requirement: Synchronize with an external directory URL (DIR-002)
The system MUST synchronize with an external directory URL (POST with directory
parameter).

**Priority:** Must **Status:** Implemented

### Requirement: Synchronize a specific listing's directory (DIR-003)
The system MUST synchronize a specific listing's directory.

**Priority:** Must **Status:** Implemented

### Requirement: Synchronize all directories via cron (every hour) (DIR-004)
The system MUST synchronize all directories via cron (every hour).

**Priority:** Must **Status:** Implemented

### Requirement: Add a new listing from a URL (public endpoint) (DIR-005)
The system MUST allow adding a new listing from a URL (public endpoint).

**Priority:** Must **Status:** Implemented

### Requirement: Anti-loop protection during broadcast sync cycles (DIR-006)
The system SHOULD provide anti-loop protection during broadcast sync cycles.

**Priority:** Should **Status:** Implemented

### Requirement: Broadcast this directory to external instances (cron every 4 hours) (DIR-007)
The system SHOULD broadcast this directory to external instances (cron every 4 hours).

**Priority:** Should **Status:** Bug (Not Registered in info.xml)

### Requirement: CORS support on directory endpoints (DIR-008)
The system MUST support CORS on directory endpoints.

**Priority:** Must **Status:** Implemented

### Requirement: Publication endpoint auto-detection from directory URLs (DIR-009)
The system SHOULD support publication endpoint auto-detection from directory URLs.

**Priority:** Should **Status:** Implemented

### Requirement: Listing staleness checking during directory sync (DIR-010)
The system SHOULD perform listing staleness checking during directory sync.

**Priority:** Should **Status:** Implemented

### Requirement: Catalog-to-listing conversion during sync (DIR-011)
The system SHOULD perform catalog-to-listing conversion during sync.

**Priority:** Should **Status:** Implemented

### Requirement: Manifest-driven SPA shell and main navigation (DSH-009)
The frontend SPA SHALL render through a manifest-driven `CnAppRoot` shell
(`App.vue`) for app id `opencatalogi`, passing the app manifest, custom
components, page types, a per-app translate closure, and a computed
`permissions` array.

**Priority:** Must **Status:** Implemented

### Requirement: Dashboard overview view (DSH-010)
The system SHALL provide a `Dashboard.vue` overview. The total publication
count MUST be sourced from OR's `x-openregister-aggregations` declaration on
the publications schema (see DSH-OR-001), NOT from a bespoke count query.

**Priority:** Should **Status:** Implemented (aggregation citation added by Phase 7)

### Requirement: Unpublished-content dashboard widgets (DSH-011)
The system SHALL provide two Nextcloud dashboard widgets —
`UnpublishedAttachmentsWidget` and `UnpublishedPublicationsWidget`. Counts
MUST come from OR schema aggregations (DSH-OR-001).

**Priority:** Should **Status:** Implemented (aggregation citation added by Phase 7)

### Requirement: Directory management UI (DIR-012)
The system SHALL provide a directory management frontend: a `DirectorySideBar`,
an `AddDirectoryModal`, and a `ViewDirectoryModal`.

**Priority:** Should **Status:** Implemented

### Requirement: Listing management UI (LST-007)
The system SHALL provide listing management dialogs: an `EditListingModal` and
a `DeleteListingDialog`.

**Priority:** Should **Status:** Implemented

## REMOVED Requirements

| ID | Title | Reason removed |
|----|-------|----------------|
| DSH-004 | Dashboard data endpoint returns basic status info | REMOVED — dead code (route exists but controller method was removed before this spec rewrite). Not restored. |

## Breaking Changes

| Breaking change | Old behaviour | New behaviour |
|---|---|---|
| Dashboard aggregate counts sourced from bespoke PHP | Widget loaded a publication count via `GET /api/publications?_limit=1000` | Widget sources counts from OR `x-openregister-aggregations` on the publications schema. The `_limit=1000` trick for counting is no longer permitted. |

## Data Model

### Listing Schema

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| catalogusId | string | Yes | ID of the catalog this listing belongs to (facetable) |
| title | string | Yes | Title of the listing (facetable) |
| summary | string | Yes | Brief description |
| description | string | No | Detailed description |
| search | string (URL) | No | Search API endpoint URL |
| publications | string (URL) | No | Publications API endpoint URL |
| directory | string (URL) | No | Directory URL for synchronization |
| status | enum | No | development, beta, stable, obsolete |
| lastSync | datetime | No | Timestamp of last synchronization (facetable) |
| integrationLevel | enum | No | none, connection, search (facetable) |
| default | boolean | No | Whether this is the default listing (facetable) |

## API Endpoints

### Dashboard

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/` | Serve SPA template (dashboard page) |

### Listings

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/listings` | List all listings (authenticated) |
| POST | `/api/listings` | Create new listing (authenticated) |
| POST | `/api/listings/sync` | Synchronize directories (authenticated) |
| POST | `/api/listings/add` | Add listing from URL (public) |
| GET | `/api/listings/{id}` | Get listing by ID (public) |
| PUT | `/api/listings/{id}` | Update listing (authenticated) |
| DELETE | `/api/listings/{id}` | Delete listing (authenticated) |

### Directory

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/directory` | Get combined directory data (public) |
| POST | `/api/directory` | Sync with external directory URL (public) |

## References

- OR `x-openregister-aggregations` schema extension (upstream dependency)
- `.claude/audit-2026-05-03/02-spec-rewrite.md` (Stream 2 rationale)
- `openspec/changes/opencatalogi-adopt-or-abstractions/` (Phase 7 implementation change)
- ADR-022 — Apps consume OR abstractions
- ADR-031 — Schema-declarative business logic
