---
status: reviewed
---

# Catalogs

## Purpose

Catalogs are the top-level organizational unit in OpenCatalogi. A catalog groups publications by associating them with specific OpenRegister registers and schemas, providing a URL-slug-based namespace for the public API. Catalogs enable multi-tenant content organization where different collections of publications can be served through distinct API endpoints.

## Data Model

The catalog schema is defined in `lib/Settings/publication_register.json` and stored in OpenRegister.

| Field | Type | Required | Description |
|---|---|---|---|
| title | string | Yes | The title of the catalog |
| summary | string | No | Brief description of the catalog |
| description | string | No | Detailed description of the catalog |
| image | string | No | URL to the catalog's image |
| listed | boolean | No | Whether the catalog is publicly listed |
| organization | string | No | Reference to the owning organization |
| registers | array(int/string) | No | List of register IDs associated with this catalog |
| schemas | array(int/string) | No | List of schema IDs associated with this catalog |
| filters | object | No | Custom filters for the catalog |
| status | enum | No | Lifecycle status: `development`, `beta`, `stable`, `obsolete` |
| view | integer | No | Reference to a view/filter set ID in OpenRegister |
| slug | string | No | URL-friendly identifier (pattern: `^[a-z0-9-]+$`) |
| hasWooSitemap | boolean | No | Whether this catalog needs WOO-specific sitemaps |

## Requirements

### Requirement: CAT-001 — Public catalog list endpoint

The system MUST expose a public, paginated endpoint for listing all catalogs.

#### Scenario: List all catalogs
- GIVEN the `catalog_schema` and `catalog_register` keys are configured in `IAppConfig`
- WHEN a `GET` request is made to `/api/catalogi`
- THEN all catalog objects are returned as a paginated JSON response
- AND the response includes pagination metadata (`total`, `page`, `pages`, `limit`)
- AND CORS headers (`Access-Control-Allow-Origin`, etc.) are present in the response
- AND no RBAC or multitenancy filters are applied (fully public access)

#### Scenario: List endpoint returns empty array when no catalogs exist
- GIVEN no catalog objects are stored in the configured register/schema
- WHEN a `GET` request is made to `/api/catalogi`
- THEN the response body contains an empty `results` array
- AND `total` is `0`
- AND the HTTP status is `200 OK`

---

### Requirement: CAT-002 — Public catalog detail endpoint with scoped publications

The system MUST expose a public endpoint that returns a single catalog together with its scoped publications.

#### Scenario: Retrieve catalog detail with publications
- GIVEN a catalog with ID `abc-123` exists and has `registers: [1]` and `schemas: [2]`
- WHEN a `GET` request is made to `/api/catalogi/abc-123`
- THEN `CatalogiService::index()` fetches all publications belonging to register `1` and schema `2`
- AND `@self` metadata fields (`schemaVersion`, `relations`, `locked`, `owner`, etc.) are stripped from every publication in the result
- AND paginated results are returned with CORS headers
- AND the HTTP status is `200 OK`

#### Scenario: Catalog not found returns 404
- GIVEN no catalog with ID `does-not-exist` exists
- WHEN a `GET` request is made to `/api/catalogi/does-not-exist`
- THEN the response HTTP status is `404 Not Found`
- AND the response body contains a `message` field with a static error string
- AND no stack trace, SQL, or internal path is included in the response

---

### Requirement: CAT-003 — Catalogs stored as OpenRegister objects

Catalog data MUST be persisted as OpenRegister objects. No custom Entity or Mapper class may be created for catalog data.

#### Scenario: Catalog object stored in configured register/schema
- GIVEN `catalog_schema` is set to `"catalog"` and `catalog_register` is set to `"publication"` in `IAppConfig`
- WHEN a catalog is created via the admin UI or API
- THEN the catalog object is stored by `ObjectService::saveObject(register, schema, data)` with 3 positional args
- AND no custom DB table, entity class, or mapper is used for storage

---

### Requirement: CAT-004 — Catalog configuration stored in IAppConfig

The schema ID and register ID used for catalog lookups MUST be stored in Nextcloud's `IAppConfig` under the keys `catalog_schema` and `catalog_register`. These values MUST NOT be hardcoded in PHP.

#### Scenario: Configuration keys read at runtime
- GIVEN `catalog_schema` is `"catalog"` and `catalog_register` is `"publication"` in `IAppConfig`
- WHEN any catalog list or detail request is processed
- THEN `CatalogiService` reads these values from `IAppConfig` at runtime
- AND changing the values in `IAppConfig` takes effect on the next request without a code deploy

#### Scenario: Missing configuration returns empty results
- GIVEN `catalog_schema` or `catalog_register` is not set in `IAppConfig`
- WHEN a catalog slug lookup is performed
- THEN the lookup returns `null` without throwing an unhandled exception
- AND no stack trace is returned to the caller

---

