---
kind: code
depends_on: []
---

# Proposal: publication-attachment-upload-dialog

## Why

Board **OcBijlageToevoegen** draws the publication page's Attachments card with
"Add attachment" in its header, opening the upload dialog: labels, Automatically
publish, a drop zone and a progress list per file (uploaded, busy, failed with
the reason) with Retry all and Open folder.

The dialog exists (`src/modals/generic/UploadFiles.vue`, mounted in
`Dialogs.vue` on `navigationStore.dialog === 'uploadFiles'`) and is reachable
only from the unrouted legacy `ViewObject`. The publication page's Attachments
card is the library's `files` integration (CnFilesCard), which has no Add.

## What changes

- An app widget `publication-attachments` lists the files with the library's
  CnFilesCard and adds an "Add attachment" button in the card's header row. It
  sets the page's publication as the active publication and opens the upload
  dialog; when the dialog closes the list is read again.
- The PublicationDetail page's `pub-files` widget uses it.

## Later

- When CnFilesCard forwards its header actions slot, the button moves into it
  and the positioning rule goes.
