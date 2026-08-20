---
status: proposed
---

# Dashboard and Directory

## Purpose
The dashboard provides the main entry point for the OpenCatalogi Nextcloud app, serving the Vue SPA for all internal views. The directory system manages the network of interconnected OpenCatalogi instances, enabling federation through listings (external catalog registrations), directory synchronisation, and broadcast notifications. Listings form the backbone of the decentralised catalog network.

## Context
OpenCatalogi is a government transparency publication platform deployed by Dutch municipalities. The app's frontend is a Vue SPA served by a single PHP controller; all routing to feature pages (catalogi, publications, search, organizations, themes, glossary, pages, menus, directory) is handled client-side by the Vue router. The directory subsystem enables federated search across multiple OpenCatalogi instances.

**Known gaps addressed by this spec:**
- **DSH-004 / Gap 17**: `DashboardController::index()` was deleted but the `dashboard#index` GET route remains in `routes.php`, causing a runtime error on `GET /index`.
- **DIR-007 / Gap 12**: `lib/Cron/Broadcast.php` is fully implemented but not listed in `appinfo/info.xml`, so automatic broadcasting never runs.

**Relation to existing specs:**
- `auto-publishing` spec: `ObjectCreatedEventListener` and `ObjectUpdatedEventListener` (registered in `Application.php`) implement auto-publishing behaviour.
- `cms-tool` spec: `ToolRegistrationListener` (registered in `Application.php`) registers the CMS Tool for AI agents.

**Relation to existing OpenCatalogi entities:**
- Listings are stored via OpenRegister's `ObjectService` using the `listing_register` and `listing_schema` keys from `IAppConfig`.
- No custom Entity/Mapper classes are used; all persistence is through OpenRegister.

## Requirements

### Requirement: SPA template MUST be served for all registered deep-link routes (DSH-001, DSH-002)
The `UiController` MUST return the same SPA template (`index`) for every registered frontend route so that the Vue router handles client-side navigation.

#### Scenario: Root dashboard route serves SPA template
- GIVEN a logged-in user navigates to `/apps/opencatalogi`
- WHEN `DashboardController::page()` handles the request
- THEN the response MUST be an HTML page containing the Vue app root element
- AND the response status MUST be 200

#### Scenario: Deep-link to publications page serves SPA template
- GIVEN a user navigates directly to `/apps/opencatalogi/publications/my-catalog`
- WHEN `UiController::publicationsIndex()` handles the request
- THEN the same SPA template (`index`) MUST be returned
- AND the Vue router handles client-side routing to the publications view
- AND no 404 MUST be returned for any registered deep-link path

#### Scenario: Deep-link to directory page serves SPA template
- GIVEN a user navigates directly to `/apps/opencatalogi/directory`
- WHEN `UiController::directory()` handles the request
- THEN the SPA template MUST be returned
- AND the Vue router navigates to the DirectoryIndex view

#### Scenario: All registered SPA routes return the template
- GIVEN any of the routes: `/`, `/catalogi`, `/publications/{slug}`, `/publications/{slug}/{id}`, `/search`, `/organizations`, `/themes`, `/glossary`, `/pages`, `/menus`, `/directory`
- WHEN the corresponding `UiController` method handles the request
- THEN the response MUST be 200 with the SPA template
- AND no route MUST return a 404 or 500

### Requirement: Content Security Policy MUST allow federation HTTP requests (DSH-003)
The CSP applied to the SPA page MUST permit `connect-src` to all domains so that the Vue app can make API calls to external OpenCatalogi instances.

#### Scenario: CSP allows connect to external domains
- GIVEN the SPA page is served
- WHEN the browser inspects the `Content-Security-Policy` response header
- THEN the `connect-src` directive MUST include `*` or equivalent to allow all domain connections
- AND external API calls from the Vue app to remote OpenCatalogi instances MUST not be blocked by CSP

