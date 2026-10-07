# Tasks: scanned-attachments-become-searchable

Search only finds OCR text once `add-document-content-search` is merged; tasks 1 to 3 can be built before it, task 4.2 cannot. Check the signatures on `development` before building: OpenRegister `TextExtractionService::getExtractedText(int $fileId): ?string` and `extractFromProvidedText(int $fileId, string $text, ?array $entityTypes = null, string $method = 'ocr'): void`; filinq `OcrExtractionFallback::afterExtraction(int $fileId, object $textExtractor, bool $force): array`. If one has moved, stop and name it in the PR body.

## 1. The reading job

- [ ] 1.1 Add `lib/Settings/register.d/attachment-reading.json` with the `attachmentReading` schema of design D2, a `slug`, titles and descriptions on every property, and no public read rule (REQ-SOCR-001). Verify: `tests/Unit/Settings/AttachmentReadingSchemaTest.php`.
- [ ] 1.2 Add `lib/BackgroundJob/AttachmentReadingJob.php` with the steps of design D1 (REQ-SOCR-001, REQ-SOCR-002). Verify: `tests/Unit/BackgroundJob/AttachmentReadingJobTest.php::testATypedPdfIsRecordedAsTypedWithoutOcr`, `::testAScanWithoutTextIsSentToTheOcrFallback`, `::testWithoutFilinqTheStateIsUnavailable`, `::testAnOcrSkippedAnswerIsRecordedAsFailed`.
- [ ] 1.3 Add a test that the class `OCA\Filinq\Service\Ocr\OcrExtractionFallback` and its `afterExtraction` method are the names the job looks up, read from a pinned list in the test with the filinq commit it was checked at (REQ-SOCR-002). Verify: `tests/Unit/BackgroundJob/FilinqOcrContractTest.php`.
- [ ] 1.4 Refuse the original of a redacted document as design D3 (REQ-SOCR-003). Find the marker the Woo redaction path sets with `git grep -n "redact" lib/Service/Woo lib/Service/DocumentRedactor.php`; if there is none, stop and name it in the PR body. Verify: `AttachmentReadingJobTest::testTheOriginalOfARedactedDocumentIsNeverRead`.

## 2. The caller

- [ ] 2.1 Queue the job from every attachment publish path: `EventService::publishObjectAttachments()` and the file publish in `PublicationStateController`. List the paths with `git grep -n "createShareLink\|publishFile" lib` and name each in the PR body (REQ-SOCR-001). Verify: one test per path asserting the job is queued, so the job has a caller.

## 3. The file list

- [ ] 3.1 Add the Text column and the "Read again" row action to the publication's file list (board `OcPublicatieBestanden`), reading `attachmentReading` objects (REQ-SOCR-004). Verify: `tests/e2e/attachment-reading.spec.ts` "an editor sees which scan search cannot read", carrying `@e2e` REQ-SOCR-004.
- [ ] 3.2 Add the strings to `l10n/` (en, nl). Verify: `npm run check:l10n`.

## 4. Verification

- [ ] 4.1 `openspec validate scanned-attachments-become-searchable --strict`, `composer check:strict`, `npm run lint`.
- [ ] 4.2 Live, on an instance with filinq and Tesseract: publish a scan, wait for the job, and paste the `_content` search answer that finds it in the PR body.
- [ ] 4.3 Set `srch-ocr` in `openspec/parity/capabilities.json` to `built` with this change, and correct its evidence (filinq's fallback and OpenRegister's seam exist).
