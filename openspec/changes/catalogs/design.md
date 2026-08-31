# Design: Catalogs

## Architecture Overview

Catalogs are stored as OpenRegister objects using the `catalog` schema within the `publication` register. The schema definition lives in `lib/Settings/publication_register.json`. Which schema and register to use is configured at the app level via Nextcloud's `IAppConfig` under the keys `catalog_schema` and `catalog_register`, making it operator-tunable without code changes.

The public API surface is thin:

```
lib/Controller/CatalogiController.php   ← routing + response, <10 lines per method
lib/Service/CatalogiService.php         ← all business logic and cache management
```

The controller delegates all logic to the service. The service owns the distributed cache, slug resolution, and publication scoping. No custom Entity or Mapper exists for catalogs — all persistence goes through OpenRegister's `ObjectService`.

Cache management is event-driven. `lib/Listener/CatalogCacheEventListener.php` subscribes to OpenRegister's post-save events and invalidates or warms the slug cache whenever a catalog object changes.

## Data Flow

### List endpoint

```
GET /api/catalogi
    │
    ▼
CatalogiController::index()
    │
    ▼
ObjectService::searchObjectsPaginated(register, schema, params)
    │
    ▼
JSONResponse with pagination metadata + CORS headers
```

### Detail endpoint with publication scoping

```
GET /api/catalogi/{id}
    │
    ▼
CatalogiController::show($id)
    │
    ▼
CatalogiService::index($catalog)
    ├── Fetch all publications scoped to catalog's registers[] + schemas[]
    ├── Strip @self metadata fields (schemaVersion, relations, locked, owner, …)
    └── Return paginated JSONResponse with CORS headers
```

### Slug-based cache lookup

```
getCatalogBySlug("westerveld-woo")
    1. cache->get("catalog_slug_westerveld-woo")
    2. Cache HIT  → return cached array immediately (no DB)
    3. Cache MISS →
       a. IAppConfig → catalog_schema, catalog_register
       b. ObjectService.searchObjects([slug => "westerveld-woo"], register, schema)
       c. cache->set("catalog_slug_westerveld-woo", $result, 3600)
       d. Return array (or null if not found)
```

### Cache lifecycle (event-driven)

```
OpenRegister emits ObjectCreatedEvent / ObjectUpdatedEvent / ObjectDeletedEvent
    │
    ▼
CatalogCacheEventListener::handle()
    ├── Check object schema === catalog_schema && register === catalog_register
    ├── If no match → return early (other object types are silently ignored)
    ├── ObjectCreatedEvent  → warmupCatalogCache(slug)   (invalidate + re-fetch)
    ├── ObjectUpdatedEvent  → warmupCatalogCache(slug)   (invalidate + re-fetch)
    └── ObjectDeletedEvent  → invalidateCatalogCache(slug)
```

## Reuse Analysis

| Platform capability | Service / class | How OpenCatalogi uses it |
|---|---|---|
| Object storage & retrieval | `ObjectService` (OpenRegister) | All catalog CRUD; `searchObjects()` for slug lookup; `searchObjectsPaginated()` for listing and publication scoping |
| App configuration | `IAppConfig` (Nextcloud) | Stores `catalog_schema` and `catalog_register` at app scope; read on every slug lookup |
| Distributed cache | `ICacheFactory::createDistributed()` (Nextcloud) | Creates the named cache `opencatalogi_catalogs`; backend is Redis/Memcached/APCu depending on Nextcloud deployment |
| Event subscriptions | OpenRegister `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent` | Triggers cache warmup/invalidation automatically after object lifecycle changes |
| CORS / public page | Nextcloud `#[PublicPage]`, `#[NoCSRFRequired]`, `#[NoAdminRequired]` | Applied to all catalog endpoints; OPTIONS preflight routes registered in `appinfo/routes.php` |

No custom query builder, no custom caching infrastructure, no custom Entity/Mapper, no foreign keys.

## Deduplication Check

- **Object persistence**: `ObjectService::saveObject()` / `searchObjectsPaginated()` from OpenRegister covers all data access. No duplication.
- **Cache**: Nextcloud's `ICacheFactory` provides distributed caching. No custom cache layer introduced.
- **CRUD UI**: `CnIndexPage` + `CnFormDialog` from `@conduction/nextcloud-vue` drive the admin list and create/edit forms. No bespoke dialog or table component built for catalogs.
- **Existing implementations**: `CatalogiController`, `CatalogiService`, and `CatalogCacheEventListener` already implement the full feature set described in this spec. This change documents and formalizes existing behavior; it introduces no duplicate implementations.

## Seed Data