### Requirement: CAT-005 — Catalog slug lookups are cached (1-hour TTL)

`CatalogiService::getCatalogBySlug()` MUST check a distributed cache before querying the database. Cache entries expire after 3600 seconds.

#### Scenario: Cache hit avoids database query
- GIVEN a catalog with slug `"westerveld-woo"` has been cached under key `catalog_slug_westerveld-woo`
- WHEN `getCatalogBySlug("westerveld-woo")` is called
- THEN the cache entry is returned immediately
- AND no database query is issued

#### Scenario: Cache miss triggers database query and caches result
- GIVEN the cache does not contain `catalog_slug_westerveld-woo`
- WHEN `getCatalogBySlug("westerveld-woo")` is called
- THEN `ObjectService::searchObjects()` is called with the slug filter and configured register/schema
- AND the result is stored in the cache with TTL `3600`
- AND subsequent calls for the same slug return the cached value

#### Scenario: Slug not found is not cached
- GIVEN no catalog with slug `"nonexistent"` exists in the database
- WHEN `getCatalogBySlug("nonexistent")` is called
- THEN `null` is returned
- AND no cache entry is written for the missing slug

---

### Requirement: CAT-006 — Cache invalidation by slug and by ID

The system MUST support invalidating the slug cache both by slug string and by catalog object ID.

#### Scenario: Invalidate cache by slug
- GIVEN a cached catalog under key `catalog_slug_westerveld-woo`
- WHEN `invalidateCatalogCache("westerveld-woo")` is called
- THEN the cache entry for `catalog_slug_westerveld-woo` is removed
- AND the next `getCatalogBySlug("westerveld-woo")` call goes to the database

#### Scenario: Invalidate cache by ID
- GIVEN a catalog with ID `42` and slug `"westerveld-woo"` is cached
- WHEN `invalidateCatalogCacheById(42)` is called
- THEN `ObjectService` is queried to resolve the slug for ID `42`
- AND `invalidateCatalogCache("westerveld-woo")` is called with the resolved slug
- AND the cache entry is removed

---

### Requirement: CAT-007 — Cache warmup pre-loads catalogs into cache

The system MUST support warming up the slug cache for a given catalog, forcing a fresh database fetch and storing the result.

#### Scenario: Warmup by slug
- GIVEN a catalog with slug `"westerveld-woo"` exists
- WHEN `warmupCatalogCache("westerveld-woo")` is called
- THEN the existing cache entry (if any) is first invalidated
- AND a fresh database query is issued
- AND the fresh result is stored in the cache with TTL `3600`

#### Scenario: Warmup by ID
- GIVEN a catalog with ID `42` and slug `"westerveld-woo"` exists
- WHEN `warmupCatalogCacheById(42)` is called
- THEN the catalog ID is resolved to its slug via `ObjectService`
- AND `warmupCatalogCache("westerveld-woo")` is executed with the resolved slug

---

### Requirement: CAT-008 — CORS preflight support on all catalog endpoints

All public catalog endpoints MUST respond to HTTP `OPTIONS` preflight requests with the appropriate CORS headers.

#### Scenario: OPTIONS preflight for catalog list
- GIVEN a browser sends a CORS preflight request
- WHEN an `OPTIONS` request is made to `/api/catalogi`
- THEN the response status is `200 OK`
- AND the response includes `Access-Control-Allow-Origin`, `Access-Control-Allow-Methods`, and `Access-Control-Allow-Headers` headers

#### Scenario: OPTIONS preflight for catalog detail
- GIVEN a browser sends a CORS preflight request
- WHEN an `OPTIONS` request is made to `/api/catalogi/{id}`
- THEN the response status is `200 OK`
- AND the response includes appropriate CORS headers

---

### Requirement: CAT-009 — Public endpoint access control annotations

All public catalog controller methods MUST be annotated with `#[PublicPage]`, `#[NoCSRFRequired]`, and `#[NoAdminRequired]`. No authentication is required to access catalog list or detail endpoints.

#### Scenario: Unauthenticated access to catalog list
- GIVEN no Nextcloud session exists
- WHEN a `GET` request is made to `/api/catalogi`
- THEN the response is `200 OK` with catalog data
- AND no redirect to a login page occurs

#### Scenario: Unauthenticated access to catalog detail
- GIVEN no Nextcloud session exists
- WHEN a `GET` request is made to `/api/catalogi/{id}`
- THEN the response is `200 OK` with catalog and publication data (or `404` if not found)
- AND no redirect to a login page occurs

---

### Requirement: CAT-010 — Multi-schema and multi-register catalog support

A single catalog MUST be able to reference multiple registers and multiple schemas simultaneously. The publication scoping logic MUST union results across all configured registers and schemas.

#### Scenario: Catalog spanning two registers
- GIVEN a catalog with `registers: [1, 2]` and `schemas: [3]`
- WHEN a `GET` request is made to `/api/catalogi/{id}`
- THEN publications from both register `1` and register `2` that match schema `3` are included in the result
- AND publications from other registers are excluded

