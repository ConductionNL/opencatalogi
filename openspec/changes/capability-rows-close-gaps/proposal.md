---
kind: spec
depends_on: []
---

# Proposal: capability-rows-close-gaps

## Why

The capability audit of 12 November 2026 read the requirement each row of
`openspec/parity/capabilities.json` names and found three rows whose
requirement says only part of the row:

| row | what the row says | what was missing |
|---|---|---|
| `wr-review` | Send a batch for review only once every document in it is assessed. | The row named the batch data model, which lists fields. Nothing said review is refused while a document is unassessed. |
| `cat-glossary` | Keep a glossary that explains the terms used in the publications. | CMS-030 lists terms over the public API. Nothing said staff create, edit and delete terms. |
| `pub-relations` | Link a publication to related publications and see which ones link back to it. | OpenRegister's relation labels cover the link back. Nothing said an editor links one publication to another. |

## What changes

- Three added requirements, each in the main spec it belongs to.
- `wr-review` and `cat-glossary` describe what the app already does. Their
  tasks name the test and sync the requirement into the main spec.
- `pub-relations` describes what the app does not do yet: the publication
  schema declares no property that points at another publication. The tasks
  build it. The row stays `specified` until they are done.

## Rows covered

`wr-review`, `cat-glossary`, `pub-relations`.

## Out of scope

The link back from the other publication is OpenRegister's
(`relation-types-with-inverses`); this change only consumes it.
