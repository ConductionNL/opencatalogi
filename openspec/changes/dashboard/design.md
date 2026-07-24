# Design: dashboard

## Context

OpenCatalogi's main entry point is `DashboardController::page()` which renders the Vue SPA template at `/apps/opencatalogi`. The SPA handles client-side routing to all feature pages (catalogi, publications, search, organizations, themes, glossary, pages, menus, directory). The directory subsystem is managed by `ListingController` and `DirectoryController`, with `DirectoryService` centralising federation logic.

**Known gaps to address:**
- **Gap 17 (DSH-004)**: `DashboardController::index()` was deleted but the `dashboard#index` GET route still exists in `routes.php`. Any call to `/apps/opencatalogi/index` results in a method-not-found runtime error.
- **Gap 12 (DIR-007)**: `lib/Cron/Broadcast.php` is a fully implemented `TimedJob` (runs every 4 hours, calls `BroadcastService::broadcast(null)`) but is not listed under `<background-jobs>` in `appinfo/info.xml`. Nextcloud only discovers background jobs declared there, so broadcasting never runs.
- **Gap 21 (DSH-005..008)**: `Application.php` bootstrap registers widgets, event listeners, and vendor autoload — documented here for traceability, no code change expected.

**Constraints:**
- Public endpoints (`/api/listings/{id}`, `/api/listings/add`, `/api/directory`) require `#[PublicPage]` + `#[NoCSRFRequired]`.
- CORS (`#[CORS]`) required on all listing and directory public endpoints so external OpenCatalogi instances can call them from a browser context.
- Listing configuration stored in `IAppConfig`: keys `listing_schema` and `listing_register`.
- Anti-loop protection: `DirectoryService` tracks processed directory URLs with a 5-minute TTL cache.
- The `boot()` method in `Application.php` is intentionally empty; initialisation runs via the `InitializeSettings` repair step only during install/upgrade.

## Goals / Non-Goals

**Goals:**
- Remove orphan `dashboard#index` route from `routes.php` (DSH-004 dead code fix).
- Register `Broadcast` cron job in `appinfo/info.xml` (DIR-007 bug fix).
- Verify and document `Application.php` widget + event listener registrations (DSH-005..008).
- Full Listing CRUD API (LST-001..006).
- Directory sync endpoints and hourly cron (DIR-001..011).
- CORS on all public listing/directory endpoints.

**Non-Goals:**
- Redesigning the Vue SPA routing structure.
- Adding new dashboard widgets beyond the existing three.
- Changing the OpenCatalogi federation protocol or wire format.

## Decisions

### 1. Dead route DSH-004 — Remove the route
The `index()` method was intentionally deleted (marked "Dead Code" in the brief). Removing the route is cleaner than restoring a stub method; it eliminates the runtime error with no behaviour change.

### 2. Broadcast cron — Add to info.xml only
The `Broadcast` class is complete and correct. The fix is a single `<job>` line in `appinfo/info.xml` under `<background-jobs>`. No PHP code changes are needed.

### 3. Listing storage — IAppConfig keys
Listing register and schema are resolved from `IAppConfig` (`listing_register`, `listing_schema`) on every CRUD operation. This follows the established OpenRegister-backed entity pattern used across OpenCatalogi.

### 4. Anti-loop protection stays in DirectoryService
The 5-minute cached URL set (`$cachedUniqueDirectories`) already prevents infinite sync loops between federated instances. No changes needed; document in spec for traceability.

### 5. Catalog-to-listing conversion stays in DirectoryService
Remote directory data arrives as catalog objects and is converted to local listing objects by `DirectoryService`. The conversion maps catalog `title`, `summary`, `schemas`, etc. to listing fields. No interface change needed.

## Entities

### Listing
Stored in OpenRegister (register resolved from `IAppConfig:listing_register`, schema from `IAppConfig:listing_schema`).

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| catalogusId | string | Yes | ID of the remote catalog (facetable) |
| title | string | Yes | Display title (facetable) |
| summary | string | Yes | Brief description |
| description | string | No | Detailed description |
| search | URL | No | Search API endpoint |
| publications | URL | No | Publications API endpoint |
| directory | URL | No | Directory URL for sync |
| metadata | array(string) | No | Metadata fields |
| status | enum | No | development, beta, stable, obsolete |
| statusMessage | string | No | Latest status message |
| statusCode | number | No | HTTP status code from last check |
| lastSync | datetime | No | Last synchronisation timestamp (facetable) |
| integrationLevel | enum | No | none, connection, search (facetable) |
| default | boolean | No | Whether this is the default listing (facetable) |
| organization | object | No | Organization object |
| schemas | array | No | Publication types in this listing |
| version | string | No | OpenCatalogi version of the source |

