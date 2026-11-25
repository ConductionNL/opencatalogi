# Design: publication-delete-says-what-it-removes

## Board

**OcPublicatieVerwijderen** draws the Publications list with a dialog on top:
title "Delete publication", the question "Do you want to delete "Woo decision
on the swimming pool tender"? This action cannot be undone.", then a second
paragraph in muted type: "The publication and its attachments disappear from
the public page and from the Woo sitemap. Archiving keeps them for the
retention term instead." Buttons Cancel and Delete (destructive).

## D1. The text belongs to the app, the dialog to the library

The consequence is opencatalogi's knowledge; the dialog is nextcloud-vue's.
`CnDeleteDialog` already has props for the title (default "Delete item") and
the message; `CnIndexPage` does not forward them. A `deleteDialog` object in
the page config, forwarded as props, keeps one dialog for every app and lets
each app say what deleting means for its records. `description` is a new
optional prop on `CnDeleteDialog`, rendered under the message as a muted
paragraph, and left out when empty so other apps see no change.

## D2. opencatalogi's values

On `publicationPairConfig` only, since other pairs in a catalog are not
publications:

```json
"deleteDialog": {
  "title": "Delete publication",
  "message": "Do you want to delete \"{name}\"? This action cannot be undone.",
  "description": "The publication and its attachments disappear from the public page and from the Woo sitemap. Archiving keeps them for the retention term instead."
}
```

`{name}` is filled by the dialog's existing name resolution. Strings go
through `t('opencatalogi', ...)` when the manifest is translated, like the
other manifest labels.