The following 5 catalog objects MUST be present in `lib/Settings/publication_register.json` under `components.objects[]`. All use the `@self` envelope anchoring them to the `catalog` schema in the `publication` register. Values are fictional but realistic Dutch government catalogs suitable for dev/test environments.

### Seed object 1 — WOO publicaties gemeente Westerveld

```json
{
  "@self": {
    "register": "publication",
    "schema": "catalog",
    "slug": "gemeente-westerveld-woo"
  },
  "title": "Gemeente Westerveld – WOO publicaties",
  "summary": "Openbare publicaties van gemeente Westerveld in het kader van de Wet open overheid.",
  "description": "Dit catalogus bevat alle documenten die gemeente Westerveld actief openbaar maakt op grond van de WOO, inclusief besluiten, convenanten en onderzoeksrapporten.",
  "listed": true,
  "status": "stable",
  "slug": "gemeente-westerveld-woo",
  "hasWooSitemap": true,
  "organization": "gemeente-westerveld"
}
```

### Seed object 2 — RVO subsidies en regelingen

```json
{
  "@self": {
    "register": "publication",
    "schema": "catalog",
    "slug": "rvo-subsidies"
  },
  "title": "RVO – Subsidies en regelingen",
  "summary": "Overzicht van subsidies en regelingen beschikbaar via de Rijksdienst voor Ondernemend Nederland.",
  "description": "Catalogus met alle actieve en afgesloten subsidieregelingen van RVO, bedoeld voor ondernemers, agrariërs en onderzoekers.",
  "listed": true,
  "status": "stable",
  "slug": "rvo-subsidies",
  "hasWooSitemap": false,
  "organization": "rvo"
}
```

### Seed object 3 — Provincie Drenthe omgevingsbeleid

```json
{
  "@self": {
    "register": "publication",
    "schema": "catalog",
    "slug": "provincie-drenthe-omgevingsbeleid"
  },
  "title": "Provincie Drenthe – Omgevingsbeleid",
  "summary": "Beleidsdocumenten en regelgeving rondom ruimtelijke ordening en omgevingsrecht in de provincie Drenthe.",
  "description": "Dit catalogus groepeert alle publicaties van de provincie Drenthe die betrekking hebben op het omgevingsbeleid, inclusief omgevingsvisies, verordeningen en vergunningbesluiten.",
  "listed": true,
  "status": "beta",
  "slug": "provincie-drenthe-omgevingsbeleid",
  "hasWooSitemap": false,
  "organization": "provincie-drenthe"
}
```

### Seed object 4 — Waterschap Hunze en Aa's waterbeheer

```json
{
  "@self": {
    "register": "publication",
    "schema": "catalog",
    "slug": "waterschap-hunze-aas-waterbeheer"
  },
  "title": "Waterschap Hunze en Aa's – Waterbeheer",
  "summary": "Publicaties van Waterschap Hunze en Aa's over dijkbeheer, peilbesluiten en watersysteembeheer.",
  "description": "Alle openbare documenten van Waterschap Hunze en Aa's op het gebied van waterbeheer, keringen en peilbeheer in Noord-Nederland.",
  "listed": true,
  "status": "stable",
  "slug": "waterschap-hunze-aas-waterbeheer",
  "hasWooSitemap": true,
  "organization": "waterschap-hunze-aas"
}
```

### Seed object 5 — Conduction testcatalogus (development)

```json
{
  "@self": {
    "register": "publication",
    "schema": "catalog",
    "slug": "conduction-dev"
  },
  "title": "Conduction – Testcatalogus",
  "summary": "Ontwikkelcatalogus voor interne tests en demonstraties. Niet voor productiegebruik.",
  "description": "Fictieve catalogus voor gebruik in lokale ontwikkelomgevingen en geautomatiseerde tests. Bevat testpublicaties met variabele inhoud.",
  "listed": false,
  "status": "development",
  "slug": "conduction-dev",
  "hasWooSitemap": false,
  "organization": "conduction"
}
```

## Open Questions

- **Q1.** Should the `organization` field on a catalog be a plain string reference or an OpenRegister relation to an Organization object? Currently it is stored as a string identifier. If it becomes a relation, slug-to-ID resolution on save must be extended to cover this field as well.
- **Q2.** What is the expected behavior when `catalog_schema` or `catalog_register` are not configured in `IAppConfig`? Currently the slug lookup returns `null` silently. Should this surface an operator-visible warning or a 503 response?
- **Q3.** The `filters` field is typed as an opaque `object`. Should its structure be documented (key names, value types) to enable frontend filter-bar generation, or should it remain deliberately open-ended?
