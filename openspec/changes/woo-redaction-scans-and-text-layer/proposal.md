---
kind: code
depends_on: [openregister/anonymisation-image-seam, openregister/redaction-release-safeguards, woo-review-surface]
---

# Proposal: woo-redaction-scans-and-text-layer

## Why

Many documents a Woo request or an active-disclosure batch collects are scans: a signed letter, a fax, a printed memo. The Woo path cannot redact them today, because it finds names only in text and a scan has none. And a redacted file that cannot be searched fails readers who rely on assistive technology and fails the Woo-index's full-text search.

Rows, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`:

- **4.14** "Text inside a scan is found before it is redacted". Ours: partial, build. Evidence: "openregister does not perform OCR. lib/Service/TextExtractionService.php::extractFromProvidedText(fileId, text, entityTypes, method='ocr') INDEXES text another app extracted from a scan, which is the consuming half. Nothing in the three apps turns a scan into text".
- **4.15** "The redacted document is still machine readable, and its text can be searched". Ours: partial, build. Evidence: "DocumentRedactor.php has the Woo path redacted by OpenRegister PdfTextReplacer ... so a redacted born-digital PDF keeps an extractable, searchable text layer. A scan has no text layer to keep, and nothing tests or refuses a redacted file without one".

Re-read on development at 35999c296. The evidence is right that OpenRegister does no OCR, but filinq does: `OCA\FilinQ\Service\OcrService::processFile(int $fileId): array{text, confidence, ocrProcessed}` runs Tesseract (with hOCR internally) and `POST /apps/filinq/api/ocr/{fileId}` triggers it. It returns no word positions. `DocumentRedactor` counts a redaction as verified when `anonymizeDocument()` changed the bytes and no residual was reported (REQ-WRP-001).

Decision D2 (Ruben, 2026-10-05, option 1): the redaction guarantees move into OpenRegister's engine, and this change makes the Woo pipeline gate on the engine's verdict. The OpenRegister lane wrote both halves (OpenRegister PR #4378): `anonymisation-image-seam` (REQ-AIS-001 to 004: OCR word boxes in through `TextExtractionService::extractFromProvidedText(..., words: [{text, page, box}])`, regions burned into pixels by `ImageRedactionService::redactImage()`, reached by `FileService::anonymizeDocument()`) and `redaction-release-safeguards` (REQ-RRS-001: the written copy is verified on its bytes with verdict `clean`, `leaking` or `unverifiable`, returned as `verification` and stored on the output's metadata; REQ-RRS-002: `anonymizeDocument()` refuses with 409 until a person decided every detection and set a review mark, when `anonymisation.requireReview` is on).

## What changes

- Before detection, a Woo document without a text layer is sent through filinq's OCR with word positions, and the words go to OpenRegister's `extractFromProvidedText()` with `method` `ocr`, so detection sees the scan's text and knows where it is.
- `DocumentRedactor` gates on OpenRegister's verdict, not on changed bytes: a redaction is verified only when `verification.verdict` is `clean`. A 409 from the review gate is shown to the officer as "a person must check the findings first", not as a failure of the file.
- After redaction, a redacted scan gets a searchable text layer: filinq OCRs the burned output and writes an invisible text layer, then OpenRegister's verifier runs again on the final bytes. A redacted file without an extractable text layer is not released.
- The assessment shows, per document, whether OCR ran, the verdict and whether the text layer is present.

## Fail closed

- An image-only document is never treated as redacted until OCR ran and every detection has a position. When OCR is unavailable (filinq absent, Tesseract missing) or returns no positions, the document cannot be redacted and the publish is blocked naming it.
- The verdict is the only proof. `unverifiable` and `leaking` block exactly like a missing redaction.
- A redacted file with no extractable text, or with text on fewer pages than it has, is refused with the reason. When the text layer is added after verification, the verifier runs again on the final bytes; a missing second verdict blocks.

## Out of scope

- The engine work: image detection and burning, the verifier, the review gate (OpenRegister).
- Object detection in images (`anonymiq/object-detection-in-page-images`).
- The review screen (`woo-review-surface`), which shows the findings a person decides on.

## Dependencies

- `openregister/anonymisation-image-seam` and `openregister/redaction-release-safeguards` (OpenRegister, planned in this programme, wave 1; PR #4378).
- `woo-review-surface` (opencatalogi, planned, wave 1): the redaction log and the reviewer flow this builds on.
- filinq, contract this side needs: `OcrService::processFile(int $fileId): array` as today, extended with `words: list<{text, page, box: {x, y, w, h}}>` (relative boxes, as REQ-AIS-001 expects), and a new `OcrService::addTextLayer(int $fileId): array{fileId: int, pages: int, pagesWithText: int}` that writes an invisible OCR text layer onto a PDF in place. The filinq lane's `filinq/image-redaction` or `filinq/redaction-guarantees-from-the-engine` must add both; this spec states the contract and requires a test on each side. Without filinq, scans cannot be redacted on the Woo path and are blocked with that reason; born-digital PDFs are unaffected.
- `published-file-carries-its-facts` (wave 1) appends an incremental update after verification; with this change merged, that writer must run before the final verification, and this change orders it so.

## Wave

Wave 2, after the OpenRegister engine changes of wave 1.

## Decisions

- D2, option 1: implemented as written. The Woo pipeline gates on the engine's verdict and its human gate, and calls filinq only for OCR.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 4.14 | Text inside a scan is found before it is redacted | partial | REQ-WRT-001, scenario "A name in a scanned letter is found and burned out" |
| 4.15 | The redacted document is still machine readable, and its text can be searched | partial | REQ-WRT-003, scenarios "A redacted scan can be searched" and "No text layer, no release" |
