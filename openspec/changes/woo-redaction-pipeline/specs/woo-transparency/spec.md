---
status: proposed
---

# Woo redaction pipeline

## ADDED Requirements

### Requirement: A partly public document is published only as a verified redacted version (REQ-WRP-001)

Assessing a document as `deels_openbaar` SHALL produce its redacted version through OpenRegister's redaction pipeline (`FileService::anonymizeDocument`). The findings SHALL be the ones OpenRegister selects for its own anonymize endpoint, so a finding the officer rejected is never redacted and OpenCatalogi accepts nothing on the officer's behalf. The result SHALL count as verified only when it is a separate file, its bytes differ from the original, and OpenRegister reports no residual findings. The assessment SHALL store the verified file id and its SHA-256.

Publishing SHALL refuse a batch while any `deels_openbaar` document lacks a verified redacted file, or while that file is the original, is gone, or has changed bytes. When redaction is unavailable or fails, the original SHALL NOT be published in its place.

#### Scenario: Redaction breaks
<!-- @e2e exclude Server-side fail-closed contract between OpenCatalogi and OpenRegister's redaction service; a browser cannot break that call on demand. Proven by PHPUnit WooServiceTest::testABrokenRedactionNeverPublishesTheOriginal, which fails on a version that publishes the original. -->

- **GIVEN** an approved batch with a document assessed as `deels_openbaar`
- **AND** OpenRegister's redaction call fails
- **WHEN** the officer publishes the batch
- **THEN** the publish is refused, naming that document
- **AND** no file is attached and the original is not published

#### Scenario: Redaction works
<!-- @e2e exclude Needs a live redaction backend (OpenAnonymiser or Presidio) on the test instance; covered by PHPUnit WooServiceTest::testAVerifiedRedactionIsWhatGetsPublished. -->

- **GIVEN** a document assessed as `deels_openbaar` whose redaction is verified
- **WHEN** the officer publishes the batch
- **THEN** the redacted file is attached in place of the original

#### Scenario: The redacted file changed after verification
<!-- @e2e exclude Server-side integrity check on file bytes; covered by PHPUnit WooServiceTest::testARedactedFileChangedSinceVerificationBlocksThePublish. -->

- **GIVEN** a verified redacted file whose bytes changed since
- **WHEN** the officer publishes the batch
- **THEN** the publish is refused, naming that document

### Requirement: The officer sees why a partly public document cannot be published (REQ-WRP-002)

The batch SHALL list every `deels_openbaar` document without a verified redacted version, each with the reason redaction did not produce one. The batch page SHALL show that list.

#### Scenario: A redaction failed
<!-- @e2e exclude The list is fed by a failed server-side redaction, which a browser run cannot force; covered by PHPUnit WooServiceTest::testTheBatchNamesEveryUnredactedDocumentWithItsReason. -->

- **GIVEN** a batch with a `deels_openbaar` document whose redaction failed
- **WHEN** the officer opens the batch
- **THEN** the page names that document and the reason
