---
status: done
retrofit_extensions:
  - CAT-013
  - CAT-014
  - CAT-015
  - CAT-016
---

# Catalogs

## Purpose

@e2e exclude retrofit spec — public catalog HTTP API contract and backend caching/event behaviour verified by Newman API tests and PHPUnit, not browser-UI observable.

Catalogs are the top-level organizational unit in OpenCatalogi. A catalog groups publications by associating them with specific OpenRegister registers and schemas, providing a URL-slug-based namespace for the public API. Catalogs enable multi-tenant content organization where different collections of publications can be served through distinct API endpoints.
## Requirements
### Requirement: List all catalogs via public API with CORS headers (CAT-001)
The system MUST list all catalogs via public API with CORS headers.

**Priority:** Must **Status:** Implemented

#### Scenario: List all catalogs publicly
- GIVEN the catalog schema and register are configured in IAppConfig
- WHEN a GET request is made to `/api/catalogi`
- THEN all catalog objects MUST be returned with pagination metadata and CORS headers, with no RBAC/multitenancy filters applied

### Requirement: Retrieve a single catalog by ID, returning all publications scoped to that catalog (CAT-002)
The system MUST retrieve a single catalog by ID, returning all publications scoped to that catalog.

**Priority:** Must **Status:** Implemented

#### Scenario: View catalog detail with scoped publications
- GIVEN a catalog with a known ID exists
- WHEN a GET request is made to `/api/catalogi/{id}`
- THEN the response MUST include the catalog's publications scoped to its registers and schemas, paginated, with CORS headers

### Requirement: Catalogs are stored as OpenRegister objects using the `catalog` schema in the `publication` register (CAT-003)
Catalogs MUST be stored as OpenRegister objects using the `catalog` schema in the `publication` register.

**Priority:** Must **Status:** Implemented

#### Scenario: Catalog persisted as OpenRegister object
- GIVEN a catalog is created
- WHEN it is saved
- THEN it MUST be stored as an OpenRegister object using the `catalog` schema in the `publication` register

### Requirement: Catalog configuration (schema ID, register ID) is stored in IAppConfig as `catalog_schema` and `catalog_register` (CAT-004)
Catalog configuration (schema ID, register ID) MUST be stored in IAppConfig as `catalog_schema` and `catalog_register`.

**Priority:** Must **Status:** Implemented

#### Scenario: Resolve catalog schema and register from config
- GIVEN the app reads catalog configuration
- WHEN it resolves the catalog schema and register
- THEN it MUST read them from IAppConfig keys `catalog_schema` and `catalog_register`

### Requirement: Catalog lookups by slug are cached in a distributed cache (1 hour TTL) for performance (CAT-005)
Catalog lookups by slug SHALL be cached in a distributed cache (1 hour TTL) for performance. Lookups by slug SHOULD be served from cache when present.

**Priority:** Should **Status:** Implemented

#### Scenario: Cache a catalog lookup by slug
- GIVEN a catalog with slug "publications" exists
- WHEN `getCatalogBySlug("publications")` is called and the cache is empty
- THEN the result MUST be stored in the distributed cache under key `catalog_slug_publications` with a 3600-second TTL
- AND subsequent calls MUST return the cached result

### Requirement: Cache invalidation is supported by slug or by catalog ID (CAT-006)
Cache invalidation SHALL be supported by slug or by catalog ID. The service SHOULD expose invalidation by either key.

**Priority:** Should **Status:** Implemented

#### Scenario: Invalidate cache by slug
- GIVEN a cached catalog with slug "publications"
- WHEN `invalidateCatalogCache("publications")` is called
- THEN the cache entry MUST be removed and the next lookup MUST fetch fresh data from the database

### Requirement: Cache warmup is available to pre-load catalogs into cache (CAT-007)
Cache warmup SHALL be available to pre-load catalogs into cache. Warmup SHOULD force fresh data into the cache.

**Priority:** Nice **Status:** Implemented

#### Scenario: Warm up a catalog cache
- GIVEN a catalog with slug "new-catalog"
- WHEN `warmupCatalogCache("new-catalog")` is called
- THEN the cache MUST be invalidated and re-fetched so the catalog is immediately available in cache

