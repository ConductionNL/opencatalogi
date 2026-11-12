---
kind: spec
depends_on: []
---

# Proposal: capability-rows-verify-scenarios

## Why

On 12 November 2026 every row of `openspec/parity/capabilities.json` was read
against the scenarios of the requirement it names. One row had no scenario for
its main half:

| row | what the row says | what was missing |
|---|---|---|
| `cat-custom-fields` | Add your own metadata fields to a kind of publication without programming. | OpenRegister's REQ-LCA-003 keeps an added field through an upgrade. Nothing said that a field an administrator adds shows on the publication form and is saved. |

## What changes

- One added requirement in `publications`: a property an administrator adds to
  the publication schema in OpenRegister appears on the publication form and
  is saved and served, with no change to OpenCatalogi.
- The row links to it next to OpenRegister's REQ-LCA-003.

## Rows covered

`cat-custom-fields`.

## Out of scope

Keeping the added field through an upgrade. That is OpenRegister's
`local-changes-to-app-shipped-configuration`.
