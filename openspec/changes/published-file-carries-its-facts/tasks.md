# Tasks: published-file-carries-its-facts

Read `/home/rubenlinde/memcap-work/woo-build/LANE-RULES-BUILD.md` first. Mock `OCP\Files\File` and `OCP\Files\IMimeTypeDetector` against the real OCP interfaces in `vendor/nextcloud/ocp`; for OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`. Do not loosen `DocumentRedactor::isVerified()` or `assertPublishable()`; the writer runs only after them.

## 1. Type from the bytes

- [ ] 1.1 Add `FileFacts::mimeTypeOf()` (REQ-PFF-001). Verify: `tests/Unit/Service/FileFactsTest.php::testAPdfNamedDocxIsDetectedAsPdf`, `::testUnreadableBytesGiveNull`, `::testOnlyTheFirst8KbAreRead`.
- [ ] 1.2 Use it in `SitemapService::mapDiwooDocument()` and `DcatMappingService`, and report `type-mismatch` and `type-unreadable` in `collectDiwooViolations()` (REQ-PFF-001). Verify: `tests/Unit/Service/SitemapServiceTest.php::testTheFormatComesFromTheBytesNotTheName` (fails today), `::testUnreadableBytesOmitTheFormatAndReportIt`; `tests/Unit/Service/DcatMappingServiceTest.php::testTheMediaTypeComesFromTheBytes`.
- [ ] 1.3 Store the detected type and `fileTypeClaimed` in `WooService` (REQ-PFF-001); add `fileTypeClaimed` to `#wooAssessment` with a bumped version. Verify: `tests/Unit/Service/WooServiceTest.php::testTheAssessmentFileTypeIsTheDetectedTypeAndTheClaimIsKept`.

## 2. Title in the file

- [ ] 2.1 Add `EmbeddedTitleWriter` with the PDF incremental update and the office core properties (REQ-PFF-002). Verify: `tests/Unit/Service/Publication/EmbeddedTitleWriterTest.php::testThePdfInfoAndXmpCarryTheTitle` (fails today), `::testTheVerifiedRedactedBytesAreAnExactPrefix`, `::testASignedPdfIsSkippedWithTheReason`, `::testAnEncryptedPdfIsSkipped`, `::testADocxCarriesTheTitleInCoreProperties`, `::testAFailureLeavesTheBytesAndSaysFailed`, using fixture files under `tests/fixtures/pdf/` (a plain, a signed, an encrypted and an incremental-update PDF).
- [ ] 2.2 Call it from `BatchPublicationWriter::attach()` and the attachment upload path for public publications, after the publish gate, and store `embeddedTitle` on the attachment (REQ-PFF-002). Verify: `tests/Unit/Service/Woo/BatchPublicationWriterTest.php::testAttachWritesTheTitleAfterTheGate` and `::testAGateRefusalWritesNothing`, so the writer has its caller and its order.
- [ ] 2.2b When OpenRegister's `redaction-release-safeguards` is merged (its verifier verifies "after every later step", REQ-RRS-001, and `FilePublishingHandler::publishFile()` refuses an output whose verdict is not `clean`), run that verifier again on the written bytes of a redacted file and store the new verdict; a verdict other than `clean` leaves the verified bytes unwritten-to (write nothing, `embeddedTitle` `skipped` with reason `verifier`). `woo-redaction-scans-and-text-layer` orders this writer before its final verification. Verify: `EmbeddedTitleWriterTest::testTheVerifierRunsAgainOnTheWrittenBytes`, skipped with a message while the verifier class is absent.
- [ ] 2.3 Show the `embeddedTitle` status on the attachments list (REQ-PFF-002). Verify: `tests/e2e/published-file-facts.spec.ts` "the officer sees what was written", carrying `@e2e` REQ-PFF-002.
- [ ] 2.4 Live: publish a batch with one redacted PDF on the dev instance, download it, and paste `pdfinfo` (or the parser's read) and the prefix hash check in the PR body (REQ-PFF-002). Verify: the pasted output.

## 3. Verification

- [ ] 3.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`.
- [ ] 3.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran; `composer audit` covers any new library.
- [ ] 3.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 3.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Rows 2.20 and 4.29 become `production` only once a store release ships it.
