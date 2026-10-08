---
kind: code
depends_on: [woo-dossier-publication]
---

# Proposal: woo-batch-creates-publications

## Why

An editor who publishes a Woo batch (active disclosure) sees it marked "published", but nothing is public. `WooService::publishBatch()` (`lib/Service/WooService.php`) writes a `wooPublication` summary onto the batch object and stops. No publication exists, so search, the Woo sitemap and saved-search alerts never see the documents (hydra `woo-citizen-journey`, contract C6, journey step J1.3).

Ruben decided on 30 September 2026: both publishing paths stay, and the batch path must create real publications.

## What changes

- Publishing a batch creates one `publication` with `publicationKind: actief`, the batch's information category (`wooCategory`), its case reference, a publication date of now, and every disclosable document attached as a published file.
- Only `openbaar` documents and the redacted version of `deels_openbaar` documents are attached, as today.
- The approval gate stays exactly as it is.
- Every document is resolved to a Nextcloud file before anything is written. One that cannot be found stops the publish with its name, and nothing is created.
- The batch records the publication's id and URL in `wooPublication`, so the editor can open it.

## Hydra requirements implemented

- `woo-citizen-journey`: Both publishing paths MUST create a public, searchable publication (the batch half).

## Out of scope

The dossiq path (`woo-publish-decision-from-the-case`, dossiq lane).
