# Dashboard and Directory

## Problem
The OpenCatalogi Nextcloud app requires a fully functional main entry point (dashboard) and directory management system. The dashboard serves the Vue SPA for all internal views and must support deep-link routing for all feature pages. The directory system manages a federated network of OpenCatalogi instances via listings — external catalog registrations that enable decentralized catalog discovery and synchronization.

Two known bugs exist that must be resolved:
1. **Dead route DSH-004 (Gap 17)**: `DashboardController::index()` was deleted but the `dashboard#index` route still exists in `routes.php`, causing a runtime error on `GET /index`.
2. **Broadcast cron not registered (Gap 12/DIR-007)**: The `Broadcast` cron class exists and is fully implemented but is not declared in `appinfo/info.xml`, so Nextcloud never executes it and automatic broadcasting to external instances does not happen.

## Proposed Solution
Verify and fix the Dashboard and Directory implementation following the detailed specification. Key requirements include:
- Requirement: SPA template MUST be served for all registered deep-link routes
- Requirement: Listing CRUD endpoints MUST support full lifecycle management
- Requirement: Directory synchronization MUST run on cron (hourly) with anti-loop protection
- Requirement: Fix dead route (DSH-004) — remove orphan `dashboard#index` route from `routes.php`
- Requirement: Fix Broadcast cron registration (DIR-007) — register in `appinfo/info.xml`
- Requirement: Application.php MUST register all dashboard widgets and event listeners on bootstrap

## Scope
This change covers all requirements defined in the dashboard specification: SPA routing (DSH-001..003, DSH-005..008), listing CRUD (LST-001..006), directory synchronization (DIR-001..011), dead code removal (DSH-004), and the Broadcast cron registration bug fix (DIR-007).

## Success Criteria
- Vue SPA is served and all deep-link routes return the SPA template
- Listing CRUD (create, read, update, delete, list) works via REST API
- DirectorySync cron runs hourly and syncs all known directories without infinite loops
- Broadcast cron is registered in `info.xml` and runs every 4 hours
- Dead route `/index` is resolved (route removed from `routes.php`)
- All 3 dashboard widgets (CatalogWidget, UnpublishedPublicationsWidget, UnpublishedAttachmentsWidget) are registered
- All event listeners (ObjectCreated/Updated/Deleted for auto-publishing and cache, ToolRegistration) are registered
