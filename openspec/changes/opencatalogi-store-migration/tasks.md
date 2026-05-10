# Tasks — opencatalogi-store-migration

## 1. Dependency

- [x] 1.1 Bump `@conduction/nextcloud-vue` to `^1.0.0-beta.12` in `package.json`
- [x] 1.2 Run `npm install --legacy-peer-deps` to refresh `package-lock.json`
- [x] 1.3 Verify the installed lib resolves to `1.0.0-beta.14` and exports the 6 plugins (`filesPlugin`, `auditTrailsPlugin`, `relationsPlugin`, `lifecyclePlugin`, `selectionPlugin`, `liveUpdatesPlugin`)

## 2. Store rewrite

- [x] 2.1 Rewrite `src/store/modules/object.js` as a thin wrapper:
  - [x] 2.1.1 Outer Pinia store keeps id `'object'` and exports `useObjectStore`
  - [x] 2.1.2 Inner store created via `createObjectStore('opencatalogi-objects-inner', { plugins: [...] })`
  - [x] 2.1.3 CRUD actions delegate to inner store (`fetchCollection`, `fetchObject`, `saveObject`, `deleteObject`, `resolveReferences`)
  - [x] 2.1.4 Outer store preserves `{ results: [...] }` collection shape
  - [x] 2.1.5 Outer store keeps active-object map, related-data, columnFilters, settings, search debounce, mass operations, attachment publish/depublish, per-object errors
- [x] 2.2 Confirm no Vue file is touched
- [x] 2.3 Confirm `catalog.js`, `navigation.ts`, `search.ts`, `store.js` are unchanged

## 3. Validate

- [x] 3.1 `npx eslint src/store/` passes
- [x] 3.2 `npx webpack --mode production` succeeds

## 4. Spec

- [x] 4.1 Add `specs/opencatalogi-store-migration/spec.md` with REQ-OSM-1 through REQ-OSM-N
