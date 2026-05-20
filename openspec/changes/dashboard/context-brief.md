---
status: reviewed
---

# Dashboard and Directory

## Purpose

The dashboard provides the main entry point for the OpenCatalogi Nextcloud app, serving the Vue SPA for all internal views. The directory system manages the network of interconnected OpenCatalogi instances, enabling federation through listings (external catalog registrations), directory synchronization, and broadcast notifications. Listings represent external catalogs that can be synchronized and searched, forming the backbone of the decentralized catalog network.

## Requirements

### Dashboard and UI

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| DSH-001 | Serve the Vue SPA template for the main app page | Must | Implemented |
| DSH-002 | Support deep-link routing for all SPA pages (catalogi, publications, search, themes, glossary, pages, menus, directory, organizations) | Must | Implemented |
| DSH-003 | Content Security Policy allows connect to all domains (for federation HTTP requests) | Must | Implemented |
| DSH-004 | Dashboard data endpoint returns basic status info | Should | Dead Code (route exists but controller method removed) |
| DSH-005 | Application.php bootstrap registers dashboard widgets (CatalogWidget, UnpublishedPublicationsWidget, UnpublishedAttachmentsWidget) | Must | Implemented |
| DSH-006 | Application.php bootstrap registers event listeners for OpenRegister events | Must | Implemented |
| DSH-007 | Application.php bootstrap registers tool registration listener for AI agents | Must | Implemented |
| DSH-008 | Application.php bootstrap loads vendor autoload for Composer dependencies | Must | Implemented |

### Listings (CRUD)

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| LST-001 | List all listings with pagination | Must | Implemented |
| LST-002 | Get a single listing by ID (public endpoint) | Must | Implemented |
| LST-003 | Create a new listing | Must | Implemented |
| LST-004 | Update an existing listing | Must | Implemented |
| LST-005 | Delete a listing | Must | Implemented |
| LST-006 | Listing configuration stored in IAppConfig as `listing_schema` and `listing_register` | Must | Implemented |

### Directory and Synchronization

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| DIR-001 | Get combined directory data from all listings | Must | Implemented |
| DIR-002 | Synchronize with an external directory URL (POST with directory parameter) | Must | Implemented |
| DIR-003 | Synchronize a specific listing's directory | Must | Implemented |
| DIR-004 | Synchronize all directories via cron (every hour) | Must | Implemented |
| DIR-005 | Add a new listing from a URL (public endpoint) | Must | Implemented |
| DIR-006 | Anti-loop protection during broadcast sync cycles | Should | Implemented |
| DIR-007 | Broadcast this directory to external instances (cron every 4 hours) | Should | Bug (Not Registered) |
| DIR-008 | CORS support on directory endpoints | Must | Implemented |
| DIR-009 | Publication endpoint auto-detection from directory URLs | Should | Implemented |
| DIR-010 | Listing staleness checking during directory sync | Should | Implemented |
| DIR-011 | Catalog-to-listing conversion during sync | Should | Implemented |

## DashboardController Dead Code (Gap 17)

The `DashboardController::index()` method has been **removed** from the controller class. The DashboardController now only has a `page()` method that renders the SPA template. However, the route `['name' => 'dashboard#index', 'url' => '/index', 'verb' => 'GET']` still exists in `routes.php`, which means the `/index` endpoint will return a method-not-found error at runtime.

**Status**: Dead Code - route exists in routes.php but the controller method has been removed.

## Application.php Bootstrap (Gap 21)

The `Application` class (`lib/AppInfo/Application.php`) implements `IBootstrap` and registers the following during `register()`:

### Vendor Autoload
```php
include_once __DIR__ . '/../../vendor/autoload.php';
```
Loads Composer dependencies (mPDF, Twig, Guzzle, Elasticsearch client, etc.).

### Dashboard Widgets
| Widget | Class | Description |
|--------|-------|-------------|
| CatalogWidget | `Dashboard\CatalogWidget` | Catalog overview on Nextcloud dashboard |
| UnpublishedPublicationsWidget | `Dashboard\UnpublishedPublicationsWidget` | Shows unpublished publications |
| UnpublishedAttachmentsWidget | `Dashboard\UnpublishedAttachmentsWidget` | Shows unpublished attachments |

