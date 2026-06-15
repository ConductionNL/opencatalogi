---
status: done
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

@e2e exclude retrofit spec — listing/directory CRUD, SPA-serving, CSP, bootstrap registration, and aggregation data-sourcing are backend/HTTP-contract behaviours covered by Newman API tests and PHPUnit, not browser-UI observable; the frontend shell/views named here are already real-UI covered by the dedicated dashboard SPA e2e scenarios.

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

#### Scenario: main app page returns the SPA template
- GIVEN an authenticated user opens the opencatalogi app root
- WHEN a GET request is made to `/`
- THEN the response MUST be the Vue SPA template that bootstraps the app

### Requirement: Support deep-link routing for all SPA pages (DSH-002)
The system MUST support deep-link routing for all SPA pages (catalogi,
publications, search, themes, glossary, pages, menus, directory, organizations).

**Priority:** Must **Status:** Implemented

#### Scenario: a deep link resolves to its SPA page
- GIVEN the SPA is served
- WHEN a user opens a deep link such as `/publications` or `/directory` directly
- THEN the SPA router MUST resolve the URL to the corresponding page without a server-side 404

### Requirement: Content Security Policy allows connect to all domains (DSH-003)
The Content Security Policy MUST allow connect to all domains (for federation
HTTP requests).

**Priority:** Must **Status:** Implemented

#### Scenario: CSP permits federation connect targets
- GIVEN the app emits its Content Security Policy
- WHEN the SPA makes a federation HTTP request to an external instance
- THEN the CSP MUST allow the connect so the cross-instance request is not blocked

### Requirement: Application.php bootstrap registers dashboard widgets (DSH-005)
Application.php bootstrap MUST register dashboard widgets (CatalogWidget,
UnpublishedPublicationsWidget, UnpublishedAttachmentsWidget). Each widget
MUST source its aggregate counts from OR schema aggregations (DSH-OR-001).

**Priority:** Must **Status:** Implemented (aggregation citation added by Phase 7)

#### Scenario: dashboard widgets are registered at bootstrap
- GIVEN the app is bootstrapped via Application.php
- WHEN registration runs
- THEN CatalogWidget, UnpublishedPublicationsWidget, and UnpublishedAttachmentsWidget MUST be registered as dashboard widgets

### Requirement: Application.php bootstrap registers event listeners for OpenRegister events (DSH-006)
Application.php bootstrap MUST register event listeners for OpenRegister events.

**Priority:** Must **Status:** Implemented

#### Scenario: OR event listeners are wired at bootstrap
- GIVEN the app is bootstrapped via Application.php
- WHEN registration runs
- THEN the OpenRegister event listeners MUST be registered so OR events reach the app

### Requirement: Application.php bootstrap registers tool registration listener for AI agents (DSH-007)
Application.php bootstrap MUST register the tool registration listener for AI agents.

**Priority:** Must **Status:** Implemented

#### Scenario: tool registration listener is wired at bootstrap
- GIVEN the app is bootstrapped via Application.php
- WHEN registration runs
- THEN the AI-agent tool registration listener MUST be registered so the app can expose its tools

### Requirement: Application.php bootstrap loads vendor autoload for Composer dependencies (DSH-008)
Application.php bootstrap MUST load vendor autoload for Composer dependencies.

**Priority:** Must **Status:** Implemented

#### Scenario: vendor autoload is loaded at bootstrap
- GIVEN the app is bootstrapped via Application.php
- WHEN the app boots
- THEN the Composer vendor autoload MUST be loaded so third-party dependencies resolve

### Requirement: List all listings with pagination (LST-001)
The system MUST list all listings with pagination.

**Priority:** Must **Status:** Implemented

#### Scenario: list listings with pagination
- GIVEN multiple listings exist
- WHEN a GET request is made to `/api/listings`
- THEN the response MUST return the listings as a paginated collection

### Requirement: Get a single listing by ID (public endpoint) (LST-002)
The system MUST get a single listing by ID (public endpoint).

**Priority:** Must **Status:** Implemented

#### Scenario: fetch a single listing by ID
- GIVEN a listing with a known ID exists
- WHEN an unauthenticated GET request is made to `/api/listings/{id}`
- THEN the response MUST return that listing without requiring authentication