### Requirement: Dead route DSH-004 MUST be removed (DSH-004)
The route `['name' => 'dashboard#index', 'url' => '/index', 'verb' => 'GET']` references a controller method that no longer exists. This route MUST be removed from `appinfo/routes.php`.

#### Scenario: GET /index no longer causes a runtime error
- GIVEN the `dashboard#index` route has been removed from `routes.php`
- WHEN a request is made to `GET /apps/opencatalogi/index`
- THEN the response MUST be a 404 (route not found), not a 500 (method not found)
- AND no PHP error MUST be logged

#### Scenario: No other routes are affected by the removal
- GIVEN the dead route is removed
- WHEN all other SPA deep-link routes are requested
- THEN they MUST continue to return 200 with the SPA template
- AND the removal MUST not break any functional endpoint

### Requirement: Application.php MUST register dashboard widgets on bootstrap (DSH-005)
The `Application` class MUST register all three dashboard widgets so they appear on the Nextcloud dashboard.

#### Scenario: CatalogWidget is registered
- GIVEN the OpenCatalogi app is enabled
- WHEN the Nextcloud dashboard is loaded
- THEN the `CatalogWidget` MUST appear as an available widget
- AND it MUST show a catalog overview

#### Scenario: UnpublishedPublicationsWidget is registered
- GIVEN the OpenCatalogi app is enabled
- WHEN the Nextcloud dashboard is loaded
- THEN the `UnpublishedPublicationsWidget` MUST be available
- AND it MUST display publications awaiting publication

#### Scenario: UnpublishedAttachmentsWidget is registered
- GIVEN the OpenCatalogi app is enabled
- WHEN the Nextcloud dashboard is loaded
- THEN the `UnpublishedAttachmentsWidget` MUST be available
- AND it MUST display attachments awaiting publication

### Requirement: Application.php MUST register event listeners on bootstrap (DSH-006, DSH-007)
The `Application` class MUST register event listeners for OpenRegister object lifecycle events and AI tool registration.

#### Scenario: ObjectCreatedEvent triggers auto-publishing listener
- GIVEN the OpenCatalogi app is bootstrapped
- WHEN an `ObjectCreatedEvent` is dispatched by OpenRegister
- THEN `ObjectCreatedEventListener` MUST handle it (auto-publishing, see auto-publishing spec)
- AND `CatalogCacheEventListener` MUST handle it (cache invalidation/warmup)

#### Scenario: ObjectUpdatedEvent triggers update listeners
- GIVEN the OpenCatalogi app is bootstrapped
- WHEN an `ObjectUpdatedEvent` is dispatched by OpenRegister
- THEN `ObjectUpdatedEventListener` MUST handle it (auto-publishing on update)
- AND `CatalogCacheEventListener` MUST handle it (cache invalidation/warmup)

#### Scenario: ObjectDeletedEvent triggers cache listener
- GIVEN the OpenCatalogi app is bootstrapped
- WHEN an `ObjectDeletedEvent` is dispatched by OpenRegister
- THEN `CatalogCacheEventListener` MUST handle it to invalidate the catalog cache

#### Scenario: ToolRegistrationEvent registers CMS Tool for AI agents
- GIVEN the OpenCatalogi app is bootstrapped
- WHEN a `ToolRegistrationEvent` is dispatched
- THEN `ToolRegistrationListener` MUST register the CMS Tool (see cms-tool spec)

### Requirement: Vendor autoload MUST be loaded on bootstrap (DSH-008)
`Application::register()` MUST include `vendor/autoload.php` before any Composer dependencies (mPDF, Twig, Guzzle, Elasticsearch client) are used.

#### Scenario: Composer dependencies are available
- GIVEN the OpenCatalogi app is bootstrapped
- WHEN any service that depends on Guzzle, mPDF, or Twig is instantiated
- THEN the dependency MUST be available (autoload was executed)
- AND no `class not found` error MUST occur for vendored classes

