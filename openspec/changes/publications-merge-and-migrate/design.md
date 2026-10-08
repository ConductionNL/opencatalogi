# Design: publications-merge-and-migrate

## Boards

Both boards sit on the Publications list with two rows selected and the
selection bar showing "2 selected".

**OcSamenvoegen** draws the dialog "Merge objects", "Step 2 of 3, Configure
merge", with the steps Select target object, Configure merge, Merge report.
It shows the register and schema with the note "Objects can only be merged if
they belong to the same register and schema", the line `Merging "<source>"
into "<target>"`, and a table Property, Source, Target, Merge value (Source,
Target, Custom), Result value. Under it: "Files attached to source object (6)"
with View Files and the choice Transfer to target object or Delete files, and
"Relations to source object (2)" with View Relations and the choice Transfer
to target object or Drop relations. The footer note: "After the merge the
source object is deleted. The merge report then counts properties changed,
files and relations transferred, and references updated." Buttons Cancel,
Back, Merge objects.

**OcMigreren** draws "Migrate 3 objects", "Step 3 of 4, property mapping",
steps Confirm object selection, Select target register and schema, Property
mapping, Migration report. Source and target register and schema, the note
"Properties not mapped will be discarded", a two-column mapping, a warning
"Attachments is not mapped, so it is discarded for these 3 objects", and the
report counts "objects migrated and failed, and properties mapped and
discarded". Buttons Cancel, Back, Migrate objects.

The existing dialogs already carry these steps, fields and choices
(`MergeObject.vue` steps at lines 40, 116, 417; file and relation choices at
243-329; `MigrationObject.vue` mapping note at 215). The change is the entry
point and the two defects.

## D1. Entry point: CnIndexPage bulk actions

`CnIndexPage` takes `bulkActions[]` with a named `handler` resolved from the
app's registry, called with `{ actionId, selectedIds, count }`. The
Publications page adds, in `publicationPairConfig`:

```json
"bulkActions": [
  { "id": "merge", "label": "Merge", "icon": "CallMerge", "handler": "openPublicationMerge" },
  { "id": "migrate", "label": "Migrate", "icon": "DatabaseArrowRight", "handler": "openPublicationMigration" }
]
```

The handlers live in `src/services/publicationActions.js`, next to
`openPublicationFiles`. "Merge" with a count other than two shows the toast
"Select exactly two publications to merge" and opens nothing; the bulk action
contract has no per-count disabled state, so the handler guards it.

## D2. Staging the selection

`openPublicationMerge` fetches the two selected objects, sets the first as
`objectStore.objectItem` (the dialog's source) and passes the second through
`navigationStore.setTransferData({ mergeTarget, startStep: 2 })`. The dialog
reads `mergeTarget` on mount and starts at step 2; without it, it starts at
step 1 as today. The register and schema come from the page's active pair,
set on `catalogStore` as the dialog expects.

`openPublicationMigration` fetches the selected objects and sets
`objectStore.selectedObjects`, which `initializeMigration()` reads.

## D3. The merge call

`performMerge()` calls `objectStore.mergeObjects('publication', sourceId,
{ target, object, fileAction, relationAction })`, which posts to
`/api/objects/{register}/{schema}/{id}/merge` with the body `objects#merge`
reads (`target` required).

## D4. Rights

Both endpoints are OpenRegister's and apply its RBAC. The bulk actions are
shown to users who may edit publications, the same rule as the built-in
delete (`showDeleteAction`).

## Risks

- A catalog with several register and schema pairs: merge across pairs is
  refused by the dialog's same-pair rule; migrate is the way to move between
  them.