### Requirement: Create a new listing (LST-003)
The system MUST allow creating a new listing.

**Priority:** Must **Status:** Implemented

#### Scenario: create a listing
- GIVEN an authenticated user with listing data
- WHEN a POST request is made to `/api/listings`
- THEN a new listing MUST be created and returned

### Requirement: Update an existing listing (LST-004)
The system MUST allow updating an existing listing.

**Priority:** Must **Status:** Implemented

#### Scenario: update a listing
- GIVEN an existing listing
- WHEN an authenticated PUT request is made to `/api/listings/{id}` with changed fields
- THEN the listing MUST be updated and the updated representation returned

### Requirement: Delete a listing (LST-005)
The system MUST allow deleting a listing.

**Priority:** Must **Status:** Implemented

#### Scenario: delete a listing
- GIVEN an existing listing
- WHEN an authenticated DELETE request is made to `/api/listings/{id}`
- THEN the listing MUST be removed and no longer appear in the listings collection

### Requirement: Listing configuration stored in IAppConfig (LST-006)
Listing configuration MUST be stored in IAppConfig as `listing_schema` and
`listing_register`, following the OR `IAppConfig` naming convention per the
[admin-settings spec](../admin-settings/spec.md).

**Priority:** Must **Status:** Implemented

#### Scenario: listing config persisted under IAppConfig keys
- GIVEN listing configuration is set
- WHEN it is stored
- THEN it MUST be persisted in IAppConfig as `listing_schema` and `listing_register`

### Requirement: Get combined directory data from all listings (DIR-001)
The system MUST get combined directory data from all listings.

**Priority:** Must **Status:** Implemented

#### Scenario: combined directory data is returned
- GIVEN several listings exist
- WHEN a GET request is made to `/api/directory`
- THEN the response MUST return the combined directory data aggregated from all listings

### Requirement: Synchronize with an external directory URL (DIR-002)
The system MUST synchronize with an external directory URL (POST with directory
parameter).

**Priority:** Must **Status:** Implemented

#### Scenario: synchronize with an external directory URL
- GIVEN an external directory URL
- WHEN a POST request is made to `/api/directory` with the directory parameter
- THEN the system MUST synchronize listings from that external directory

### Requirement: Synchronize a specific listing's directory (DIR-003)
The system MUST synchronize a specific listing's directory.

**Priority:** Must **Status:** Implemented

#### Scenario: synchronize a single listing's directory
- GIVEN a listing referencing an external directory
- WHEN a directory sync is triggered for that listing
- THEN the system MUST synchronize only that listing's directory

### Requirement: Synchronize all directories via cron (every hour) (DIR-004)
The system MUST synchronize all directories via cron (every hour).

**Priority:** Must **Status:** Implemented

#### Scenario: hourly cron synchronizes all directories
- GIVEN the directory sync cron job is registered
- WHEN the hourly schedule fires
- THEN all directories MUST be synchronized

### Requirement: Add a new listing from a URL (public endpoint) (DIR-005)
The system MUST allow adding a new listing from a URL (public endpoint).

**Priority:** Must **Status:** Implemented

#### Scenario: add a listing from a URL
- GIVEN a directory or publications URL
- WHEN an unauthenticated POST request is made to `/api/listings/add` with that URL
- THEN a listing MUST be created from the URL

### Requirement: Anti-loop protection during broadcast sync cycles (DIR-006)
The system MUST provide anti-loop protection during broadcast sync cycles.

**Priority:** Should **Status:** Implemented

#### Scenario: broadcast cycle does not loop
- GIVEN two instances broadcasting to each other
- WHEN a broadcast sync cycle runs
- THEN anti-loop protection MUST prevent the sync from re-triggering itself indefinitely

### Requirement: Broadcast this directory to external instances (cron every 4 hours) (DIR-007)
The system MUST broadcast this directory to external instances on a cron every 4 hours.

**Priority:** Should **Status:** Bug (Not Registered in info.xml)

#### Scenario: directory is broadcast to external instances
- GIVEN the broadcast cron job is scheduled every 4 hours
- WHEN the schedule fires
- THEN this directory MUST be broadcast to the known external instances