### Requirement: Listing CRUD endpoints MUST support full lifecycle management (LST-001..005)
The `ListingController` MUST expose list, get, create, update, and delete operations.

#### Scenario: List all listings with pagination
- GIVEN listings exist in the system
- WHEN `GET /index.php/apps/opencatalogi/api/listings` is called with a valid session
- THEN the response MUST be 200 with a paginated list of listing objects
- AND the response MUST include `total` and `pages` fields

#### Scenario: Get a single listing by ID (public)
- GIVEN a listing with ID "abc123" exists
- WHEN `GET /index.php/apps/opencatalogi/api/listings/abc123` is called (no authentication required)
- THEN the response MUST be 200 with the listing object
- AND the endpoint MUST be accessible without authentication

#### Scenario: Create a new listing
- GIVEN valid listing data with `catalogusId`, `title`, and `summary`
- WHEN a POST request is made to `/api/listings` with authenticated session
- THEN the listing MUST be saved via `ObjectService.saveObject()` using `listing_register` and `listing_schema`
- AND the response MUST be 201 with the created listing object including its assigned ID

#### Scenario: Update an existing listing
- GIVEN a listing with ID "abc123" exists
- WHEN a PUT request is made to `/api/listings/abc123` with updated fields and authenticated session
- THEN the listing MUST be updated via `ObjectService.saveObject()`
- AND the response MUST be 200 with the updated listing object

#### Scenario: Delete a listing
- GIVEN a listing with ID "abc123" exists
- WHEN a DELETE request to `/api/listings/abc123` is made with authenticated session
- THEN the listing MUST be removed from OpenRegister
- AND the response MUST be 200 or 204

#### Scenario: Unauthenticated access to write endpoints is rejected
- GIVEN no authenticated session
- WHEN POST, PUT, or DELETE is called on `/api/listings` or `/api/listings/{id}`
- THEN the response MUST be 401 or redirect to login
- AND no modification MUST be made

### Requirement: Listing configuration MUST be stored in IAppConfig (LST-006)
The `listing_schema` and `listing_register` values MUST be read from `IAppConfig` on every listing operation.

#### Scenario: Listing operations use configured register and schema
- GIVEN `listing_register` is set to "catalogi-register" and `listing_schema` to "listing-schema" in IAppConfig
- WHEN any listing CRUD operation is performed
- THEN `ObjectService` MUST be called with those register and schema values
- AND hardcoded register/schema identifiers MUST NOT be used

### Requirement: Directory endpoint MUST return combined data from all listings (DIR-001)
`GET /api/directory` MUST aggregate and return directory data from all known listings.

#### Scenario: Combined directory data is returned
- GIVEN 3 listings exist, each with a `directory` URL
- WHEN `GET /api/directory` is called (no authentication required)
- THEN the response MUST be 200 with combined directory data from all listings
- AND the endpoint MUST be accessible without authentication

### Requirement: Directory synchronisation MUST be triggerable via API (DIR-002, DIR-003)
`POST /api/directory` MUST trigger a sync with an external directory URL. `POST /api/listings/sync` with an ID MUST sync a specific listing's directory.

#### Scenario: Synchronise with an external directory URL
- GIVEN an external OpenCatalogi instance at "https://external.example.nl/api/directory"
- WHEN a POST request is made to `/api/directory` with `{"directory": "https://external.example.nl/api/directory"}`
- THEN `DirectoryService::syncDirectory()` MUST be called with that URL
- AND the sync result MUST be returned in the response

#### Scenario: Synchronise a specific listing by ID
- GIVEN a listing with ID "abc123" has `directory` set to "https://external.example.nl/api/directory"
- WHEN a POST request is made to `/api/listings/sync` with `{"id": "abc123"}`
- THEN the listing is fetched to retrieve its directory URL
- AND `DirectoryService::syncDirectory()` MUST be called with that URL
- AND the sync result MUST be returned

### Requirement: DirectorySync cron MUST run hourly (DIR-004)
The `DirectorySync` background job MUST be registered in `appinfo/info.xml` and run every hour.

