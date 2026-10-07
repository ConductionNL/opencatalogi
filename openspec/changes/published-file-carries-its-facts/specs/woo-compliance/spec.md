---
status: proposed
---

# Woo compliance

## ADDED Requirements

### Requirement: A file's type comes from its bytes (REQ-PFF-001)

`OCA\OpenCatalogi\Service\FileFacts::mimeTypeOf(\OCP\Files\File $file): ?string` SHALL read at most the first 8 KB of the stored file and answer `IMimeTypeDetector::detectString()` on them, or `null` when the bytes cannot be read. `SitemapService::mapDiwooDocument()` SHALL take `diwoo:format` from that type (mapped to the DiWoo format list by MIME type), `DcatMappingService` SHALL take `dcat:mediaType` from it, and `WooService` SHALL store it as `wooAssessment.fileType`, overwriting a caller's value. When the caller's value differs, the assessment SHALL record `fileTypeClaimed` with the caller's value, and `collectDiwooViolations()` SHALL list the document with reason `type-mismatch`. When the type is `null`, `diwoo:format` SHALL be omitted and the document reported with reason `type-unreadable`; the extension SHALL NOT be used.

#### Scenario: A PDF named .docx is listed as a PDF
<!-- @e2e exclude Sitemap XML contract; proven by SitemapServiceTest::testTheFormatComesFromTheBytesNotTheName, which fails on today's code because the format is the extension. -->

- **GIVEN** a public Woo publication with a file named `besluit.docx` whose bytes are a PDF
- **WHEN** its DiWoo sitemap page is rendered
- **THEN** its `diwoo:format` is the PDF format
- **AND** the validator lists it with `type-mismatch`

#### Scenario: A caller's type is overwritten
<!-- @e2e exclude Service contract; proven by WooServiceTest::testTheAssessmentFileTypeIsTheDetectedTypeAndTheClaimIsKept. -->

- **GIVEN** an assessment saved with `fileType` `text/plain` for a PDF
- **WHEN** the document is assessed
- **THEN** `fileType` is `application/pdf` and `fileTypeClaimed` is `text/plain`

#### Scenario: Unreadable bytes give no format
<!-- @e2e exclude Fail-closed path; proven by SitemapServiceTest::testUnreadableBytesOmitTheFormatAndReportIt. -->

- **GIVEN** a file whose content read throws
- **WHEN** the sitemap page is rendered
- **THEN** the document has no `diwoo:format` and is reported `type-unreadable`

### Requirement: The published file carries its official title (REQ-PFF-002)

`OCA\OpenCatalogi\Service\Publication\EmbeddedTitleWriter::write(\OCP\Files\File $file, string $title, string $identifier): array` SHALL be called by `BatchPublicationWriter::attach()` and by the attachment upload path for a public publication, after `DocumentRedactor::assertPublishable()` has passed. For a PDF it SHALL append one incremental update holding a new Info dictionary with `/Title` and an XMP metadata stream with `dc:title` and `dc:identifier`, and a new trailer pointing at the previous cross-reference with `/Prev`; it SHALL NOT rewrite any earlier byte. For docx, xlsx, pptx, odt, ods and odp it SHALL set the core properties title and identifier through `ZipArchive`. It SHALL skip, with the reason, an encrypted PDF, a signed PDF and a PDF it cannot parse. It SHALL answer `{status: written|skipped|failed, hashBefore, hashAfter, reason?}`, which SHALL be stored on the attachment as `embeddedTitle`. For a redacted document `hashBefore` SHALL equal the verified `anonymizedDocumentHash`, and the first `size(before)` bytes of the published file SHALL equal the verified file.

#### Scenario: The published PDF carries the official title
<!-- @e2e exclude File bytes; proven by EmbeddedTitleWriterTest::testThePdfInfoAndXmpCarryTheTitle, which reads the result back with smalot/pdfparser and fails on today's code because nothing writes it. -->

- **GIVEN** a public publication titled "Besluit parkeerregulering binnenstad 2027" and a PDF whose own title is "Microsoft Word - concept v3"
- **WHEN** the PDF is attached
- **THEN** the published PDF's Info title and XMP `dc:title` read "Besluit parkeerregulering binnenstad 2027"
- **AND** its XMP `dc:identifier` is the publication's identifier

#### Scenario: The approved redaction is untouched
<!-- @e2e exclude Integrity contract with REQ-WRP-001; proven by EmbeddedTitleWriterTest::testTheVerifiedRedactedBytesAreAnExactPrefix. -->

- **GIVEN** a verified redacted PDF with hash H
- **WHEN** the title is written on publication
- **THEN** the published file's first bytes, of the redacted file's length, hash to H
- **AND** `embeddedTitle.hashBefore` is H

#### Scenario: A signed PDF is skipped and said so
<!-- @e2e exclude Fail-closed path; proven by EmbeddedTitleWriterTest::testASignedPdfIsSkippedWithTheReason. -->

- **GIVEN** a PDF carrying a digital signature
- **WHEN** it is attached
- **THEN** its bytes are unchanged and `embeddedTitle` reads `skipped` with reason `signed`

#### Scenario: The officer sees what was written

- **GIVEN** a publication with one PDF written and one signed PDF skipped
- **WHEN** an officer opens the attachments
- **THEN** the first shows "Title written into the file" and the second "Title not written: the file is signed"