### Requirement: CORS support on directory endpoints (DIR-008)
The system MUST support CORS on directory endpoints.

**Priority:** Must **Status:** Implemented

#### Scenario: directory endpoints answer cross-origin requests
- GIVEN an external instance calls a directory endpoint cross-origin
- WHEN the request is made to `/api/directory`
- THEN the response MUST include CORS headers so the cross-origin call succeeds

### Requirement: Publication endpoint auto-detection from directory URLs (DIR-009)
The system MUST support publication endpoint auto-detection from directory URLs.

**Priority:** Should **Status:** Implemented

#### Scenario: publication endpoint is auto-detected
- GIVEN a directory URL that exposes a publications endpoint
- WHEN the directory is synchronized
- THEN the system MUST auto-detect and record the publications endpoint URL

### Requirement: Listing staleness checking during directory sync (DIR-010)
The system MUST perform listing staleness checking during directory sync.

**Priority:** Should **Status:** Implemented

#### Scenario: stale listings are detected during sync
- GIVEN listings that have not been refreshed within the staleness window
- WHEN a directory sync runs
- THEN the system MUST check and flag the stale listings

### Requirement: Catalog-to-listing conversion during sync (DIR-011)
The system MUST perform catalog-to-listing conversion during sync.

**Priority:** Should **Status:** Implemented

#### Scenario: catalogs are converted to listings on sync
- GIVEN a synced directory exposes catalogs
- WHEN the sync runs
- THEN each catalog MUST be converted into a corresponding listing

### Requirement: Manifest-driven SPA shell and main navigation (DSH-009)
The frontend SPA SHALL render through a manifest-driven `CnAppRoot` shell
(`App.vue`) for app id `opencatalogi`, passing the app manifest, custom
components, page types, a per-app translate closure, and a computed
`permissions` array.

**Priority:** Must **Status:** Implemented

#### Scenario: SPA renders through the manifest-driven shell
- GIVEN the opencatalogi SPA loads
- WHEN `App.vue` mounts
- THEN it MUST render through `CnAppRoot` passing the manifest, custom components, page types, translate closure, and computed permissions

### Requirement: Dashboard overview view (DSH-010)
The system SHALL provide a `Dashboard.vue` overview. The total publication
count MUST be sourced from OR's `x-openregister-aggregations` declaration on
the publications schema (see DSH-OR-001), NOT from a bespoke count query.

**Priority:** Should **Status:** Implemented (aggregation citation added by Phase 7)

#### Scenario: dashboard overview shows the publication count
- GIVEN `Dashboard.vue` renders the overview
- WHEN it displays the total publication count
- THEN the count MUST be sourced from OR's `x-openregister-aggregations` declaration, not a bespoke count query

### Requirement: Unpublished-content dashboard widgets (DSH-011)
The system SHALL provide two Nextcloud dashboard widgets —
`UnpublishedAttachmentsWidget` and `UnpublishedPublicationsWidget`. Counts
MUST come from OR schema aggregations (DSH-OR-001).

**Priority:** Should **Status:** Implemented (aggregation citation added by Phase 7)

#### Scenario: unpublished widgets render their counts
- GIVEN the Nextcloud dashboard shows the opencatalogi widgets
- WHEN `UnpublishedAttachmentsWidget` and `UnpublishedPublicationsWidget` render
- THEN their counts MUST be sourced from OR schema aggregations

### Requirement: Directory management UI (DIR-012)
The system SHALL provide a directory management frontend: a `DirectorySideBar`,
an `AddDirectoryModal`, and a `ViewDirectoryModal`.

**Priority:** Should **Status:** Implemented

#### Scenario: directory management frontend is available
- GIVEN a user opens the directory view
- WHEN the directory UI loads
- THEN the `DirectorySideBar`, `AddDirectoryModal`, and `ViewDirectoryModal` MUST be available for managing directories

### Requirement: Listing management UI (LST-007)
The system SHALL provide listing management dialogs: an `EditListingModal` and
a `DeleteListingDialog`.

**Priority:** Should **Status:** Implemented

#### Scenario: listing management dialogs are available
- GIVEN a user manages a listing
- WHEN they edit or delete it
- THEN the `EditListingModal` and `DeleteListingDialog` MUST be available to perform those actions

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