#### Scenario: Catalog with no registers returns all publications for its schemas
- GIVEN a catalog with `registers: []` and `schemas: [3]`
- WHEN a `GET` request is made to `/api/catalogi/{id}`
- THEN the behavior is defined by `CatalogiService` (either return all matching schema `3` objects or return an empty set) — the implementation MUST document which choice is made and apply it consistently

---

### Requirement: CAT-011 — Automatic cache invalidation and warmup on object lifecycle events

The `CatalogCacheEventListener` MUST subscribe to the OpenRegister post-save events `ObjectCreatedEvent`, `ObjectUpdatedEvent`, and `ObjectDeletedEvent`. When any of these events fires for an object that matches the configured `catalog_schema` and `catalog_register`, the listener MUST perform the appropriate cache action.

| Event | Cache action |
|---|---|
| `ObjectCreatedEvent` | `warmupCatalogCache(slug)` — pre-load the new catalog into cache |
| `ObjectUpdatedEvent` | `warmupCatalogCache(slug)` — invalidate stale entry and re-fetch |
| `ObjectDeletedEvent` | `invalidateCatalogCache(slug)` — remove the deleted catalog from cache |

The listener MUST silently ignore events for objects that do not match the configured catalog schema/register pair.

#### Scenario: Automatic cache warmup on catalog creation
- GIVEN a new catalog object is created with slug `"new-catalog"`
- WHEN `ObjectCreatedEvent` is dispatched by OpenRegister
- THEN `CatalogCacheEventListener` checks whether the object matches `catalog_schema` and `catalog_register`
- AND if it matches, `warmupCatalogCache("new-catalog")` is called
- AND the new catalog is immediately available in cache for subsequent slug lookups

#### Scenario: Automatic cache warmup on catalog update
- GIVEN a cached catalog with slug `"westerveld-woo"` is updated
- WHEN `ObjectUpdatedEvent` is dispatched by OpenRegister
- THEN `CatalogCacheEventListener` identifies the object as a catalog
- AND `warmupCatalogCache("westerveld-woo")` is called (invalidate + re-fetch)
- AND subsequent slug lookups return the updated data

#### Scenario: Automatic cache invalidation on catalog deletion
- GIVEN a catalog with slug `"old-catalog"` exists in cache
- WHEN the catalog object is deleted and `ObjectDeletedEvent` is dispatched
- THEN `CatalogCacheEventListener` invalidates the cache for `"old-catalog"`
- AND subsequent `getCatalogBySlug("old-catalog")` calls return `null` until a new catalog with that slug is created

#### Scenario: Non-catalog object events are silently ignored
- GIVEN an object with a different schema (not `catalog_schema`) is updated
- WHEN `ObjectUpdatedEvent` is dispatched
- THEN `CatalogCacheEventListener` performs no cache operation
- AND no error or log entry is produced for the non-catalog event

## API Endpoints

| Method | Path | Auth | Description |
|---|---|---|---|
| `GET` | `/api/catalogi` | Public | List all catalogs (paginated) |
| `GET` | `/api/catalogi/{id}` | Public | Catalog detail + scoped publications (paginated) |
| `OPTIONS` | `/api/catalogi` | Public | CORS preflight for catalog list |
| `OPTIONS` | `/api/catalogi/{id}` | Public | CORS preflight for catalog detail |

## Admin UI Components

| Component | Route | Purpose |
|---|---|---|
| `CatalogiIndex.vue` | `/catalogi` | Lists all catalogs with create, edit, delete actions |
| `CatalogModal.vue` | (modal) | Create/edit catalog dialog |
| `ViewCatalogi.vue` | (modal) | View catalog details |
| `CatalogiWidget.vue` | (dashboard) | Dashboard widget showing catalog overview |

## Cache Configuration Reference

| Setting | Value | Description |
|---|---|---|
| Cache name | `opencatalogi_catalogs` | Created via `ICacheFactory::createDistributed()` |
| TTL | `3600` seconds (1 hour) | Time-to-live for each slug entry |
| Key format | `catalog_slug_{slug}` | Per-catalog cache key |

## Dependencies

- **OpenRegister** — `ObjectService` for all data persistence and retrieval; `searchObjects()` for slug lookups; `searchObjectsPaginated()` for list and publication queries.
- **Nextcloud `IAppConfig`** — Stores `catalog_schema` and `catalog_register` at app scope.
- **Nextcloud `ICacheFactory`** — Provides the distributed cache backend for slug lookups.
- **OpenRegister events** — `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent` for automatic cache lifecycle management.
- **`CatalogiService`** — Business logic layer owning catalog operations and all cache methods.
- **`CatalogCacheEventListener`** — Subscribes to OpenRegister post-save events to drive automatic cache warmup and invalidation.