#### Scenario: Hourly directory synchronisation
- GIVEN the DirectorySync cron job is registered and the app is installed
- WHEN the Nextcloud background job system executes
- THEN `DirectoryService::doCronSync()` MUST be called to sync all known directories
- AND parallel runs MUST be prevented by the job's registration mechanism

### Requirement: Add listing from external URL MUST work as a public endpoint (DIR-005)
`POST /api/listings/add` MUST accept a URL and add or update the corresponding listing without requiring authentication.

#### Scenario: Add listing from external URL
- GIVEN an external OpenCatalogi instance publishes a directory at a known URL
- WHEN a POST request is made to `/api/listings/add` with `{"url": "https://external.example.nl/api/directory"}`
- THEN `DirectoryService::syncDirectory()` MUST process the URL
- AND a new listing MUST be created or an existing one updated
- AND the endpoint MUST be accessible without authentication

### Requirement: Anti-loop protection MUST prevent infinite sync cycles (DIR-006)
`DirectoryService` MUST track processed directory URLs to prevent infinite synchronisation loops between federated instances.

#### Scenario: Directory URL processed only once per sync cycle
- GIVEN two OpenCatalogi instances A and B that each list the other in their directory
- WHEN instance A initiates a sync
- THEN each directory URL MUST be processed at most once within the 5-minute TTL window
- AND the sync MUST terminate rather than loop indefinitely

#### Scenario: Cached URL set expires after TTL
- GIVEN a directory URL was processed 6 minutes ago
- WHEN a new sync is triggered
- THEN the URL MUST be eligible for re-processing (TTL expired)
- AND the 5-minute TTL MUST reset

### Requirement: Broadcast cron job MUST be registered and run every 4 hours (DIR-007)
The `Broadcast` background job MUST be declared in `appinfo/info.xml` under `<background-jobs>`.

#### Scenario: Broadcast job registered in info.xml
- GIVEN `OCA\OpenCatalogi\Cron\Broadcast` is listed in `appinfo/info.xml`
- WHEN the Nextcloud background job system discovers jobs
- THEN the `Broadcast` job MUST be discovered and scheduled
- AND `BroadcastService::broadcast(null)` MUST be called every 4 hours

#### Scenario: Broadcasting before fix (regression baseline)
- GIVEN the Broadcast job is NOT listed in `info.xml`
- WHEN the Nextcloud background job system runs
- THEN the Broadcast job MUST NOT execute
- AND this scenario documents the bug state before the fix

### Requirement: CORS MUST be supported on listing and directory endpoints (DIR-008)
All public listing and directory endpoints MUST include CORS headers to allow cross-origin requests from external OpenCatalogi frontends.

#### Scenario: OPTIONS preflight request returns CORS headers
- GIVEN an external browser-based OpenCatalogi app at a different origin
- WHEN an OPTIONS preflight is sent to `/api/listings/add` or `/api/directory`
- THEN the response MUST include `Access-Control-Allow-Origin: *` (or equivalent)
- AND the response MUST include appropriate `Access-Control-Allow-Methods` and `Access-Control-Allow-Headers`

#### Scenario: Public GET endpoint returns CORS headers
- GIVEN an external origin requests `GET /api/listings/{id}`
- WHEN the response is returned
- THEN CORS headers MUST be present to allow the browser to read the response

### Requirement: Publication endpoint MUST be auto-detected from directory data (DIR-009)
When syncing a directory, `DirectoryService` MUST read the `publications` field from listing data to discover the publications endpoint.

#### Scenario: Publications endpoint discovered from directory sync
- GIVEN a remote listing's directory data includes `"publications": "https://external.example.nl/api/publications"`
- WHEN `DirectoryService::syncDirectory()` processes the listing
- THEN the `publications` field MUST be stored on the local listing object
- AND subsequent operations MAY use this URL to fetch publications from the remote instance

