# Catalogs

## Purpose

Catalogs are the top-level organizational unit in OpenCatalogi. A catalog groups publications by associating them with specific OpenRegister registers and schemas, providing a URL-slug-based namespace for the public API. Catalogs enable multi-tenant content organization where different collections of publications can be served through distinct API endpoints.

## Requirements

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| CAT-001 | List all catalogs via public API with CORS headers | Must | Implemented |
| CAT-002 | Retrieve a single catalog by ID, returning all publications scoped to that catalog | Must | Implemented |
| CAT-003 | Catalogs are stored as OpenRegister objects using the `catalog` schema in the `publication` register | Must | Implemented |
| CAT-004 | Catalog configuration (schema ID, register ID) is stored in IAppConfig as `catalog_schema` and `catalog_register` | Must | Implemented |
| CAT-005 | Catalog lookups by slug are cached in a distributed cache (1 hour TTL) for performance | Should | Implemented |
| CAT-006 | Cache invalidation is supported by slug or by catalog ID | Should | Implemented |
| CAT-007 | Cache warmup is available to pre-load catalogs into cache | Nice | Implemented |
| CAT-008 | CORS preflight OPTIONS responses must be supported on all catalog endpoints | Must | Implemented |
| CAT-009 | Public catalog endpoints must use `@PublicPage`, `@NoCSRFRequired`, `@NoAdminRequired` annotations | Must | Implemented |
| CAT-010 | Multi-schema and multi-register catalogs must be supported (a single catalog can span multiple schemas/registers) | Should | Implemented |
| CAT-011 | Automatic cache invalidation/warmup via CatalogCacheEventListener on object create/update/delete | Should | Implemented |

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

The `CatalogCacheEventListener` (`lib/Listener/CatalogCacheEventListener.php`) is registered in Application.php for three OpenRegister events:

| Event | Action |
|-------|--------|
| `ObjectCreatedEvent` | If object is a catalog (schema/register match), warmup cache for its slug |
| `ObjectUpdatedEvent` | If object is a catalog, warmup cache for its slug (invalidate + re-fetch) |
| `ObjectDeletedEvent` | If object is a catalog, invalidate cache for its slug |

The listener checks if the affected object matches the `catalog_schema` and `catalog_register` from IAppConfig before performing any cache operations. Non-catalog objects are silently ignored.

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

## Dependencies

- **OpenRegister** - ObjectService for data persistence and searchObjectsPaginated for queries
- **Nextcloud IAppConfig** - Stores catalog_schema and catalog_register configuration keys
- **Nextcloud ICacheFactory** - Distributed cache for catalog slug lookups (1-hour TTL)
- **CatalogiService** - Business logic layer for catalog operations and caching
- **CatalogCacheEventListener** - Automatic cache management on OpenRegister object events
- **OpenRegister Events** - ObjectCreatedEvent, ObjectUpdatedEvent, ObjectDeletedEvent for cache triggers