### Requirement: CORS preflight OPTIONS responses must be supported on all catalog endpoints (CAT-008)
CORS preflight OPTIONS responses MUST be supported on all catalog endpoints.

**Priority:** Must **Status:** Implemented

#### Scenario: CORS preflight on a catalog endpoint
- GIVEN a cross-origin frontend
- WHEN it issues an OPTIONS request to `/api/catalogi` or `/api/catalogi/{id}`
- THEN the endpoint MUST respond with the CORS preflight headers

### Requirement: Public catalog endpoints must use `@PublicPage`, `@NoCSRFRequired`, `@NoAdminRequired` annotations (CAT-009)
Public catalog endpoints MUST use `@PublicPage`, `@NoCSRFRequired`, `@NoAdminRequired` annotations.

**Priority:** Must **Status:** Implemented

#### Scenario: Public catalog endpoint is anonymously reachable
- GIVEN an anonymous (unauthenticated) caller
- WHEN it requests a public catalog endpoint
- THEN the endpoint MUST be reachable, declaring `@PublicPage`, `@NoCSRFRequired`, and `@NoAdminRequired`

### Requirement: Multi-schema and multi-register catalogs must be supported (a single catalog can span multiple schemas/registers) (CAT-010)
Multi-schema and multi-register catalogs SHALL be supported so that a single catalog can span multiple schemas/registers. Such catalogs SHOULD be served correctly.

**Priority:** Should **Status:** Implemented

#### Scenario: Catalog spanning multiple schemas and registers
- GIVEN a catalog configured with multiple registers and schemas
- WHEN its publications are listed
- THEN the search MUST query across all the configured registers and schemas

### Requirement: Automatic cache invalidation/warmup via CatalogCacheEventListener on post-save events; slug-to-ID normalisation via CatalogSchemaEventListener on pre-save events (CAT-011)
Automatic cache invalidation/warmup MUST occur via CatalogCacheEventListener on object create/update/delete (post-save). Slug-to-ID normalisation of `registers`/`schemas` happens via CatalogSchemaEventListener on the **pre-save** events (`ObjectCreatingEvent`, `ObjectUpdatingEvent`) using `setModifiedData(...)`, never via a second `saveObject` call.

**Priority:** Should **Status:** Implemented

#### Scenario: Cache warmup on catalog creation
- GIVEN a new catalog object matching `catalog_schema`/`catalog_register` is created
- WHEN OpenRegister dispatches the post-save `ObjectCreatedEvent`
- THEN CatalogCacheEventListener MUST warm up the cache for the catalog's slug

#### Scenario: Slug-to-ID normalisation on pre-save
- GIVEN a catalog whose `registers`/`schemas` contain slug-or-uuid values is about to be saved
- WHEN the pre-save `ObjectCreatingEvent`/`ObjectUpdatingEvent` fires
- THEN CatalogSchemaEventListener MUST resolve them to integer IDs via `setModifiedData(...)` without issuing a second `saveObject`

### Requirement: No catalog event listener may trigger a re-save of the originating object from a post-save event handler (CAT-012)
No catalog event listener MUST trigger a re-save of the originating object from a post-save event handler. Listeners that need to mutate the entity MUST subscribe to the pre-save events and use `setModifiedData(...)`.

**Priority:** Must **Status:** Implemented

#### Scenario: Post-save listener does not re-save the entity
- GIVEN a catalog is saved or soft-deleted
- WHEN the post-save event handler runs
- THEN it MUST NOT call `saveObject(...)` on the originating object, so exactly one update event results and the request returns promptly without an event loop

### Requirement: Catalog store fetches a catalog's publications and registers object types (CAT-013)
The frontend catalog store SHALL, when a catalog is set active, fetch that catalog's
publications via the public slug endpoint `GET /index.php/apps/opencatalogi/api/{slug}`
(falling back to the catalog id, then the last-used catalog id), with `_extend` of
`@self.schema,@self.register` and pagination. For each returned publication it resolves
the publication's schema/register references against the response's `@self.schemas` /
`@self.registers` maps and registers the schema slug as an object type in the shared
object store (once per slug). On error the publications collection is reset to empty.

