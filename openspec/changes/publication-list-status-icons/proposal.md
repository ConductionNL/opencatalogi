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
  icon its card shows, colored by the same status (`--color-element-success`, `-warning`, `-error`, `-info`).
- The status is the `state` the server sends per row (REQ-PLC-002), the value the State column shows. The card
  (`cardAccent`) took it from the dates, so a draft with a past date showed as published beside a State of Draft; the
  card and the row now both use the server's state. A row of another schema gets no icon.