## Seed Data (Dutch Listings)

```json
[
  {
    "catalogusId": "cat-gemeente-amsterdam",
    "title": "Open Catalogi Amsterdam",
    "summary": "De officiële publicatiecatalogus van de Gemeente Amsterdam.",
    "description": "Bevat alle WOO-publicaties van de Gemeente Amsterdam, inclusief besluiten en beleidsstukken.",
    "search": "https://catalogi.amsterdam.nl/api/search",
    "publications": "https://catalogi.amsterdam.nl/api/publications",
    "directory": "https://catalogi.amsterdam.nl/api/directory",
    "status": "stable",
    "integrationLevel": "search",
    "default": true,
    "version": "0.1.5"
  },
  {
    "catalogusId": "cat-gemeente-rotterdam",
    "title": "Transparantieportal Rotterdam",
    "summary": "WOO-publicaties van de Gemeente Rotterdam.",
    "directory": "https://transparantie.rotterdam.nl/api/directory",
    "publications": "https://transparantie.rotterdam.nl/api/publications",
    "status": "beta",
    "integrationLevel": "connection",
    "default": false,
    "version": "0.1.3"
  },
  {
    "catalogusId": "cat-rijksoverheid",
    "title": "Rijkscatalogus",
    "summary": "Federale publicatiecatalogus van het Rijk.",
    "search": "https://open.overheid.nl/api/search",
    "publications": "https://open.overheid.nl/api/publications",
    "directory": "https://open.overheid.nl/api/directory",
    "status": "stable",
    "integrationLevel": "search",
    "default": false,
    "version": "0.2.0"
  },
  {
    "catalogusId": "cat-gemeente-tilburg",
    "title": "Open Data Tilburg",
    "summary": "Publicaties en besluiten van de Gemeente Tilburg.",
    "directory": "https://woo.tilburg.nl/api/directory",
    "status": "development",
    "integrationLevel": "none",
    "default": false,
    "lastSync": "2026-05-01T10:00:00Z"
  },
  {
    "catalogusId": "cat-vng",
    "title": "VNG Realisatie Catalogus",
    "summary": "Standaarden en referentiecatalogi van de VNG.",
    "search": "https://catalogi.vng.nl/api/search",
    "publications": "https://catalogi.vng.nl/api/publications",
    "directory": "https://catalogi.vng.nl/api/directory",
    "status": "stable",
    "integrationLevel": "search",
    "schemas": ["WOO", "DCAT"],
    "default": false,
    "version": "1.0.0"
  }
]
```

## Reuse Analysis (ADR-012)
- `ObjectService` from OpenRegister handles all listing persistence — no custom Mapper class needed.
- `IAppConfig` stores `listing_register` / `listing_schema` — established pattern, no new config mechanism.
- `GuzzleHttp` (already in vendor autoload) handles HTTP calls to external directories.
- `DirectoryService` centralises all federation sync logic — no duplication in controllers.
- `BroadcastService` already implements broadcast logic — the only missing piece is the `info.xml` declaration.

## File Changes

| File | Change |
|------|--------|
| `appinfo/routes.php` | Remove dead `['name' => 'dashboard#index', ...]` route entry |
| `appinfo/info.xml` | Add `<job>OCA\OpenCatalogi\Cron\Broadcast</job>` under `<background-jobs>` |
| `lib/AppInfo/Application.php` | Verify widget + event listener registrations (no code change expected) |
| `lib/Controller/ListingController.php` | Verify CRUD methods, CORS, and auth annotations |
| `lib/Controller/DirectoryController.php` | Verify sync endpoints and CORS |
| `lib/Service/DirectoryService.php` | Verify sync, anti-loop, staleness check, catalog-to-listing conversion |
| `tests/Unit/Controller/ListingControllerTest.php` | Add/verify coverage for all CRUD operations |
| `tests/Unit/Controller/DirectoryControllerTest.php` | Add/verify coverage for sync operations |