**Priority:** Must **Status:** Implemented

#### Scenario: Set active catalog and load its publications
@e2e exclude internal Pinia store method — catalogStore.setActiveCatalog() is a JavaScript store call with no directly browser-observable DOM surface; covered by Jest store unit test.
- GIVEN a catalog with a `slug`
- WHEN `catalogStore.setActiveCatalog(catalog)` is called
- THEN the store MUST fetch `GET /api/{slug}` with `_extend=@self.schema,@self.register`
- AND each publication's schema slug MUST be registered as an object type exactly once

#### Scenario: Fetch with no resolvable catalog id
@e2e exclude internal Pinia store error path — catalogStore.fetchPublications() error guard has no browser-observable UI rendering; covered by Jest store unit test.
- GIVEN no catalogId argument, no active catalog, and no last-used catalog id
- WHEN `catalogStore.fetchPublications()` is called
- THEN the store MUST log an error and return without issuing an HTTP request

### Requirement: Create and edit catalogs via the catalog modal (CAT-014)
The system SHALL provide a `CatalogModal` (shown when the navigation store modal is
`catalog`) for creating and editing a catalog. The modal validates the catalog against
the Catalogi entity, maps selected registers/schemas to their IDs and the selected
organization to its id, normalises the status to its id, and saves via
`objectStore.updateObject('catalog', id, item)` (edit) or
`objectStore.createObject('catalog', item)` (create), then closes after a short delay.

**Priority:** Must **Status:** Implemented

#### Scenario: Create a new catalog
- GIVEN the modal is open without an existing catalog id
- WHEN the user submits valid title, slug, and registers
- THEN the catalog item's id MUST be dropped and `objectStore.createObject('catalog', item)` called
- AND the modal MUST close after the success feedback delay

#### Scenario: Edit an existing catalog
- GIVEN the modal is open for a catalog with an id
- WHEN the user submits the form
- THEN `objectStore.updateObject('catalog', id, item)` MUST be called

### Requirement: View catalog details and detail page (CAT-015)
The system SHALL provide a `ViewCatalogi` modal and a `CatalogDetailPage` route view that
display a catalog read from the object store. The detail page resolves the catalog by the
route `id` param via `objectStore.fetchObject('catalog', id)`, supports navigating back to
the catalogs list and forward to the catalog's publications (by slug), and the view modal
presents catalog details across tabbed panels.

**Priority:** Should **Status:** Implemented

#### Scenario: Open a catalog detail page by route id
- GIVEN a route with an `id` param
- WHEN `CatalogDetailPage` mounts
- THEN it MUST call `objectStore.fetchObject('catalog', id)` and render the active catalog

#### Scenario: Navigate to a catalog's publications
- GIVEN a catalog with a `slug` on the detail page
- WHEN the user opens its publications
- THEN the router MUST push the `Publications` route with `catalogSlug` set to the slug

### Requirement: Catalogs dashboard widget (CAT-016)
The system SHALL provide a `CatalogiWidget` Nextcloud dashboard widget (registered as
`opencatalogi_catalogi_widget`) that on mount fetches the catalog collection via
`objectStore.fetchCollection('catalog')`, renders catalogs as widget items with a
theme-aware database icon, shows an empty state when there are none, and navigates to a
catalog's publications page when an item is clicked.

**Priority:** Should **Status:** Implemented

#### Scenario: Widget loads catalogs on mount
@e2e exclude NC dashboard widget — widget must be manually added to the Nextcloud dashboard by each user; not reliably present in a default test environment; covered by Jest component test instead.
- GIVEN the dashboard renders the catalogs widget
- WHEN the widget mounts
- THEN it MUST call `objectStore.fetchCollection('catalog')`
- AND render an empty-content state if no catalogs are returned

#### Scenario: Click a catalog widget item
@e2e exclude NC dashboard widget navigation — requires seeded catalogs in the widget plus the widget added to dashboard; not reliably automatable in the test environment; covered by Jest component test.
- GIVEN a catalog item shown in the widget
- WHEN the item is clicked
- THEN the browser MUST navigate to that catalog's publications URL

