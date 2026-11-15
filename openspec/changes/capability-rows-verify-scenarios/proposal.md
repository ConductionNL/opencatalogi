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
| `cat-schemas` | Choose per catalogue which kinds of publication it holds. | REQ-CATM-006 scopes a catalogue to its schemas. Nothing said the administrator picks them on the catalogue form. |
| `woo-rules` | Set a standing rule that says which records are published under which category, and preview what it catches. | REQ-PIN-101 has the rule and its preview. Nothing said the rule names the category. |
| `wr-batch` | Open a disclosure batch for one Woo request and gather the documents in scope. | The queue requirement creates a batch when a case system sends documents. Nothing said an officer opens one for a request and gets its documents. |
| `fed-directory` | Publish a directory of this installation's catalogues that other installations can read. | DIR-001 combines listings. Nothing said the directory carries this installation's own catalogues. |
| `srch-pid` | Give every publication a persistent identifier that stays citable. | DCAT-006 keeps IRIs stable between harvests. Nothing said the identifier survives an edit. |

## What changes

- One added requirement in `publications`: a property an administrator adds to
  the publication schema in OpenRegister appears on the publication form and
  is saved and served, with no change to OpenCatalogi.
- The row links to it next to OpenRegister's REQ-LCA-003.
- Five more added requirements, REQ-CVS-002 to REQ-CVS-006, in `catalogs`,
  `woo-compliance`, `woo-transparency`, `federation` and `publications`, one
  per row in the table. Each row links to its new requirement next to the one
  it already had.
- Three rows link to an existing requirement that says their missing part:
  `woo-tooi` to WOO-TOOI-003 (the handling type list), `fed-portal` to
  portaliq REQ-WSD-001 (filtering by organisation) and `srch-deeplink` to
  PUB-002 and PUB-020 (a publication's address by UUID keeps answering).

## Rows covered

`cat-custom-fields`, `cat-schemas`, `woo-rules`, `wr-batch`, `fed-directory`, `srch-pid`.

## Out of scope

Keeping the added field through an upgrade. That is OpenRegister's
`local-changes-to-app-shipped-configuration`.
