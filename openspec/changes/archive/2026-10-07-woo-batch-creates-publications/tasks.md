# Tasks: woo-batch-creates-publications

## 1. Publish

- [x] 1.1 Document resolution in `BatchPublicationWriter::resolveAll()` (REQ-WBP-002). Verify: `tests/Unit/Service/WooServiceTest.php` (`testDocumentReferencesResolveByIdPathAndUserPathButNeverToAFolder`, `testAMissingDocumentStopsThePublishAndCreatesNothing`).
- [x] 1.2 `WooService::publishBatch()` creates the publication and attaches the files; `wooBatch` gains `title` and `wooCategory` (REQ-WBP-001, REQ-WBP-002). Verify: `tests/Unit/Service/WooServiceTest.php`, payload validated against the real publication schema.

## 2. Docs

- [x] 2.1 `openspec validate woo-batch-creates-publications --strict`.
