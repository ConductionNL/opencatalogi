# Tasks: Catalogs

## Deduplication Check

- [x] Verify no custom Entity/Mapper exists for catalog data — all persistence MUST use `ObjectService` (3-arg signatures).
- [x] Verify no custom query builder or cache implementation has been introduced — `ObjectService` and `ICacheFactory` cover all needs.
- [x] Verify `CnIndexPage` + `CnFormDialog` from `@conduction/nextcloud-vue` are used for the admin list and create/edit UI; no bespoke table or dialog component built for catalogs.

## Task 1: Schema definition in publication_register.json

- **Spec ref**: specs/catalogs/spec.md (CAT-003)
- **Status**: done
- **Files**: `lib/Settings/publication_register.json`
- **Acceptance criteria**:
  - [ ] The `catalog` schema is declared under `components.schemas` in `publication_register.json`.
  - [ ] Schema fields match the data model table in `specs/catalogs/spec.md` exactly: `title` (required), `summary`, `description`, `image`, `listed`, `organization`, `registers`, `schemas`, `filters`, `status`, `view`, `slug` (pattern `^[a-z0-9-]+$`), `hasWooSitemap`.
  - [ ] The `catalog` schema is associated with the `publication` register in the register definition.
  - [ ] Schema is idempotent on re-import (`force: false` MUST NOT create duplicates, matched by slug).

## Task 2: IAppConfig configuration keys

- **Spec ref**: specs/catalogs/spec.md (CAT-004)
- **Status**: done
- **Files**: `lib/Service/CatalogiService.php`, `lib/Settings/SettingsController.php` (or equivalent)
- **Acceptance criteria**:
  - [ ] `catalog_schema` and `catalog_register` are read from `IAppConfig` at runtime, not hardcoded.
  - [ ] Missing keys result in a `null` return from `getCatalogBySlug()` without throwing an unhandled exception.
  - [ ] Keys are listed in the admin settings UI so operators can configure them after install.

## Task 3: Public catalog list endpoint (CAT-001)

- **Spec ref**: specs/catalogs/spec.md (CAT-001)
- **Status**: done
- **Files**: `lib/Controller/CatalogiController.php`, `lib/Service/CatalogiService.php`, `appinfo/routes.php`
- **Acceptance criteria**:
  - [ ] `GET /api/catalogi` returns all catalogs as a paginated JSON response.
  - [ ] Response includes pagination metadata: `total`, `page`, `pages`, `limit`.
  - [ ] Controller method is annotated `#[PublicPage]`, `#[NoCSRFRequired]`, `#[NoAdminRequired]`.
  - [ ] Controller method body is ≤10 lines; all logic is in `CatalogiService`.
  - [ ] CORS headers (`Access-Control-Allow-Origin`, etc.) are present in all responses.
  - [ ] No RBAC or multitenancy filter is applied.
  - [ ] `@spec openspec/changes/catalogs/tasks.md#task-3` PHPDoc tag is on the controller method and service.

## Task 4: Public catalog detail endpoint (CAT-002)

- **Spec ref**: specs/catalogs/spec.md (CAT-002)
- **Status**: done
- **Files**: `lib/Controller/CatalogiController.php`, `lib/Service/CatalogiService.php`, `appinfo/routes.php`
- **Acceptance criteria**:
  - [ ] `GET /api/catalogi/{id}` calls `CatalogiService::index($catalog)`.
  - [ ] Service fetches publications scoped to the catalog's `registers[]` and `schemas[]` arrays.
  - [ ] `@self` metadata fields (`schemaVersion`, `relations`, `locked`, `owner`, etc.) are stripped from all publication objects in the result.
  - [ ] Paginated JSON response with CORS headers is returned.
  - [ ] If catalog ID does not exist, `404 Not Found` with a static `message` field is returned — no stack trace, SQL, or internal path in the response body.
  - [ ] Controller method annotated `#[PublicPage]`, `#[NoCSRFRequired]`, `#[NoAdminRequired]`.

## Task 5: CORS preflight OPTIONS routes (CAT-008)

- **Spec ref**: specs/catalogs/spec.md (CAT-008)
- **Status**: done
- **Files**: `appinfo/routes.php`, `lib/Controller/CatalogiController.php`
- **Acceptance criteria**:
  - [ ] `OPTIONS /api/catalogi` and `OPTIONS /api/catalogi/{id}` are registered in `appinfo/routes.php` BEFORE any wildcard `{slug}` routes.
  - [ ] Both OPTIONS handlers return `200 OK` with `Access-Control-Allow-Origin`, `Access-Control-Allow-Methods`, `Access-Control-Allow-Headers`.
  - [ ] Verified with a real browser preflight (or curl `--request OPTIONS`) against a running Nextcloud instance.

## Task 6: Distributed slug cache (CAT-005, CAT-006, CAT-007)

- **Spec ref**: specs/catalogs/spec.md (CAT-005, CAT-006, CAT-007)
- **Status**: done
- **Files**: `lib/Service/CatalogiService.php`
- **Acceptance criteria**:
  - [ ] `CatalogiService` creates a distributed cache via `ICacheFactory::createDistributed('opencatalogi_catalogs')` in its constructor.
  - [ ] `getCatalogBySlug(string $slug)`: checks `cache->get("catalog_slug_{$slug}")` first; on miss, queries `ObjectService::searchObjects()`, stores with TTL `3600`, returns array or `null`.
  - [ ] Null (slug not found) is NOT stored in cache.
  - [ ] `invalidateCatalogCache(string $slug)`: calls `cache->remove("catalog_slug_{$slug}")`.
  - [ ] `invalidateCatalogCacheById(int|string $id)`: resolves slug by ID via `ObjectService`, then calls `invalidateCatalogCache($slug)`.
  - [ ] `warmupCatalogCache(string $slug)`: calls `invalidateCatalogCache($slug)` then re-fetches (forces a fresh entry).
  - [ ] `warmupCatalogCacheById(int|string $id)`: resolves slug by ID, then calls `warmupCatalogCache($slug)`.

