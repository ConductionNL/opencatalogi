---
kind: code
depends_on: []
---

# Proposal: publication-list-status-icons

## Why

Found in a test of the live environment: "Mis de status zoals voorheen via iconen". The publications list used to
start each row with a colored status icon (`PublishedIcon`): a green list icon for a published publication, an orange
pencil for a draft, a red alert for a depublished one. Since the list moved to `CnIndexPage`, the table view shows
no status icon; only the card view still has the icon and a colored border (`cardAccent`).

## What changes

- `CatalogPublicationsIndex` passes `rowIcon` and `rowClass` to `CnIndexPage`: a publication row starts with the
  icon its card shows, colored by the same status (`--color-element-success`, `-warning`, `-error`).
- The status comes from the publication's dates, as on the card and in the old list. A row of another schema gets no
  icon.
