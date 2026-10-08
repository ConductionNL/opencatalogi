---
kind: code
depends_on: []
---

# Proposal: publications-merge-and-migrate

## Why

**opencatalogi matrix, rows `pub-merge` and `pub-migrate`**, both rated `no`,
`built.state` `specified`. Drawn on boards **OcSamenvoegen** and
**OcMigreren** (canvas part 2, `QAAxpcsFKBCvDUGbQbwtCa`). Added by decision 99
(8 Oct).

- `pub-merge`: "Merge two publications into one, choosing per field which
  value stays and what happens to files and relations."
- `pub-migrate`: "Move selected publications to another register and schema,
  mapping each field."

The dialogs exist and no user can reach them. `src/modals/Modals.vue:26,30`
mounts `MergeObject` and `MigrationObject` when `navigationStore.modal` is
`mergeObject` or `migrationObject`, and nothing in `src/` sets either value.
The Publications page (`src/manifest.json:506`, `CatalogPublicationsIndex`
over `CnIndexPage`) has a selection bar with no bulk action for them.

The merge dialog would also fail if it were opened:
`MergeObject.vue:1039` calls `objectStore.mergeObjects({ register, schema, ... })`
with one object, while nextcloud-vue's lifecycle plugin takes
`mergeObjects(type, sourceId, options)` (`src/store/plugins/lifecycle.js:172`),
so the request URL is built from an object. The migration dialog reads
`objectStore.selectedObjects` (`MigrationObject.vue:624`), which the
`CnIndexPage` selection does not fill.

## What OpenRegister already covers

These are OpenRegister's generic object dialogs (OpenRegister ships the same
`src/modals/object/MergeObject.vue` and `MigrationObject.vue`), and both
endpoints are OpenRegister's: `POST /api/objects/{register}/{schema}/{id}/merge`
(`objects#merge`) and `POST /api/migrate` (`objects#migrate`). Its specs do not
cover offering them from an app's list:

- `openregister/mdm-merge` and `openregister/mdm-merge-ui` specify a different
  merge: master-data deduplication on schemas that declare
  `x-openregister-merge`, launched from a duplicate pair, keeping the losing
  record as `merged-into-other` and reversible within a window. The board
  draws the other one: the source is deleted, files and relations are
  transferred or dropped.
- `openregister/entity-management-modals` names `MigrationObject.vue` among
  the bulk modals (staged selection, per-item outcome), which is the
  behaviour this change relies on.
- No OpenRegister spec owns `objects#merge` or `objects#migrate` beyond
  `api-test-coverage` listing them.

So this change wires opencatalogi's list to OpenRegister's endpoints; it adds
no backend.

## What changes

- Publications page, selection bar: "Merge" (enabled with exactly two
  selected) and "Migrate" (one or more selected), as `bulkActions` on the
  publication pair.
- "Merge" opens the merge dialog with the first selected publication as the
  source and the second as the target, at step 2 "Configure merge".
- "Migrate" opens the migration dialog at "Confirm object selection" with the
  selection.
- The merge call is fixed to the lifecycle plugin's signature.
- After a merge or a migration the list reloads and the selection clears.

## Rows covered

- `pub-merge`
- `pub-migrate`

## Out of scope

- A reversible merge. Using `openregister/mdm-merge` would need
  `x-openregister-merge` on the publication schema and keeps the source as a
  record; that is a separate decision.
- Moving the dialogs into nextcloud-vue so every app shares one copy.
