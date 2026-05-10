# Design — opencatalogi-store-migration

## Context

OpenCatalogi has 60 distinct `objectStore.*` call patterns and ~697 callsites across `src/components/`, `src/views/`, `src/modals/`, and other modules. A "rip-and-replace" migration that retypes every callsite is high-risk for a production app and would conflict with the in-flight manifest renderer PR #547. The thin-wrap pattern lets us swap the underlying CRUD layer without changing any Vue file.

## Migration pattern: thin-wrap

```
+---------------------------------------------------------+
|  Vue files (697 callsites, 60 patterns)                 |
|  objectStore.fetchCollection / fetchObject / saveObject |
|  objectStore.activeObjects.publication / setActiveObject|
|  objectStore.massPublishObjects / publishAttachment ... |
+----------------------+----------------------------------+
                       |  unchanged public API
+----------------------v----------------------------------+
|  Outer store: defineStore('object')                     |
|  - Owns active-object map, related-data, columnFilters, |
|    settings, search debounce, mass operations           |
|  - Delegates CRUD/pagination/facets to inner store      |
+----------------------+----------------------------------+
                       |  inner.fetchCollection() etc.
+----------------------v----------------------------------+
|  Inner store: createObjectStore('opencatalogi-objects-  |
|  inner', { plugins: [files, auditTrails, relations,     |
|  lifecycle, selection, liveUpdates] })                  |
+---------------------------------------------------------+
```

The outer store keeps Pinia id `'object'` (so `useObjectStore()` resolves the same instance for all Vue files). The inner store gets a distinct id `'opencatalogi-objects-inner'` to avoid Pinia's "store id collision" error.

## Per-module disposition

| Module | Disposition | Rationale |
|---|---|---|
| `src/store/modules/object.js` | **Thin-wrap** lib `useObjectStore` + 6 plugins; keep facade | Canonical CRUD pattern, gains `fetchObject` + plugins |
| `src/store/modules/catalog.js` | **Kept local** | Calls own `/api/{catalogSlug}` endpoint; not OR-shaped |
| `src/store/modules/navigation.ts` | **Kept local** | Pure UI state, no OR |
| `src/store/modules/search.ts` | **Kept local** | Federation search API, not OR |
| `src/store/store.js` | **Unchanged barrel** | Still exports `objectStore`, `catalogStore`, etc. |

## Plugin availability (verified against `node_modules/@conduction/nextcloud-vue@1.0.0-beta.14`)

| Plugin | Importable | Decision |
|---|---|---|
| `filesPlugin` | yes | mount |
| `auditTrailsPlugin` | yes | mount |
| `relationsPlugin` | yes | mount |
| `lifecyclePlugin` | yes | mount |
| `selectionPlugin` | yes | mount |
| `liveUpdatesPlugin` | yes | mount |
| `logsPlugin` | yes | not mounted (overlaps with auditTrails for our use) |
| `registerMappingPlugin` | yes | not mounted (used by other apps with explicit mapping config) |
| `searchPlugin` | yes | not mounted (opencatalogi has its own federation search) |

## Gap analysis — 10 categories the local store has beyond the lib

The lib's `useObjectStore` covers CRUD + collections + pagination + facets + schema/register caching. The following are **kept in the outer wrapper** because the lib does not cover them:

