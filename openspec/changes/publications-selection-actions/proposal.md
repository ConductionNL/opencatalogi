---
kind: code
depends_on: [publications-merge-and-migrate]
---

# Proposal: publications-selection-actions

## Why

The Publications list can select rows, and its selection bar offers only the
library's own copy and delete. The mass dialogs the boards draw already exist
and nobody can reach them:

- **OcPubliceren** draws "Publish" and "Withdraw publication" in the selection
  bar, opening the publish dialog with its three modes (now, later,
  retroactively) for the selected publications. `MassPublishObjects` and
  `MassDepublishObjects` are mounted in `src/modals/Modals.vue` on
  `navigationStore.dialog`, and nothing sets either value.
- **OcMassaActies** draws "Lock" for three selected publications, with the
  note that Unlock and Validate open the same dialog from More.
  `MassLockObjects` and `MassUnlockObjects` exist in `src/modals/object/` and
  are not mounted at all; `MassValidateObjects` is mounted and unreachable.

The dialogs read the selection from `objectStore.selectedObjects`, which the
`CnIndexPage` selection does not fill.

## What changes

- The Publications page declares `bulkActions` on the publication pair:
  Publish, Withdraw publication (label "Depublish"), Lock, Unlock and
  Validate, next to Merge and Migrate from `publications-merge-and-migrate`.
- One generic handler, `openSelectionDialog`, stages the selected rows in
  `objectStore.selectedObjects` and opens the dialog the action's `props`
  names. Which dialog an action opens is manifest configuration.
- `CatalogPublicationsIndex` calls a bulk handler with the selected rows and
  the action's `props`, not only their ids, and reloads the list and clears
  the selection when the dialog it opened closes.
- `MassLockObjects` and `MassUnlockObjects` are mounted in `Modals.vue`.
- The board lists Lock, Unlock, Validate, Merge and Migrate under More. The
  library draws every bulk action inline and its manifest schema refuses an
  unknown key, so they show inline until nextcloud-vue offers a More menu in
  the selection bar (T06).
- Bulk action labels are translated like row action labels.

## Out of scope

- "Publish attachments" and "Withdraw attachments" from More: the attachment
  dialog works on the attachments of one publication, not on a selection of
  publications.
- The board look of the publish dialog (choice cards instead of a select).
