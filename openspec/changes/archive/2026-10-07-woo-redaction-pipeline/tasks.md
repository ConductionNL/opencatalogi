# Tasks: woo-redaction-pipeline

## 1. Redact

- [x] 1.1 `DocumentRedactor::redact()` calls OpenRegister's redaction and verifies the result (REQ-WRP-001). Verify: `tests/Unit/Service/Woo/DocumentRedactorTest.php`.
- [x] 1.2 `WooService::updateAssessment()` redacts a `deels_openbaar` document and stores the verified file, its hash and the reason on failure (REQ-WRP-001). Verify: `tests/Unit/Service/WooServiceTest.php` (`testAVerifiedRedactionIsWhatGetsPublished`).

## 2. Publish fails closed

- [x] 2.1 `BatchPublicationWriter::assertRedacted()` refuses an unverified, changed or original "redacted version" (REQ-WRP-001). Verify: `testABrokenRedactionNeverPublishesTheOriginal`, `testARedactedFileChangedSinceVerificationBlocksThePublish`, `testARedactedVersionThatIsTheOriginalBlocksThePublish`.

## 3. Officer sees why

- [x] 3.1 `WooService::getBatch()` lists unredacted documents and `WooBatchDetail.vue` shows them (REQ-WRP-002). Verify: `testTheBatchNamesEveryUnredactedDocumentWithItsReason`.
