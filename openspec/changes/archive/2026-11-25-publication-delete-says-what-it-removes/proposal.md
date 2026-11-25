---
kind: code
depends_on: []
---

# Proposal: publication-delete-says-what-it-removes

## Why

**opencatalogi matrix, row `pub-delete`**, "Delete one publication after a
confirmation that says what disappears from the public side.", rated
`partial`, `built.state` `specified`. Drawn on board
**OcPublicatieVerwijderen** (canvas part 2, `QAAxpcsFKBCvDUGbQbwtCa`).

Until now the board rode on row `pub-bulk` ("Publish, withdraw or delete many
publications in one action"). That row is about the selection bar and is
`specified` because bulk publish and withdraw are missing; deleting one
publication from its row is built and has a different question, so it gets
its own row (decision 99, 8 Oct).

Deleting one publication works: the Publications page lists the actions
`builtin:delete` and `showDeleteAction: true` (`src/manifest.json:586,593`),
and `CnIndexPage` opens nextcloud-vue's `CnDeleteDialog`. That dialog says
"Delete item" and "Are you sure you want to permanently delete "{name}"? This
action cannot be undone." It does not say what the board says, which is what
an editor of a Woo publication needs to know: the publication and its
attachments disappear from the public page and from the Woo sitemap, and
archiving keeps them for the retention term instead. `CnIndexPage` passes
`item`, `nameField` and `nameFormatter` to the dialog and no title or message
(`CnIndexPage.vue:307-314`), so an app cannot set them.

## What changes

- nextcloud-vue: `CnIndexPage` takes an optional `deleteDialog` config
  (`title`, `message`, `description`) and passes it to `CnDeleteDialog`.
- opencatalogi: the publication pair sets the title "Delete publication", the
  question, and the consequence line from the board.

## Rows covered

- `pub-delete`

## Out of scope

- Bulk delete. It stays on `pub-bulk` and `CnMassDeleteDialog`.
- Offering "Archive" as a button in the dialog. The board names archiving as
  the alternative in text only.
