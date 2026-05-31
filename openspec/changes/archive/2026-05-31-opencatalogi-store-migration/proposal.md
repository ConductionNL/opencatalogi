# opencatalogi-store-migration

## Why

OpenCatalogi maintains its own ~2.5k-line `src/store/modules/object.js` which predates `@conduction/nextcloud-vue`'s `useObjectStore`. The local store has drifted from the canonical CRUD pattern in two concrete ways that bite consumers in production:

1. **Missing methods** — the same gap that caused decidesk #162 (`fetchObject` not present, `liveUpdatesPlugin` not wired) exists here. Vue files cannot rely on the canonical `objectStore.fetchObject(type, id)` signature, and live-update events on objects never arrive because no socket plugin is mounted.
2. **No plugin surface** — audit-trails, files, relations, lifecycle, selection, and live-updates are all reimplemented locally with different shapes than the lib (`fetchRelatedData(type, id, dataType, ...)` vs the lib's per-resource methods). New apps that adopt the lib's `CnDetailPage`/`CnIndexPage` plug-in conventions cannot be reused inside opencatalogi without re-aliasing.

Project memory's "Store pattern guidance" rule is explicit: **use Options API + `createObjectStore` from the lib; do not maintain custom stores**. This change brings opencatalogi back onto the canonical pattern without touching any Vue file.

## What Changes

- Rewrite `src/store/modules/object.js` as a **thin wrapper** around `createObjectStore('opencatalogi-objects-inner', { plugins: [...] })`.
- Use the `@conduction/nextcloud-vue` version already pinned on `development` (`^1.0.0-beta.66`), which exposes the full plugin set + `fetchObject`. (No dependency bump is required; this rebuild was rebased onto current `development`.)
- Preserve every existing public method, getter, and state-shape on the outer Pinia store named `'object'`. The 60 distinct `objectStore.*` call patterns and 697 callsites continue to work unchanged — **no Vue file is edited**.
- Mount the lib's `filesPlugin`, `auditTrailsPlugin`, `relationsPlugin`, `lifecyclePlugin`, `selectionPlugin`, and `liveUpdatesPlugin` against the inner store.
- Keep `catalog.js`, `navigation.ts`, `search.ts` local — they call non-OpenRegister endpoints (catalog-aware `/api/{catalogSlug}`, federation search) or are pure UI state.
- Keep 10 categories of local-store features (active-object map, related-data fan-out, settings + dynamic type registration, columnFilters/metadata system, lifecycle signature shims, cross-schema `createObject` override, mass operations, attachment publish, per-object error tracking, search debounce) implemented in the outer wrapper because the lib does not cover them.

## Impact

- **Affected specs**: new capability `opencatalogi-store-migration`
- **Affected code**: `src/store/modules/object.js` (rewrite), `package.json` (dependency bump)
- **Out of scope**:
  - Manifest renderer adoption (PR #547 is in flight on a different branch and edits the manifest entry file, not the store; should be orthogonal merge).
  - Vue-component-level changes — callsites are unchanged.
  - Removing other local stores (`catalog.js` retains its `/api/{catalogSlug}` shape; future change can split it).
- **Risk**: production app, no admin merge. PR opens against `development` and waits for human review.
