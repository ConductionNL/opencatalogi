# Tasks: woo-redaction-scans-and-text-layer

Read `openspec/woo-build-rules.md` first. Start once `openregister/anonymisation-image-seam`, `openregister/redaction-release-safeguards` and `woo-review-surface` are merged. Read on their `development` before mocking: OpenRegister `TextExtractionService::extractFromProvidedText()` with `words`, `FileService::anonymizeDocument()` and its `verification` key, the verifier class (`RedactionIrreversibilityVerifier` in `lib/Service/Anonymisation/` per the OpenRegister tasks) and its public method; filinq `OcrService::processFile(int $fileId)` and `addTextLayer(int $fileId)`. If filinq has not added `words` and `addTextLayer()`, stop and raise it with the filinq lane; do not OCR inside OpenCatalogi. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`. Fixtures under `tests/fixtures/scan/`: a scanned one-page letter with a name, a three-page scan, a born-digital PDF.

## 1. Engine verdict (D2)

- [ ] 1.1 Store `verification` on the assessment and gate `isVerified()` on `clean`; map the review-gate 409 to `awaiting-review` (REQ-WRT-002). Verify: `tests/Unit/Service/Woo/DocumentRedactorTest.php::testAnUnverifiableVerdictIsNotVerifiedEvenWhenTheBytesChanged` (fails today), `::testALeakingVerdictStoresRoutesNotValues`, `::testAReviewGateRefusalIsAwaitingReview`.

## 2. Scans

- [ ] 2.1 Add `ScanReader::read()` with the text-layer check and the filinq call (REQ-WRT-001). Verify: `tests/Unit/Service/Woo/ScanReaderTest.php::testAScanIsReadAndItsWordsReachDetection` (fails today), `::testWithoutFilinqAScanIsBlocked`, `::testOcrWithoutWordPositionsIsBlocked`, `::testABornDigitalPdfSkipsOcr`.
- [ ] 2.2 Call it from `DocumentRedactor` before detection, so it has its caller (REQ-WRT-001). Verify: `DocumentRedactorTest::testAScanIsReadBeforeDetection`.
- [ ] 2.3 Contract tests on this side for both filinq methods: the arguments and the return keys this side reads (REQ-WRT-001, REQ-WRT-003). Verify: `ScanReaderTest::testTheFilinqOcrContract` and `::testTheFilinqTextLayerContract`; link the matching filinq tests in the PR body.

## 3. Text layer

- [ ] 3.1 Add the text layer, the second verification and the text check, and block in `assertPublishable()` (REQ-WRT-003). Verify: `ScanReaderTest::testTheRedactedScanGetsATextLayerAndASecondCleanVerdict` (fails today), `DocumentRedactorTest::testARedactedFileWithoutTextIsNotPublishable`, `::testAMissingSecondVerdictBlocks`.
- [ ] 3.2 Order `EmbeddedTitleWriter` before the final verification when it exists (REQ-WRT-003). Verify: `tests/Unit/Service/Woo/BatchPublicationWriterTest.php::testTheTitleIsWrittenBeforeTheFinalVerdict`.
- [ ] 3.3 Show OCR, verdict and text state per document on the batch page (REQ-WRT-003). Verify: `tests/e2e/woo-redaction-scans.spec.ts` "the officer sees the state per document", carrying `@e2e` REQ-WRT-003.
- [ ] 3.4 Live: redact the scanned letter fixture on the dev instance with filinq installed, and paste the verdicts, the OCR confidence and a text extraction of the output (name absent) in the PR body. Verify: the pasted output. (live pass, decision 139)

## 4. Verification

- [ ] 4.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`. The publish gate is central: run the full unit suite once.
- [ ] 4.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] 4.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 4.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Rows 4.14 and 4.15 become `production` only once store releases ship this, the two OpenRegister changes and the filinq OCR additions.