### Event Listeners
| Event | Listener | Purpose |
|-------|----------|---------|
| `ObjectCreatedEvent` | `ObjectCreatedEventListener` | Auto-publishing on object creation (see [auto-publishing spec](../auto-publishing/spec.md)) |
| `ObjectUpdatedEvent` | `ObjectUpdatedEventListener` | Auto-publishing on object update |
| `ObjectCreatedEvent` | `CatalogCacheEventListener` | Cache invalidation/warmup on catalog creation |
| `ObjectUpdatedEvent` | `CatalogCacheEventListener` | Cache invalidation/warmup on catalog update |
| `ObjectDeletedEvent` | `CatalogCacheEventListener` | Cache invalidation on catalog deletion |
| `ToolRegistrationEvent` | `ToolRegistrationListener` | Register CMS Tool for AI agents (see [cms-tool spec](../cms-tool/spec.md)) |

### boot() Method
The `boot()` method is intentionally empty. Initialization is handled by the `InitializeSettings` repair step (`lib/Repair/InitializeSettings.php`), which runs only during app install/upgrade rather than on every request.

## Broadcast Cron Job Registration Bug (Gap 12)

The `Broadcast` cron job class exists at `lib/Cron/Broadcast.php` and is fully implemented (extends `TimedJob`, runs every 4 hours, calls `BroadcastService::broadcast(null)`). However, it is **NOT registered** in `info.xml`:

```xml
<background-jobs>
    <job>OCA\OpenCatalogi\Cron\DirectorySync</job>
    <!-- Broadcast is MISSING from this list -->
</background-jobs>
```

Only `DirectorySync` is registered as a background job. The `Broadcast` class will **never execute** because Nextcloud only runs background jobs that are declared in `info.xml`. This means directory broadcasting to external instances does not happen automatically.

**Status**: Bug - Broadcast cron job exists in code but is not registered and will never execute.

## Directory Sync Details (Gap 19)

### Anti-Loop Protection
The `DirectoryService` tracks unique directory URLs in a cached array (`$cachedUniqueDirectories` with a 5-minute TTL). During broadcast sync cycles, it checks if a directory URL has already been processed to prevent infinite synchronization loops between instances.

### Publication Endpoint Auto-Detection
When syncing a directory, the service attempts to detect the publications endpoint from the directory data. Listings include a `publications` field containing the URL where publications can be fetched.

### Listing Staleness Checking
During synchronization, the `lastSync` timestamp on listings is compared with the current time to determine if a listing needs to be re-synced.

### Catalog-to-Listing Conversion
When receiving directory data from a remote instance, catalogs from that instance are converted to listing objects in the local system. The conversion maps catalog properties (title, summary, schemas, etc.) to listing fields.

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
| metadata | array(string) | No | Metadata fields |
| status | enum | No | development, beta, stable, obsolete |
| statusMessage | string | No | Latest status test message |
| statusCode | number | No | HTTP status code of the listing |
| lastSync | datetime | No | Timestamp of last synchronization (facetable) |
| integrationLevel | enum | No | none, connection, search (facetable) |
| default | boolean | No | Whether this is the default listing (facetable) |
| organization | object | No | Organization object |
| schemas | array | No | Types of publications in this listing |
| version | string | No | OpenCatalogi version of the source |

## User Interface

- **DashboardIndex.vue** (`/`) - Main dashboard with widgets
- **CatalogiWidget.vue** - Catalog overview widget
- **UnpublishedPublicationsWidget.vue** - Widget showing unpublished publications
- **UnpublishedAttachmentsWidget.vue** - Widget showing unpublished attachments
- **DashboardSideBar.vue** - Dashboard sidebar
- **DirectoryIndex.vue** (`/directory`) - Directory management page
- **DirectorySideBar.vue** - Directory sidebar
- **EditListingModal.vue** - Edit listing modal
- **ViewDirectoryModal.vue** - View directory details
- **AddDirectoryModal.vue** - Add new directory listing
- **MainMenu.vue** - Main navigation menu component

