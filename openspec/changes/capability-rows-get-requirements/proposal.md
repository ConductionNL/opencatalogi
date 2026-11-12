---
kind: spec
depends_on: []
---

# Proposal: capability-rows-get-requirements

## Why

Every row of `openspec/parity/capabilities.json` must name the requirement that
describes it in `built.requirement`. Most rows already had a requirement whose
scenarios say what the row says, and now link to it. Five rows had none:

| row | what the row says | what was missing |
|---|---|---|
| `pub-create` | Create a publication with a title, summary, description and category. | No requirement for creating a publication with those fields. |
| `cat-multi-owner` | Show one publication in the catalogues of several organisations without copying it. | `CAT-010` covers several schemas per catalogue, not one publication in several catalogues. |
| `lc-archive` | Transfer publications to an e-depot for permanent archiving. | `RET-006` keeps an archived publication in the app. Nothing hands it to an e-depot. |
| `ops-reimport` | Re-import the shipped configuration after an update. | `REQ-SHELL-002` covers saving settings, not re-importing. |
| `ops-dashboard` | See counts of catalogues, publications and attachments on the app's own dashboard. | `REQ-DASH-001` counts publications only. |

## What changes

- Five added requirements, one per row, each in the main spec it belongs to.
- `pub-create`, `cat-multi-owner` and `ops-reimport` describe what the app
  already does. The tasks only sync them into the main specs.
- `lc-archive` and `ops-dashboard` describe what the app does not do yet. Their
  rows move from `built` to `specified`, and the tasks build them.

## Rows covered

`pub-create`, `cat-multi-owner`, `lc-archive`, `ops-reimport`, `ops-dashboard`.

## Out of scope

Code changes for rows that already link to a requirement in another spec or
open change.
