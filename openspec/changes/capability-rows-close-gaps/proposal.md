---
kind: spec
depends_on: []
---

# Proposal: capability-rows-close-gaps

## Why

The capability audit of 12 November 2026 read the requirement each row of
`openspec/parity/capabilities.json` names and found two rows whose
requirement says only part of the row:

| row | what the row says | what was missing |
|---|---|---|
| `wr-review` | Send a batch for review only once every document in it is assessed. | The row named the batch data model, which lists fields. Nothing said review is refused while a document is unassessed. |
| `cat-glossary` | Keep a glossary that explains the terms used in the publications. | CMS-030 lists terms over the public API. Nothing said staff create, edit and delete terms. |

## What changes

- Two added requirements, each in the main spec it belongs to. Both describe
  what the app already does, so the tasks name the test and sync the
  requirement into the main spec.
- Row `pub-relations` had the same gap. Its missing half is already written in
  the open change `publication-relations-place-and-source-ids` (REQ-PRS-001,
  REQ-PRS-002), so the row links there instead.

- Rows whose evidence or note said the behaviour does not happen lose `built`
  (decision 389) and get a task in section 2 of `tasks.md`: `ops-usage`,
  `ops-usage-series`, `ops-most-viewed`, `pub-versions`, `pub-quality`,
  `lc-queue`, `lc-decide` and `fed-harvest-removed`.

## Rows covered

`wr-review`, `cat-glossary`, and the build plan of the eight rows above.

## Out of scope

The requirements of the eight rows above. They are already written in their own
specs; this change only plans the work.