### Requirement: Authenticated callers receive full object metadata on public reads (CAT-AUTH-001)

**Priority:** Should **Status:** Implemented

Public read endpoints that assemble catalog/publication envelopes MUST make
the `@self` metadata stripping session-aware. For requests with no
authenticated Nextcloud session, the response MUST remain byte-identical to
the current anonymous envelope (the fixed strip list: `schemaVersion`,
`relations`, `locked`, `owner`, `folder`, `application`, `validation`,
`retention`, `size`, `deleted`). For requests carrying an authenticated
session, the full `@self` metadata MUST be passed through unmodified for every
object the caller may read. Which objects are returned MUST NOT differ between
the two audiences beyond what OpenRegister RBAC (`_rbac: true`) already
decides — this requirement changes envelope richness only, never visibility.

#### Scenario: anonymous envelope is unchanged

- GIVEN a published catalog readable anonymously,
- WHEN an unauthenticated caller lists it via the public API,
- THEN each object's `@self` MUST omit the stripped properties,
- AND the envelope MUST be byte-identical to the pre-change baseline.

> @e2e exclude Backend envelope contract; anonymous baseline byte-parity is asserted by PHPUnit against a golden fixture, no distinct UI surface.

#### Scenario: authenticated caller sees full metadata

- GIVEN the same catalog and an authenticated user whom OR RBAC allows to read it,
- WHEN that user lists it via the same public API route,
- THEN each object's `@self` MUST include `owner`, `locked`, `retention` and
  the other previously stripped properties as provided by OpenRegister.

> @e2e exclude Backend envelope contract; covered by PHPUnit with a mocked IUserSession; no rendering change ships in this change.

#### Scenario: session changes metadata richness, never the object set

- GIVEN an identical OR RBAC context for an anonymous and an authenticated request,
- WHEN both list the same catalog,
- THEN both responses MUST contain the same object ids in the same order,
- AND only the `@self` richness may differ.

> @e2e exclude Backend parity contract; PHPUnit.

## Data Model

The catalog schema is defined in `publication_register.json` and stored in OpenRegister.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| title | string | Yes | The title of the catalog |
| summary | string | No | Brief description of the catalog |
| description | string | No | Detailed description of the catalog |
| image | string | No | URL to the catalog's image |
| listed | boolean | No | Whether the catalog is publicly listed |
| organization | string | No | Reference to the owning organization |
| registers | array(int/string) | No | List of register IDs associated with this catalog |
| schemas | array(int/string) | No | List of schema IDs associated with this catalog |
| filters | object | No | Custom filters for the catalog |
| status | enum | No | Lifecycle status: development, beta, stable, obsolete |
| view | integer | No | Reference to a view/filter set ID in OpenRegister |
| slug | string | No | URL-friendly identifier (pattern: `^[a-z0-9-]+$`) |
| hasWooSitemap | boolean | No | Whether this catalog needs WOO-specific sitemaps |

## User Interface

