---
kind: code
depends_on: [woo-redaction-pipeline]
---

# Proposal: woo-document-assessment-screen

## Summary

An officer assesses each document of a Woo batch on the batch page, and opens a partly public document on its own redaction page to check the redacted version before the batch can go out. Today the redaction that `woo-redaction-pipeline` built runs only when someone calls `PUT /api/woo/batches/{batchId}/documents/{docId}`, and no page does.

## Why

Row `woo-anonymise`, "Remove personal data from documents automatically before they are published." State `building`, ours `no`, re-rated on 7 October 2026 (spec round part 2). Its evidence names what is missing: "no page saves an assessment, so the redaction never runs from the app: nothing under src/ calls PUT /api/woo/batches/{batchId}/documents/{docId} (appinfo/routes.php:63) and /woo has no rowRoute to the batch page (src/manifest.json:437-455), opencatalogi#1604."

The delivered change `woo-redaction-pipeline` (4 of 4 tasks ticked) does the work behind the endpoint: `WooService::updateAssessment()` hands a `deels_openbaar` document to OpenRegister's `FileService::anonymizeDocument()` through `DocumentRedactor`, verifies the result and stores it, and `publishBatch()` refuses an unverified redaction. That change put the screen out of scope. This change is the screen.

Read on development at c3b5d5c21:

- `src/views/woo/WooBatchDetail.vue` shows counts per assessment, the unredacted list, the inventory downloads, Mark ready for review and Publish. It shows no document and saves nothing. The queue is described as "rendered by the Deck board widget on this page", but nothing renders it.
- The `/woo` index page (`WooBatches`) has no `rowRoute`, so a batch row does not open `/woo/:id`.
- `GET /api/woo/batches/{batchId}` returns the batch and `documentSummary`, not the assessments.

## What changes

- The `/woo` index opens a batch on its detail page.
- The batch page shows the document queue as four columns, To assess, Public, Partly public and Not public, as on board `OcWooBatch`. Each card shows the file name, its type and size, and for partly and not public the refusal ground. Moving a card to another column saves its assessment through the existing PUT. Moving into Partly public or Not public asks for the refusal grounds first, in a dialog. Every move has a keyboard path through the card's action menu, not only drag.
- Each card has Preview (the original, for the officer) and, on a partly public card, Redact, which opens the redaction page.
- The redaction page (board `OcLakken`) shows the redacted version beside the original, the list of redactions with their ground, the three steps (original kept, redacted version generated, verified), and Mark redaction verified. Findings are decided in OpenRegister, as `woo-redaction-pipeline` set out; the page links there.
- A redaction counts as verified for publishing only once a person marked it so. `publishBatch()` refuses a batch with a partly public document whose redaction is generated but not marked verified, and names it.

## Rows

| row | name | ours | what this closes |
|---|---|---|---|
| `woo-anonymise` | Remove personal data from documents automatically before they are published. | no | the screen that runs the redaction and the human check before publishing |

Scans without a text layer stay with `woo-redaction-scans-and-text-layer`. The reviewer's public view and the redaction log stay with `woo-review-surface`, which replaces the queue with the public view once a batch is in review.

## Out of scope

- A second redaction path. The page never redacts by itself; it calls the PUT, which calls OpenRegister.
- Deciding findings in OpenCatalogi. OpenRegister owns the per-finding decision (`PATCH /api/entity-relations/{id}`).
- Deck. The queue is a page of its own; the Deck integration note on the batch page goes.
