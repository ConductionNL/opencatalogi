---
status: proposed
---

# Woo transparency

## ADDED Requirements

### Requirement: A scan is read before it is redacted (REQ-WRT-001)

Before `DocumentRedactor` asks OpenRegister to detect and redact a `deels_openbaar` document, it SHALL determine whether the file has a text layer (PDF text on every page, or an office file with text). When it has none, `OCA\OpenCatalogi\Service\Woo\ScanReader::read(int $fileId): array` SHALL call filinq's `OCA\FilinQ\Service\OcrService::processFile($fileId)` and, when the answer carries `ocrProcessed` true and a `words` list, pass `text` and `words` to OpenRegister's `TextExtractionService::extractFromProvidedText()` with `method` `ocr`, then run detection. The assessment SHALL record `ocr: {ran, confidence, words}`. When filinq is not installed, `processFile()` throws, `ocrProcessed` is false, or `words` is missing, the document SHALL be marked `redactionStatus` failed with the reason, and the publish SHALL be blocked naming it.

#### Scenario: A name in a scanned letter is found and burned out
<!-- @e2e exclude Server-side pipeline across three apps; proven by ScanReaderTest::testAScanIsReadAndItsWordsReachDetection (fails on today's code because nothing OCRs) and by the live check in tasks 2.3. -->

- **GIVEN** a `deels_openbaar` document that is a scanned letter naming "Jan de Vries", with filinq and Tesseract installed
- **WHEN** the officer redacts it and a person decides the finding
- **THEN** filinq's OCR ran, the name was detected with its position, and the redacted output has the name burned into the pixels

#### Scenario: No OCR, no redaction
<!-- @e2e exclude Fail-closed path; proven by ScanReaderTest::testWithoutFilinqAScanIsBlocked and ::testOcrWithoutWordPositionsIsBlocked. -->

- **GIVEN** a scanned document and filinq not installed
- **WHEN** the officer redacts it
- **THEN** it is marked failed with "this document is a scan and no OCR is available", and the batch cannot be published

### Requirement: The Woo pipeline trusts only the engine's verdict and its human gate (REQ-WRT-002)

`DocumentRedactor` SHALL store the `verification` OpenRegister's `anonymizeDocument()` returns (REQ-RRS-001) on the assessment and SHALL set `redactionStatus` verified only when `verification.verdict` is `clean`. `DocumentRedactor::isVerified()` SHALL additionally require the stored verdict `clean`, besides the hash check of REQ-WRP-001. A 409 from OpenRegister's review gate (REQ-RRS-002) SHALL be stored as `redactionStatus` `awaiting-review` with OpenRegister's message, and the assessment page SHALL say a person must check the findings first. A verdict of `leaking` or `unverifiable` SHALL be stored as failed with the routes and counts, never with the values.

#### Scenario: Changed bytes are not enough
<!-- @e2e exclude Server-side gate; proven by DocumentRedactorTest::testAnUnverifiableVerdictIsNotVerifiedEvenWhenTheBytesChanged, which fails on today's code because changed bytes count as verified. -->

- **GIVEN** an anonymise answer whose bytes changed and whose `verification.verdict` is `unverifiable`
- **WHEN** the redaction completes
- **THEN** the document is not verified and publishing is blocked

#### Scenario: A person must check first
<!-- @e2e exclude Server-side gate; proven by DocumentRedactorTest::testAReviewGateRefusalIsAwaitingReview. -->

- **GIVEN** `anonymisation.requireReview` on and undecided findings
- **WHEN** the officer redacts the document
- **THEN** its status is `awaiting-review` with OpenRegister's message and nothing was written

### Requirement: A redacted file keeps a searchable text layer, or it is not released (REQ-WRT-003)

After a verified redaction of a document that had no text layer, `ScanReader` SHALL call filinq's `OcrService::addTextLayer(int $fileId)` on the output, then run OpenRegister's verifier again on the final bytes with the redacted values, and store the second verdict. Every published redacted file, born-digital or scanned, SHALL pass a text check: text extractable on every page that had text or OCR words before redaction. `DocumentRedactor::assertPublishable()` SHALL block a document that fails the text check, that lacks the second verdict where one is required, or whose second verdict is not `clean`. `EmbeddedTitleWriter` (from `published-file-carries-its-facts`), when present, SHALL write before this final verification.

#### Scenario: A redacted scan can be searched
<!-- @e2e exclude File bytes; proven by ScanReaderTest::testTheRedactedScanGetsATextLayerAndASecondCleanVerdict, which fails on today's code because no text layer is added. -->

- **GIVEN** a scanned three-page document redacted on page two
- **WHEN** the redaction completes
- **THEN** text is extractable on all three pages, the redacted name is not, and the second verdict is `clean`

#### Scenario: No text layer, no release
<!-- @e2e exclude Fail-closed path; proven by DocumentRedactorTest::testARedactedFileWithoutTextIsNotPublishable. -->

- **GIVEN** a redacted output with text on one of three pages
- **WHEN** the batch is published
- **THEN** the publish is refused naming the document and "no searchable text on pages 2 and 3"

#### Scenario: The officer sees the state per document

- **GIVEN** a batch with a scanned and a born-digital document
- **WHEN** the officer opens the batch
- **THEN** each document shows whether OCR ran, the verdict, and whether its text can be searched
