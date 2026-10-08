# Design: scanned-attachments-become-searchable

Read at opencatalogi development `0d89f9dd5`, filinq and OpenRegister `development` on 7 October 2026. Board: `OcPublicatieBestanden` on the Zuiddrecht canvas (5NkFW28vZUUij43xzxHg5a): the file list with Name, Size, Labels, Status and Actions. The board has no Text column yet; this change adds it after Status, and the board is the reference for its place.

## Where it lands

| piece | where | what is there now |
|---|---|---|
| attachment publish | `lib/Service/EventService.php` `publishObjectAttachments()`, `lib/Controller/PublicationStateController.php` file publish paths | publishes files, reads no text state |
| OpenRegister extraction | `OCA\OpenRegister\Service\TextExtractionService::getExtractedText(int $fileId): ?string`, `extractFromProvidedText(...)` | extraction on file write; no OCR |
| filinq OCR | `OCA\Filinq\Service\Ocr\OcrExtractionFallback::afterExtraction(int $fileId, object $textExtractor, bool $force): array` | returns `ocr`, `ocrSkipped` or `ocrDetectionPending`; empty when no OCR was needed |
| search | `GET /api/search?_content=true` (`add-document-content-search`) | matches OpenRegister chunks |

## D1. A queued job reads each published attachment

Publishing an attachment queues `AttachmentReadingJob` (a `QueuedJob`) with the file id and the publication id. The publish itself never waits on OCR. The job:

1. asks `getExtractedText($fileId)`; non-empty text means `typed`, done;
2. for a PDF or image without text, resolves filinq's `OcrExtractionFallback` from the container when `IAppManager::isEnabledForUser('filinq')` and the class exists; else `unavailable` with reason "OCR is not installed";
3. calls `afterExtraction($fileId, $textExtractor, false)` and maps its answer: `ocr.ran` true and text ingested means `ocr`; `ocrSkipped` means `failed` with that reason; `ocrDetectionPending` means `waiting`; an empty answer means `none`.

The filinq id is the one in filinq's `appinfo/info.xml` (`filinq`) at the time of building. The lookup is duck-typed; a test asserts the class name exists in filinq's tree so a rename fails loudly here.

## D2. The state is stored per attachment

A schema `attachmentReading` in `lib/Settings/register.d/attachment-reading.json`: `fileId` (integer), `publication` (uuid, `$ref` publication), `state` (`typed`, `ocr`, `waiting`, `none`, `unavailable`, `failed`), `reason` (string), `updatedAt` (date-time). No public read rule. One object per file, upserted by the job.

## D3. Only the published bytes are read

The job reads the file id that is published. When the attachment is a redaction output, that is the redacted copy. The job refuses a file that carries the original-of-a-redaction marker set by the Woo redaction path and records `none` with reason "Original of a redacted document is not indexed". This keeps withheld text out of the chunk index, whatever the search filter does later.

## D4. The file list shows it

The file list (board `OcPublicatieBestanden`) gains a Text column after Status: "Typed text", "Read by OCR", "Reading", "No text found", "No text: OCR is not installed", or "Failed" with the reason in a tooltip. A "Read again" row action re-queues the job with `force` true.

## Declarative or imperative

The schema is declared. The job is imperative: it calls another app and waits on external work (ADR-031 exception: background processing).

## Risks

- OCR is slow on a 300-page scan. The job runs outside the request, and the state shows "Reading" meanwhile.
- filinq renames its class. The existence test in task 1.3 fails in CI before the lookup silently does nothing.