1. **Active-object management** — `setActiveObject(type, obj)` (with a related-data fan-out side-effect), `getActiveObject(type)`, `clearActiveObject(type)`, `activeObjects` map. ~140 callsites.
2. **Related-data fetch on active-object set** — `fetchRelatedData(type, id, dataType, params, publicationData)` aggregates `logs`, `uses`, `used`, `files` into `relatedData[type]`. The lib has individual plugin actions (`fetchAuditTrails`, `fetchFiles`, `fetchRelations`) but no aggregated wrapper.
3. **Settings + dynamic type registration** — `fetchSettings()` calls `/apps/opencatalogi/api/settings`, then `_registerTypesFromSettings()` derives schema/register IDs from `availableRegisters[].schemas[].slug` matches. Unique to opencatalogi's settings page.
4. **Column filter & metadata system** — `metadata` (24 fixed metadata columns), `properties` (derived from the schema), `columnFilters`, `enabledColumns`, `enabledMetadata`, `enabledProperties`, `initializeProperties(schema)`, `initializeColumnFilters()`, `updateColumnFilter(id, enabled)`. Powers the column picker on every list view.
5. **Lifecycle signature shims** — `publishObject(objectItem)` extracts type/register/schema from the object; the lib's lifecyclePlugin expects `(type, id, options)`. The wrapper translates between the two without changing callers.
6. **Cross-schema `createObject` override** — `createObject(type, data, publicationData)` accepts an optional `{ register, schema }` override (used by `copyObject` so a publication copy targets the source's actual schema, not the type's default config).
7. **Mass operations** — `massPublishObjects`, `massDepublishObjects`, `massValidateObjects`, `massLockObjects`, `massUnlockObjects`, `massPublishAttachments`, `massDepublishAttachments`. These iterate via `Promise.allSettled` with per-item progress callbacks.
8. **Attachment publish/depublish** — `publishAttachment(fileId)`, `depublishAttachment(fileId)`, `refreshActivePublicationFiles()` operate on the **active publication** (no register/schema args from the caller).
9. **Per-object error tracking** — `objectErrors` map, `setObjectError`, `clearObjectError`, `getObjectError`, `clearAllObjectErrors`. Used by mass operation views to render per-row error icons.
10. **Search debounce** — `setSearchTerm(type, term)` debounces 500ms then calls `fetchCollection(type, { _search: term })`. Not present in the lib.

## Wrap-then-extend rationale

The wrap-then-extend pattern is chosen over alternatives:

- **Direct lib usage everywhere** — would require rewriting all 697 callsites and removing 10 feature categories. High-risk, large diff, conflicts with PR #547.
- **Lib + parallel local store** — two stores diverge over time; same problem we have today.
- **Drop local store, push features upstream** — desirable but multi-quarter; needs co-ordination with all 5 consumer apps. We can do this incrementally **after** this change as separate per-feature PRs.

The thin-wrap unblocks the canonical pattern today and leaves a clear "extend this category upstream" backlog visible inside the outer wrapper.

## Coordination with PR #547 (manifest renderer)

PR #547 lives on `feature/declarative-annotation-pilot` and edits:

- `lib/Dashboard/CatalogWidget.php` and other PHP widgets
- `webpack.config.js`
- `package.json` (a different dependency bump)
- `package-lock.json`

This change touches `src/store/modules/object.js` and the same `package.json` `@conduction/nextcloud-vue` line. Expected merge conflicts: **package.json + package-lock.json on the dependency line only**. Resolution rule: keep whichever lib version is higher (this PR pins `^1.0.0-beta.12`; verify #547 doesn't downgrade).

## Shape preservation (must-not-break)

- `getCollection(type)` returns `{ results: Array<any> }` — the lib's getter returns `Array<any>`. **Outer wrapper preserves the local shape** by mirroring lib results into a `state.collections[type] = { results }` slot.
- Pagination object — local has `{total, page, pages, limit, offset, next, prev}`; lib has `{total, page, pages, limit}`. **Outer wrapper owns pagination state** and copies lib values into it; `next`/`prev`/`offset` continue to be sourced from the raw API response.
- `setActiveObject(type, obj)` is an **async** action with a related-data fan-out side-effect. Preserved verbatim.
- `getActiveObject(type)` getter returns the active-object map's value or `null`.
- `objectStore.activeObjects.publication` — directly read by Vue templates. Preserved as a reactive object on the outer store.

## Pinia store ids

- Outer: `'object'` (unchanged — the consumer-facing id)
- Inner: `'opencatalogi-objects-inner'` (new)

Both stores share the same Pinia instance (`pinia.js`).
