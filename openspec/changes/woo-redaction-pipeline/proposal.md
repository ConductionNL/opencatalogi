---
kind: code
depends_on: [woo-batch-creates-publications]
---

# Proposal: woo-redaction-pipeline

## Why

A partly public (`deels_openbaar`) Woo document had no route to a redacted version. `WooService::createBatch()` wrote every assessment with `anonymizedDocument: ''` and nothing ever filled it. `BatchPublicationWriter` only read the field back. OpenRegister already ships the redaction pipeline: entity detection, the officer's per-finding decision, PDF, DOCX and ODT text replacement, and a strict residual check. OpenCatalogi called none of it.

## What changes

- Assessing a document as `deels_openbaar` hands it to OpenRegister's `FileService::anonymizeDocument()`, the same call `POST /api/files/{id}/anonymize` makes.
- The findings are the ones OpenRegister selects for that endpoint: every detected entity except those the officer rejected with `PATCH /api/entity-relations/{id}` (`skip_anonymization`).
- The result is verified before it counts: a separate file, different bytes from the original, and no residual findings. The verified file id and its SHA-256 are stored on the assessment.
- Publishing refuses a `deels_openbaar` document unless its verified redacted file still exists, is not the original, and still has the verified bytes.
- The batch lists every partly public document that cannot be published yet, with the reason.

## Fail closed

Redaction unavailable, failing, or unverifiable leaves `anonymizedDocument` empty and `redactionStatus: failed`. The publish then refuses with the document's name. The original is never published in its place.

## Out of scope

- A review screen for the findings. The officer decides per finding in OpenRegister.
- Running text extraction. The officer runs it in OpenRegister first.