### SPA Deep Link Routes

| Route | Controller Method | Description |
|-------|-------------------|-------------|
| `/` | ui#dashboard | Dashboard |
| `/catalogi` | ui#catalogi | Catalog management |
| `/publications/{catalogSlug}` | ui#publicationsIndex | Publications list |
| `/publications/{catalogSlug}/{id}` | ui#publicationsPage | Publication detail |
| `/search` | ui#search | Search interface |
| `/organizations` | ui#organizations | Organization management |
| `/themes` | ui#themes | Theme management |
| `/glossary` | ui#glossary | Glossary management |
| `/pages` | ui#pages | Page management |
| `/menus` | ui#menus | Menu management |
| `/directory` | ui#directory | Directory/listing management |

## API Endpoints

### Dashboard

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/` | Serve SPA template (dashboard page) |
| GET | `/index` | Dashboard data endpoint (**Dead Code**: route exists but controller method has been removed) |

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
| OPTIONS | `/api/listings` | CORS preflight |
| OPTIONS | `/api/listings/{id}` | CORS preflight |
| OPTIONS | `/api/listings/sync` | CORS preflight |
| OPTIONS | `/api/listings/add` | CORS preflight |

### Directory

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/directory` | Get combined directory data (public) |
| POST | `/api/directory` | Sync with external directory URL (public) |
| OPTIONS | `/api/directory` | CORS preflight |

## Scenarios

### Scenario: SPA deep link routing
- GIVEN a user navigates to `/apps/opencatalogi/publications/my-catalog`
- WHEN the UiController.publicationsIndex() handles the request
- THEN the same SPA template (`index`) is returned
- AND the Vue router handles client-side routing to the correct view
- AND CSP allows connect to all domains for API calls

### Scenario: Create a listing
- GIVEN valid listing data is provided
- WHEN a POST request is made to `/api/listings`
- THEN the listing is saved via ObjectService.saveObject() with the configured listing register/schema
- AND the created listing object is returned

### Scenario: Synchronize a specific listing
- GIVEN a listing with ID "abc" has a directory URL configured
- WHEN a POST request is made to `/api/listings/sync` with ID "abc"
- THEN the listing is fetched to get its directory URL
- AND DirectoryService.syncDirectory() is called with that URL
- AND the sync result is returned

### Scenario: Add listing from external URL
- GIVEN an external OpenCatalogi instance at "https://external.example.nl"
- WHEN a POST request is made to `/api/listings/add` with url="https://external.example.nl/api/directory"
- THEN DirectoryService.syncDirectory() processes the URL
- AND a new listing is created or an existing one is updated
- AND the result is returned

### Scenario: Cron-based directory synchronization
- GIVEN the DirectorySync cron job runs every hour
- WHEN the cron job executes
- THEN DirectoryService.doCronSync() syncs all known directories
- AND parallel runs are prevented

### Scenario: Broadcast cron job (non-functional)
- GIVEN the Broadcast cron job class exists but is NOT registered in info.xml
- WHEN the Nextcloud background job system runs
- THEN the Broadcast job is never discovered or executed
- AND no automatic broadcasting occurs

## Flow/Events and Flow/Operations (Gap 11) - REMOVED

The `lib/Flow/` directory and all its files (AutomatedPublishing, PublicationEvent, ListingEvent, CatalogEvent, AttachmentEvent) have been **deleted** from the codebase. These were previously broken stubs for Nextcloud Workflow (Flow) integration that were never completed. They no longer exist.

## Dependencies

- **OpenRegister ObjectService** - CRUD operations for listing objects
- **DirectoryService** - Directory synchronization, listing management, cron sync
- **BroadcastService** - Broadcasting this instance to remote directories (currently non-functional via cron)
- **Nextcloud IAppConfig** - listing_schema, listing_register configuration
- **Nextcloud BackgroundJob system** - DirectorySync (hourly) registered; Broadcast (4-hourly) NOT registered
- **GuzzleHttp** - HTTP client for remote directory communication
