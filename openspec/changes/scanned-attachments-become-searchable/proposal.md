---
kind: code
depends_on: [add-document-content-search, filinq/ocr-trigger-surface]
---

# Proposal: scanned-attachments-become-searchable

## Why

Half the attachments of an older Woo decision are scans: a letter signed on paper, a map, a fax. A reader who searches for a street name finds the typed PDFs and never the scan that names it. Nobody can tell from the file list which attachments search can read.

opencatalogi matrix, row `srch-ocr`, "Find words in scanned documents that were never typed." Own rating no, built.state `none`, owner `ConductionNL/openregister`.

- Own evidence: "openregister DocumentProcessingHandler.php:1328 defers text-layer-less PDFs to OCR, and there is no OCR engine in OR lib; filinq OcrService.php (Tesseract) is called only by FinancialExtractionService.php:1001, not the chunk index opencatalogi searches".
- That evidence is out of date in one respect, read on filinq `development`: `OCA\Filinq\Service\Ocr\OcrExtractionFallback::afterExtraction(int $fileId, object $textExtractor, bool $force): array` now runs OCR on a file whose extraction left no text and hands the words to OpenRegister's `TextExtractionService::extractFromProvidedText(int $fileId, string $text, ?array $entityTypes = null, string $method = 'ocr')`, which exists on OpenRegister `development`. The engine and the seam both exist. Nothing calls them for a publication's attachments.
- No competitor is rated yes: CKAN and DKAN index no file text at all.

## What changes

- When an attachment of a publication is published, OpenCatalogi checks whether OpenRegister extracted text from it. When it did not and the file is a PDF or an image, OpenCatalogi asks filinq's OCR fallback to read it. The text lands in OpenRegister's chunk index, where `add-document-content-search` already searches.
- Each attachment carries a reading state: typed text, read by OCR, waiting, no text, or failed with the reason. The file list shows it in a Text column.
- Only the published file is read. For a partly redacted document that is the redacted copy, so OCR never puts withheld words in the index.
- Without filinq, nothing breaks: the state reads "No text: OCR is not installed" and search behaves as it does today.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| opencatalogi | `srch-ocr` | Find words in scanned documents that were never typed. | no | no caller of the OCR fallback for attachments; no visible reading state |

## Existing work it builds on

- `add-document-content-search` (open change here): the `_content` flag on `GET /api/search` that matches extracted body text, with the same anonymous visibility rules as metadata matches.
- filinq's archived change `2026-09-29-ocr-trigger-surface` (REQ-DDOCR-003, automatic OCR fallback), which built `OcrExtractionFallback`.
- `woo-redaction-scans-and-text-layer` (open change here): gives a redacted scan an invisible text layer. Once that lands, a redacted scan already has typed text and this change records `typed` without OCR.

## Out of scope

- An OCR engine in OpenCatalogi or OpenRegister. filinq owns OCR (ADR-022).
- OCR of files that are not attachments of a publication.
- Handwriting. Tesseract reads print; the state says "No text found" when it reads nothing.
