---
status: proposed
---

# Scanned attachments become searchable

## ADDED Requirements

### Requirement: A published scan is read by OCR so search finds its words (REQ-SOCR-001)

Publishing an attachment SHALL queue `AttachmentReadingJob`. The job MUST record `typed` when OpenRegister's `getExtractedText()` returns text. For a PDF or image without text it SHALL call filinq's `OcrExtractionFallback::afterExtraction()` when filinq is enabled, so the recognised text reaches OpenRegister's chunk index through `extractFromProvidedText()` with method `ocr`. The publish request MUST NOT wait on the job.

#### Scenario: A street name in a scanned letter is found
<!-- @e2e exclude Needs filinq with Tesseract on the instance; proven by AttachmentReadingJobTest::testAScanWithoutTextIsSentToTheOcrFallback and by the live check of task 4.2. -->

- **GIVEN** a public publication with a scanned PDF that has no text layer and shows the words "Kerkplein 4"
- **WHEN** the attachment is published and the reading job has run
- **THEN** `GET /api/search?_search=Kerkplein&_content=true` returns that attachment

#### Scenario: A typed PDF is not sent to OCR
<!-- @e2e exclude Service rule; proven by AttachmentReadingJobTest::testATypedPdfIsRecordedAsTypedWithoutOcr. -->

- **GIVEN** a published PDF from which OpenRegister extracted text
- **WHEN** the reading job runs
- **THEN** the state is `typed` and the OCR fallback is not called

### Requirement: Without filinq the state says so and nothing breaks (REQ-SOCR-002)

When filinq is not enabled or its `OcrExtractionFallback` class does not exist, the job MUST record `unavailable` with the reason "OCR is not installed" and MUST NOT throw. A failure answer from the fallback MUST be recorded as `failed` with its reason.

#### Scenario: An instance without filinq
<!-- @e2e exclude Service rule; proven by AttachmentReadingJobTest::testWithoutFilinqTheStateIsUnavailable. -->

- **GIVEN** an instance without filinq
- **WHEN** a scanned PDF is published
- **THEN** the attachment's state is `unavailable` with the reason "OCR is not installed"
- **AND** the publish succeeded

### Requirement: Only the published copy of a document is read (REQ-SOCR-003)

The job MUST read only the file that is published. It MUST NOT send the original of a redacted document to OCR, and SHALL record `none` with the reason "Original of a redacted document is not indexed" for such a file, so withheld words never reach the chunk index.

#### Scenario: The original of a redacted scan stays out of the index
<!-- @e2e exclude Fail-closed path; proven by AttachmentReadingJobTest::testTheOriginalOfARedactedDocumentIsNeverRead. -->

- **GIVEN** a scan with a redacted copy that is published and an original that is not
- **WHEN** the reading job is queued for the original
- **THEN** the OCR fallback is not called and the state is `none` with that reason

### Requirement: The file list shows whether search can read each attachment (REQ-SOCR-004)

The file list of a publication SHALL show a Text column after Status with "Typed text", "Read by OCR", "Reading", "No text found", "No text: OCR is not installed" or "Failed", and SHALL offer "Read again" as a row action.

#### Scenario: An editor sees which scan search cannot read
<!-- @e2e exclude Board column not yet built; proven by tests/e2e/attachment-reading.spec.ts once task 3.1 lands. -->

- **GIVEN** a publication with one typed PDF and one scan on an instance without filinq
- **WHEN** an editor opens its file list
- **THEN** the PDF reads "Typed text" and the scan reads "No text: OCR is not installed"