The Nextcloud admin UI provides:
- **CatalogiIndex.vue** (`/catalogi`) - Lists all catalogs with management options
- **CatalogModal.vue** - Create/edit catalog modal dialog
- **ViewCatalogi.vue** - View catalog details modal
- **CatalogiWidget.vue** - Dashboard widget showing catalog overview

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/catalogi` | List all catalogs (public, paginated) |
| GET | `/api/catalogi/{id}` | Get catalog detail with scoped publications |
| OPTIONS | `/api/catalogi` | CORS preflight for catalog list |
| OPTIONS | `/api/catalogi/{id}` | CORS preflight for catalog detail |

## CatalogiService Caching Mechanism (Gap 23)

### Cache Architecture

The `CatalogiService` uses Nextcloud's `ICacheFactory` to create a distributed cache instance named `opencatalogi_catalogs`. This cache stores resolved catalog data to avoid repeated database queries on every publication request.

### Cache Configuration

| Setting | Value | Description |
|---------|-------|-------------|
| Cache name | `opencatalogi_catalogs` | Distributed cache instance via `ICacheFactory::createDistributed()` |
| TTL | 3600 seconds (1 hour) | Time-to-live for cached catalog entries |
| Key format | `catalog_slug_{slug}` | Cache key structure using the catalog's URL slug |

### Cache Operations

| Method | Purpose | Description |
|--------|---------|-------------|
| `getCatalogBySlug(string $slug)` | Read | Checks cache first (`cache->get()`), falls back to DB query, stores result in cache |
| `invalidateCatalogCache(string $slug)` | Invalidate by slug | Removes cache entry by slug key (`cache->remove()`) |
| `invalidateCatalogCacheById(int\|string $id)` | Invalidate by ID | Fetches catalog by ID to get slug, then invalidates by slug |
| `warmupCatalogCache(string $slug)` | Warmup by slug | Invalidates then re-fetches (forces fresh data into cache) |
| `warmupCatalogCacheById(int\|string $id)` | Warmup by ID | Fetches catalog by ID to get slug, then warms up by slug |

### Slug-to-ID Resolution Flow

```
getCatalogBySlug("publications")
  1. Check cache: cache->get("catalog_slug_publications")
  2. If cached: return cached array immediately
  3. If not cached:
     a. Get catalog_schema and catalog_register from IAppConfig
     b. Query ObjectService.searchObjects() with slug filter and register/schema
     c. If found: serialize to array, store in cache with 3600s TTL
     d. Return array (or null if not found)