## Task 7: Automatic cache lifecycle via CatalogCacheEventListener (CAT-011)

- **Spec ref**: specs/catalogs/spec.md (CAT-011)
- **Status**: done
- **Files**: `lib/Listener/CatalogCacheEventListener.php`, `lib/AppInfo/Application.php`
- **Acceptance criteria**:
  - [ ] `CatalogCacheEventListener` is registered in `Application.php` for `ObjectCreatedEvent`, `ObjectUpdatedEvent`, and `ObjectDeletedEvent`.
  - [ ] On each event, the listener checks whether `object->getSchema() === catalog_schema && object->getRegister() === catalog_register` from `IAppConfig`. Non-catalog events are silently ignored (no log entry, no exception).
  - [ ] `ObjectCreatedEvent` → `warmupCatalogCache($slug)`.
  - [ ] `ObjectUpdatedEvent` → `warmupCatalogCache($slug)`.
  - [ ] `ObjectDeletedEvent` → `invalidateCatalogCache($slug)`.
  - [ ] The listener MUST NOT call `saveObject()` or any persistence method on the originating object (see CAT-012 in the `fix-catalog-update-infinite-loop` change).
  - [ ] `@spec openspec/changes/catalogs/tasks.md#task-7` PHPDoc tag on the listener class.

## Task 8: Multi-schema and multi-register publication scoping (CAT-010)

- **Spec ref**: specs/catalogs/spec.md (CAT-010)
- **Status**: done
- **Files**: `lib/Service/CatalogiService.php`
- **Acceptance criteria**:
  - [ ] `CatalogiService::index()` iterates `catalog['registers']` and `catalog['schemas']` and unions publication results across all pairs.
  - [ ] Publications from registers or schemas not listed in the catalog are excluded from the response.
  - [ ] Behavior for empty `registers` or empty `schemas` arrays is documented and consistently applied (either "all registers/schemas" or "empty result set").

## Task 9: Admin UI components

- **Spec ref**: specs/catalogs/spec.md (Admin UI section)
- **Status**: done
- **Files**: `src/views/CatalogiIndex.vue`, `src/modals/CatalogModal.vue`, `src/modals/ViewCatalogi.vue`, `src/components/CatalogiWidget.vue`
- **Acceptance criteria**:
  - [ ] `CatalogiIndex.vue` uses `CnIndexPage` (or equivalent `@conduction/nextcloud-vue` list component) — no bespoke table.
  - [ ] `CatalogModal.vue` uses `CnFormDialog` or `CnAdvancedFormDialog` for create/edit — no bespoke form component.
  - [ ] `ViewCatalogi.vue` uses `CnDetailPage` or `CnDetailCard` sections.
  - [ ] `CatalogiWidget.vue` uses `CnWidgetWrapper` / `CnInfoWidget` or equivalent — no custom widget shell.
  - [ ] All user-visible strings are wrapped in `t('opencatalogi', '...')`. No hardcoded Dutch or English strings in templates.
  - [ ] All components import from `@conduction/nextcloud-vue`, not `@nextcloud/vue`.
  - [ ] Every `<NcFoo>` and `<CnFoo>` used in a template is imported AND listed in `components: {}`.
  - [ ] SPDX header `<!-- SPDX-License-Identifier: EUPL-1.2 -->` present as first line of each `.vue` file.

## Task 10: Seed data in publication_register.json

- **Spec ref**: design.md (Seed Data section), ADR-001
- **Status**: todo
- **Files**: `lib/Settings/publication_register.json`
- **Acceptance criteria**:
  - [ ] 5 seed catalog objects are defined under `components.objects[]` using the `@self` envelope with `register: "publication"`, `schema: "catalog"`, and a unique `slug`.
  - [ ] Seed objects use realistic Dutch government values (see `design.md` for the full list).
  - [ ] Seed objects are loaded via `ConfigurationService::importFromApp()` on install; re-importing with `force: false` MUST NOT create duplicates (matched by slug).
  - [ ] After a fresh install, `GET /api/catalogi` returns at least 5 catalog results without manual data entry.

## Task 11: Pre-commit verification

- **Status**: todo
- **Acceptance criteria**:
  - [ ] `grep -rL 'SPDX-License-Identifier' lib/Controller/CatalogiController.php lib/Service/CatalogiService.php lib/Listener/CatalogCacheEventListener.php` returns no output.
  - [ ] `grep -rn 'findObject\|saveObject\|findObjects' lib/Service/CatalogiService.php` — every call has 3 positional args.
  - [ ] `grep -rn 'getMessage()' lib/Controller/CatalogiController.php` returns no output (static error strings only).
  - [ ] `npm run check:l10n` reports zero MISSING and zero UNWRAPPED.
  - [ ] `npm run find:unwrapped` — no prose-shaped literals remain outside `t()` in the catalog Vue components.
  - [ ] Catalog list and detail endpoints tested with a real browser `GET` and `OPTIONS` (curl or browser DevTools) against a running dev instance.
  - [ ] Tail Nextcloud logs during a catalog update — confirm no recursive listener lines appear.