### Requirement: Listing staleness MUST be checked during directory sync (DIR-010)
During synchronisation, `DirectoryService` MUST compare the `lastSync` timestamp of a listing against the current time to determine if a re-sync is needed.

#### Scenario: Fresh listing is not re-synced
- GIVEN a listing with `lastSync` set to 5 minutes ago
- WHEN a directory sync runs
- THEN the listing MUST be considered fresh and MUST NOT trigger a full re-sync

#### Scenario: Stale listing is re-synced
- GIVEN a listing with `lastSync` set to more than the staleness threshold ago
- WHEN a directory sync runs
- THEN the listing MUST be considered stale and MUST be re-synchronised

### Requirement: Remote catalogs MUST be converted to local listings during sync (DIR-011)
When directory data from a remote instance contains catalog objects, `DirectoryService` MUST convert them to local listing objects.

#### Scenario: Remote catalog converted to listing
- GIVEN a remote OpenCatalogi instance returns catalog data with `title`, `summary`, and `schemas` fields
- WHEN `DirectoryService` processes the remote directory data
- THEN each remote catalog MUST be mapped to a local listing object
- AND the listing MUST have `title`, `summary`, and `schemas` populated from the remote catalog
- AND the listing MUST be saved via `ObjectService`

## MODIFIED Requirements

_None — this spec captures the current feature set with two known bug fixes._

## REMOVED Requirements

- **Flow/Events and Flow/Operations (Gap 11)**: The `lib/Flow/` directory and all its files (AutomatedPublishing, PublicationEvent, ListingEvent, CatalogEvent, AttachmentEvent) have been deleted from the codebase. They were broken stubs for Nextcloud Workflow (Flow) integration and were never completed. No requirements reference them.

## Current Implementation Status
- **DSH-001..003**: Implemented — SPA template served, deep-link routing works, CSP configured.
- **DSH-004**: Dead Code — route exists in `routes.php` but controller method is deleted. **Fix required.**
- **DSH-005..008**: Implemented — widgets, event listeners, and vendor autoload registered in `Application.php`.
- **LST-001..006**: Implemented — full CRUD for listings via `ListingController`.
- **DIR-001..003**: Implemented — directory data aggregation and sync endpoints work.
- **DIR-004**: Implemented — `DirectorySync` cron registered and runs hourly.
- **DIR-005**: Implemented — `POST /api/listings/add` public endpoint works.
- **DIR-006**: Implemented — anti-loop protection in `DirectoryService` with 5-minute TTL.
- **DIR-007**: Bug — `Broadcast` cron class exists but not registered in `info.xml`. **Fix required.**
- **DIR-008..011**: Implemented — CORS, publication endpoint auto-detection, staleness checking, catalog-to-listing conversion.

## Standards & References
- Nextcloud app background job registration: `appinfo/info.xml` `<background-jobs>` section
- Nextcloud CSP configuration: `IContentSecurityPolicyManager`
- OpenRegister ObjectService API: 3 positional args (register, schema, object) per ADR-001
- ADR-002: Public endpoints use `#[PublicPage]` + `#[NoCSRFRequired]`
- ADR-005: CORS on public endpoints via `#[CORS]`
- ADR-016: All routes declared in `appinfo/routes.php`
- ADR-029: Every route must bind to an existing controller method (gate catches DSH-004)

## Dependencies
- **OpenRegister ObjectService** — Listing CRUD persistence
- **DirectoryService** — Federation sync, anti-loop, listing staleness, catalog-to-listing conversion
- **BroadcastService** — Broadcasting this instance to remote directories (currently non-functional via cron)
- **Nextcloud IAppConfig** — `listing_schema`, `listing_register` configuration keys
- **Nextcloud BackgroundJob system** — `DirectorySync` (hourly, registered); `Broadcast` (4-hourly, fix required)
- **GuzzleHttp** — HTTP client for remote directory communication (loaded via vendor autoload)