```

### Automatic Cache Invalidation via Events

The `CatalogCacheEventListener` (`lib/Listener/CatalogCacheEventListener.php`) is registered in Application.php for three OpenRegister **post-save** events:

| Event | Action |
|-------|--------|
| `ObjectCreatedEvent` | If object is a catalog (schema/register match), warmup cache for its slug |
| `ObjectUpdatedEvent` | If object is a catalog, warmup cache for its slug (invalidate + re-fetch) |
| `ObjectDeletedEvent` | If object is a catalog, invalidate cache for its slug |

The listener checks if the affected object matches the `catalog_schema` and `catalog_register` from IAppConfig before performing any cache operations. Non-catalog objects are silently ignored. The cache listener is read-only with respect to the catalog object — it MUST NOT call `saveObject(...)` (or any persistence operation) on the entity that triggered the event, otherwise the resulting `ObjectUpdatedEvent` would re-enter the same listener (CAT-012).

### Catalog Object Normalisation via Pre-Save Events

The `CatalogSchemaEventListener` (`lib/Listener/CatalogSchemaEventListener.php`) is registered for the **pre-save** events `ObjectCreatingEvent` and `ObjectUpdatingEvent`. When a catalog object is about to be persisted, the listener resolves any slug-or-uuid values in the `registers` and `schemas` arrays into integer IDs and pushes the rewritten values back to the in-flight save via `$event->setModifiedData([...])`. OpenRegister's `MagicMapper` merges that payload into the single write that triggered the event, so no second save is needed.

This listener:
- MUST NOT call `saveObject(...)` or any persistence operation on the entity (CAT-012).
- MUST NOT call `stopPropagation()` — failure to resolve a slug is logged, and the original (un-rewritten) data flows through unchanged so the user's save is never blocked.

A previous implementation subscribed this listener to the post-save events and called `CatalogiService::rewriteSchemasAndRegisters()`, which internally invoked `saveObject(...)` and re-emitted `ObjectUpdatedEvent`. That caused an infinite event loop on every catalog update and soft-delete (soft-delete reaches the loop because `DeleteObject` performs the soft-delete via `MagicMapper::update()`, which dispatches `ObjectUpdatedEvent`). The deprecated wrapper `CatalogiService::rewriteSchemasAndRegisters(ObjectEntity)` is preserved for backwards compatibility but is no longer used by any in-tree caller; new code MUST use `CatalogiService::computeRewrittenRegistersAndSchemas(array)` from a pre-save listener.

## Scenarios

### Scenario: List all catalogs
- GIVEN the catalog schema and register are configured in IAppConfig
- WHEN a GET request is made to `/api/catalogi`
- THEN all catalog objects are returned with pagination metadata
- AND CORS headers are included in the response (Access-Control-Allow-Origin, etc.)
- AND no RBAC or multitenancy filters are applied (public access)

### Scenario: View catalog detail with publications
- GIVEN a catalog with ID "abc-123" exists
- WHEN a GET request is made to `/api/catalogi/abc-123`
- THEN the CatalogiService.index() method fetches all publications scoped to that catalog's registers and schemas
- AND unwanted `@self` metadata (schemaVersion, relations, locked, owner, etc.) is stripped from results
- AND paginated results are returned with CORS headers

### Scenario: Catalog slug caching
- GIVEN a catalog with slug "publications" exists
- WHEN getCatalogBySlug("publications") is called
- THEN the cache is checked first (key: `catalog_slug_publications`)
- AND if not cached, the database is queried with register/schema filters
- AND the result is stored in cache with 3600-second TTL
- AND subsequent calls return the cached result

### Scenario: Cache invalidation on catalog update
- GIVEN a cached catalog with slug "publications"
- WHEN invalidateCatalogCache("publications") is called
- THEN the cache entry is removed
- AND the next getCatalogBySlug call fetches fresh data from the database

### Scenario: Automatic cache warmup on catalog creation
- GIVEN a new catalog object is created with slug "new-catalog"
- WHEN the ObjectCreatedEvent is dispatched by OpenRegister
- THEN CatalogCacheEventListener checks if the object matches catalog_schema/catalog_register
- AND if it matches, warmupCatalogCache("new-catalog") is called
- AND the new catalog is immediately available in cache

### Scenario: Automatic cache invalidation on catalog deletion
- GIVEN a catalog with slug "old-catalog" exists in cache
- WHEN the catalog object is deleted and ObjectDeletedEvent is dispatched
- THEN CatalogCacheEventListener invalidates the cache for "old-catalog"
- AND subsequent requests return null until a new catalog with that slug is created

### Scenario: Catalog update with slug-valued registers persists in a single save
- GIVEN a catalog object whose JSON contains `"registers": ["my-register"]` (slug, not numeric ID)
- WHEN the catalog is saved via `ObjectService::saveObject(...)`
- THEN CatalogSchemaEventListener handles the pre-save `ObjectUpdatingEvent` and calls `$event->setModifiedData(['registers' => [<integer-id>]])`
- AND MagicMapper merges the modified data into the in-flight save
- AND exactly **one** `ObjectUpdatedEvent` is dispatched as a result of the save
- AND the request returns within the standard PHP request budget (no hang)

### Scenario: Catalog soft-delete returns promptly
- GIVEN any catalog object
- WHEN it is soft-deleted via `ObjectService::deleteObject(...)`
- THEN the request returns within the standard PHP request budget
- AND no listener issues an additional `update` or `saveObject` on the same entity during deletion handling
- AND the slug cache for the catalog is invalidated by the post-save `CatalogCacheEventListener`

### Scenario: Pre-save normalisation failure does not block the save
- GIVEN a catalog object with a `registers` entry that does not resolve to an existing register
- WHEN the catalog is saved
- THEN the pre-save `CatalogSchemaEventListener` logs the resolution failure
- AND the listener does NOT call `stopPropagation()` on the event
- AND the save proceeds with the original (un-rewritten) data
- AND the user receives a successful response

## Dependencies

- **OpenRegister** - ObjectService for data persistence and searchObjectsPaginated for queries
- **Nextcloud IAppConfig** - Stores catalog_schema and catalog_register configuration keys
- **Nextcloud ICacheFactory** - Distributed cache for catalog slug lookups (1-hour TTL)
- **CatalogiService** - Business logic layer for catalog operations and caching
- **CatalogCacheEventListener** - Automatic cache management on OpenRegister post-save events (read-only with respect to the catalog object)
- **CatalogSchemaEventListener** - Pre-save normalisation of `registers`/`schemas` slug-or-uuid values into integer IDs via `setModifiedData(...)`
- **OpenRegister Events** - `ObjectCreatedEvent` / `ObjectUpdatedEvent` / `ObjectDeletedEvent` for cache triggers, `ObjectCreatingEvent` / `ObjectUpdatingEvent` for normalisation
