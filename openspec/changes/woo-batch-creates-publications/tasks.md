# Tasks: woo-batch-creates-publications

## 1. Publish

- [ ] 1.1 `BatchDocumentResolver` (REQ-WBP-002). Verify: `tests/Unit/Service/Woo/BatchDocumentResolverTest.php` with id, absolute path, user path, missing and folder.
- [ ] 1.2 `WooService::publishBatch()` creates the publication and attaches the files; `wooBatch` gains `title` and `wooCategory` (REQ-WBP-001, REQ-WBP-002). Verify: `tests/Unit/Service/WooServicePublishBatchTest.php`, payload validated against the real publication schema.

## 2. Docs

- [ ] 2.1 `openspec validate woo-batch-creates-publications --strict`.
