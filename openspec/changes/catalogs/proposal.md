# Proposal: Catalogs

## Summary

Specify and document the Catalogs feature — the top-level organizational unit in OpenCatalogi. A catalog groups publications by associating them with specific OpenRegister registers and schemas, providing a URL-slug-based namespace for the public API. This change covers the public REST API, distributed caching via Nextcloud's `ICacheFactory`, the admin UI, and the automatic cache lifecycle triggered by OpenRegister object events.

## Motivation

OpenCatalogi serves government publications to citizens and integrations through a public catalog API. Without a well-specified catalog layer, the following risks exist:

- Public endpoints may be exposed without CORS or proper access controls, breaking browser-based consumers.
- High-traffic catalog slug lookups hit the database on every request, causing latency spikes under load.
- Cache invalidation bugs (stale or missing entries) silently serve outdated catalog data.
- Multi-schema and multi-register catalogs may be incorrectly scoped, leaking publications across catalog boundaries.
- Catalog configuration (which schema/register to use) may be stored inconsistently, making the system fragile across Nextcloud upgrades.

This change formalizes the requirements, data model, caching behavior, and event-driven cache lifecycle so the implementation can be verified, extended, and audited safely.

## Scope

### In scope

- Public REST API for listing all catalogs (`GET /api/catalogi`) and retrieving a single catalog with its scoped publications (`GET /api/catalogi/{id}`).
- CORS preflight responses (`OPTIONS`) on all public catalog endpoints.
- Catalog data model: `title`, `summary`, `description`, `image`, `listed`, `organization`, `registers`, `schemas`, `filters`, `status`, `view`, `slug`, `hasWooSitemap`.
- Storage of catalog objects in OpenRegister using the `catalog` schema inside the `publication` register; app configuration via `IAppConfig` keys `catalog_schema` and `catalog_register`.
- Distributed slug cache (`opencatalogi_catalogs`) with 1-hour TTL, supporting lookup, invalidation (by slug and by catalog ID), and warmup.
- Automatic cache lifecycle management via `CatalogCacheEventListener` on `ObjectCreatedEvent`, `ObjectUpdatedEvent`, and `ObjectDeletedEvent`.
- Admin UI components: `CatalogiIndex.vue` (list view), `CatalogModal.vue` (create/edit), `ViewCatalogi.vue` (detail view), `CatalogiWidget.vue` (dashboard widget).
- Multi-schema and multi-register catalog support (a single catalog can span multiple schemas/registers).

### Out of scope

- Publication storage and retrieval (covered by the `publications` spec).
- Listing and directory management (covered by the `deelnames-gebruik` spec).
- WOO-specific catalog types (covered by the `woo-transparency` spec).
- Federation and sitemap generation (covered by their respective dedicated specs).
- Catalog slug-to-ID normalisation on save (covered by the `fix-catalog-update-infinite-loop` change, which adds `CatalogSchemaEventListener` on the pre-save events).

## Risks

- **CORS misconfiguration** — Omitting CORS headers on any public endpoint breaks browser-based catalog consumers (tilburg-woo-ui and downstream integrations). Every public endpoint requires both a main handler and an `OPTIONS` preflight route.
- **Cache TTL and invalidation gap** — A 1-hour TTL means deleted or updated catalogs may be served stale. The automatic `CatalogCacheEventListener` mitigates this; manual cache warmup is available as a fallback.
- **IAppConfig misconfiguration** — If `catalog_schema` or `catalog_register` are not set, catalog lookups silently return empty results. Operators must configure these keys after install.
- **Multi-schema publication leakage** — A catalog whose `registers`/`schemas` arrays are incorrect will return publications from unintended data sources. Input validation and register/schema resolution at save time are essential guards.
