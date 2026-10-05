---
kind: code
depends_on: []
---

# Proposal: published-file-carries-its-facts

## Why

What a file is and what it is called should be facts the product establishes, not what whoever uploaded it typed. The Woo-index lists each document with its format, taken from our DiWoo record. A reader who downloads the file and opens it later sees whatever title the authoring tool left in it, often "Microsoft Word - concept v3".

Rows, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`:

- **2.20** "A file's technical type is determined by the product from the bytes, not from what the caller said". Ours: partial, production. Evidence: "openregister detects a file's type on upload for its own storage; opencatalogi takes #wooAssessment.fileType from the caller and never re-derives it".
- **4.29** "The product writes the official title into the file's own embedded metadata". Ours: no. Evidence: "openregister FilesController::updateMetadata stores descriptive metadata about a file in the register; nothing writes into the file's own bytes (grep for exiftool, XMP, dc:title, PDF metadata finds nothing in openregister, opencatalogi or integriq)".

Read on development at 35999c296. `SitemapService::mapDiwooDocument()` emits `diwoo:format` from `strtolower($file['extension'])`, the caller's file name. `WooService` stores `wooAssessment.fileType` from the caller. OpenRegister detects bytes with `finfo` only for file properties (`FilePropertyHandler`), not for attachments. A partly public document is published only as its verified redacted file: `DocumentRedactor::isVerified()` compares the stored `anonymizedDocumentHash` with the file's sha256 before `BatchPublicationWriter` attaches it (REQ-WRP-001).

## What changes

- One service, `FileFacts::mimeTypeOf(File $file): string`, reads the first 8 KB of the stored bytes and answers the type through Nextcloud's `IMimeTypeDetector::detectString()`. `diwoo:format`, the DCAT `dcat:mediaType` and `wooAssessment.fileType` take it from there. A caller's type that disagrees is overwritten, and the disagreement is recorded on the assessment and reported by the DiWoo validator.
- When a document is attached to a public publication, after the publish gate (`DocumentRedactor::assertPublishable()`) has passed, the official title and the publication identifier are written into the published file's own metadata: for a PDF the Info dictionary `/Title` and the XMP `dc:title` and `dc:identifier`, as an incremental update appended to the file; for an office file (docx, xlsx, pptx, odt, ods, odp) the core properties `title` and `identifier`. The redacted bytes the verifier approved stay unchanged as the prefix of the published PDF.
- The attachment records what was written: `embeddedTitle` (`written`, `skipped`, `failed`), the hash before and after, and the reason when skipped or failed.

## Fail closed

- A file whose bytes cannot be read gets no type from the caller. Its format is omitted from the DiWoo record and reported, never filled from the extension.
- Writing the title never touches the bytes the redaction verifier approved: the PDF write is an incremental update, and a test asserts the approved bytes are an exact prefix of the published file. An encrypted, signed or damaged PDF is skipped with the reason, never rewritten.
- When writing fails, the document is still published as verified, `embeddedTitle` reads `failed` with the reason, and the officer sees it. Nothing claims the title was written when it was not.

## Out of scope

- Converting files to PDF/A or checking PDF/UA (`filinq/pdfua-verapdf-matterhorn`).
- Writing metadata into image or audio files.
- The upload-side malware scan (`openregister/upload-malware-scan`).

## Dependencies

- None to build. Uses `OCP\Files\IMimeTypeDetector` and PHP's `ZipArchive`. The PDF incremental update is written by a small writer in this app; the builder may use a maintained library instead if it appends rather than rewrites (record the choice in the PR body). `smalot/pdfparser` is added as a dev dependency to read the result back in tests.
- `diwoo-metadata-on-the-publication` (wave 1) makes `title` the official title; this change reads `title` either way.

## Wave

Wave 1. It needs nothing new.

## Decisions

None of D1 to D13 is implemented here. REQ-WRP-001 (the verified redacted file) is respected and not modified.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 2.20 | A file's technical type is determined by the product from the bytes, not from what the caller said | partial | REQ-PFF-001, scenario "A PDF named .docx is listed as a PDF" |
| 4.29 | The product writes the official title into the file's own embedded metadata | no | REQ-PFF-002, scenarios "The published PDF carries the official title" and "The approved redaction is untouched" |
