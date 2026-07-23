---
status: reviewed
---

# Download Service Specification

## Purpose

The Download Service provides functionality for generating downloadable export files from publications. It creates PDF metadata files from publication data using Twig templates and mPDF, and ZIP archives containing the metadata PDF along with all publication attachments (bijlagen). Files can be saved to the user's Nextcloud storage with share links or sent directly as download responses. This service is used by the publication endpoints to support the `/download` sub-resource.

## Context

Publications in OpenCatalogi are accessed primarily through the Nextcloud admin UI or the public catalog API. Downloading a portable, self-contained representation of a publication — a PDF of its metadata and a ZIP with all its files — is a common need for archival, WOO compliance, and integration handoffs. The Download Service encapsulates this capability behind two methods: `createPublicationFile()` for PDF generation and `createPublicationZip()` for full archive assembly.

## Requirements

### REQ-DWN-001: Publication metadata PDF generation

The system MUST generate a PDF file containing all metadata of a publication on demand.

#### Scenario: Render publication metadata to PDF

- GIVEN a publication with ID `abc-123` and title `Klimaatbeleid 2024`
- WHEN `createPublicationFile()` is called with that publication ID
- THEN `ObjectService` is queried for the publication data
- AND `FileService.createPdf()` renders the `publication.html.twig` template with the publication data
- AND mPDF produces a PDF file in `/tmp/mpdf/`
- AND the resulting PDF contains all metadata fields of the publication

#### Scenario: PDF filename matches publication title

- GIVEN a publication with title `Klimaatbeleid 2024`
- WHEN the PDF is generated
- THEN the filename MUST be `Klimaatbeleid 2024.pdf`

---

### REQ-DWN-002: Save generated PDF to Nextcloud storage

The system SHOULD persist the generated PDF to the user's Nextcloud storage in a structured folder hierarchy.

#### Scenario: Save PDF to structured Nextcloud path

- GIVEN a publication with ID `abc-123` and title `Klimaatbeleid 2024`
- WHEN `createPublicationFile()` is called with `saveToNextCloud=true`
- THEN `FileService.createFolder()` creates the path `Publicaties/(abc-123) Klimaatbeleid 2024/` if it does not already exist
- AND `FileService.updateFile()` writes `Klimaatbeleid 2024.pdf` into that folder
- AND the file is accessible in the Nextcloud file system under the user's storage

#### Scenario: Folder name includes both ID and title

- GIVEN any publication
- WHEN its folder is created in Nextcloud
- THEN the folder name MUST follow the pattern `({publicationId}) {publicationTitle}`
- AND the folder MUST be nested under the root `Publicaties/` directory

---

### REQ-DWN-003: Share link creation for saved files

The system SHOULD create or retrieve a public share link for each file saved to Nextcloud.

#### Scenario: Retrieve existing share link

- GIVEN a PDF file that was previously saved to Nextcloud and already has a share
- WHEN `createPublicationFile()` is called again for the same publication
- THEN `FileService.findShare()` finds the existing share
- AND `FileService.getShareLink()` returns the existing link without creating a duplicate

#### Scenario: Create new share link when none exists

- GIVEN a PDF file saved to Nextcloud for the first time
- WHEN no existing share is found via `FileService.findShare()`
- THEN `FileService.createShareLink()` creates a new public share
- AND the share link is included in the response

#### Scenario: Response includes share link and filename

- GIVEN `createPublicationFile()` with `saveToNextCloud=true`
- WHEN the file is saved and share link is resolved
- THEN the response MUST include `{ "downloadUrl": "{shareLink}/download", "filename": "{title}.pdf" }`

---

### REQ-DWN-004: Direct download response for generated PDFs

The system SHOULD support returning a generated PDF directly as an HTTP download response.

#### Scenario: Return PDF as download response

- GIVEN `createPublicationFile()` called with `download=true`
- WHEN the PDF is generated
- THEN the HTTP response MUST include appropriate `Content-Type: application/pdf` and `Content-Disposition: attachment` headers
- AND the PDF binary content MUST be returned in the response body

#### Scenario: Download and save can both be enabled

- GIVEN `createPublicationFile()` called with `download=true` and `saveToNextCloud=true`
- WHEN the PDF is generated
- THEN the file MUST be saved to Nextcloud AND returned as a download response in a single call

---

### REQ-DWN-005: ZIP archive containing metadata PDF and all publication attachments

The system MUST generate a ZIP archive that bundles the publication metadata PDF with all its attachments.

#### Scenario: ZIP includes metadata PDF and all attachments

- GIVEN a publication with ID `abc-123` and 2 attachments (`bijlage1.pdf`, `rapport.docx`)
- WHEN `createPublicationZip()` is called
- THEN the publication metadata PDF is generated first
- AND `publicationAttachments()` retrieves all attachment objects linked to the publication
- AND all attachment files are downloaded to a local temp directory
- AND `FileService.createZip()` assembles the archive
- AND the resulting ZIP file contains `Klimaatbeleid 2024.pdf` and both attachments

#### Scenario: Attachment files are fetched from their storage locations

- GIVEN a publication with attachments stored in Nextcloud or via external URLs
- WHEN `createPublicationZip()` assembles the temp folder
- THEN each attachment file MUST be fetched and written to the local temp directory before ZIP creation

---

### REQ-DWN-006: ZIP contents organized with attachments in a "Bijlagen" subfolder

The system MUST organize the ZIP archive so that the metadata PDF is in the root and all attachments are grouped under a `Bijlagen/` subfolder.

#### Scenario: Metadata PDF at ZIP root

- GIVEN a ZIP archive generated by `createPublicationZip()`
- THEN the metadata PDF MUST be located at `{title}.pdf` in the ZIP root

#### Scenario: Attachments in Bijlagen subfolder

- GIVEN a ZIP archive generated by `createPublicationZip()` with 3 attachments
- THEN all 3 attachment files MUST be located under `Bijlagen/` within the ZIP
- AND no attachment file MUST appear in the ZIP root

#### Scenario: ZIP filename includes publication title

- GIVEN a publication with title `Klimaatbeleid 2024`
- WHEN the ZIP is generated
- THEN the ZIP filename MUST be `publicatie_Klimaatbeleid 2024.zip`

---

### REQ-DWN-007: Configurable output options

The system SHOULD support three output modes: download-only, save-to-Nextcloud-only, and both simultaneously.

#### Scenario: Download-only mode

- GIVEN `createPublicationFile()` called with `download=true` and `saveToNextCloud=false`
- WHEN the PDF is generated
- THEN the PDF MUST be returned as an HTTP download response
- AND no file MUST be written to Nextcloud storage

#### Scenario: Save-to-Nextcloud-only mode

- GIVEN `createPublicationFile()` called with `download=false` and `saveToNextCloud=true`
- WHEN the PDF is generated
- THEN the file MUST be saved to Nextcloud
- AND a share link MUST be returned in the response
- AND no streaming download response is sent

#### Scenario: Both modes simultaneously

- GIVEN `createPublicationFile()` called with `download=true` and `saveToNextCloud=true`
- THEN the file MUST be saved to Nextcloud AND returned as a download response

---

### REQ-DWN-008: At least one output option must be enabled

The system MUST validate that at least one output option (`download` or `saveToNextCloud`) is enabled before generating any files.

#### Scenario: Both options false returns error immediately

- GIVEN `createPublicationFile()` called with `download=false` and `saveToNextCloud=false`
- THEN a `JSONResponse` with HTTP status 500 MUST be returned immediately
- AND the response body MUST contain a descriptive error message
- AND NO PDF or ZIP generation MUST occur

#### Scenario: Valid options proceed without error

- GIVEN `createPublicationFile()` called with at least one of `download=true` or `saveToNextCloud=true`
- THEN the service MUST proceed to generate the PDF
- AND no validation error is returned

---

### REQ-DWN-009: Clean up temporary files after generation

The system SHOULD remove all temporary files created during PDF and ZIP generation after each operation completes.

#### Scenario: mPDF temp directory cleaned up after PDF generation

- GIVEN `createPublicationFile()` completes successfully
- THEN the mPDF working directory `/tmp/mpdf/` MUST be removed
- AND no temporary PDF intermediary files MUST remain in `/tmp/`

#### Scenario: ZIP temp folder cleaned up after ZIP generation

- GIVEN `createPublicationZip()` completes and the ZIP has been sent or saved
- THEN the temporary assembly folder `/tmp/publicatie_{title}/` MUST be removed
- AND all files within that temp folder (the metadata PDF copy, attachment copies) MUST be deleted

#### Scenario: Cleanup is performed even when only PDF is generated

- GIVEN `createPublicationFile()` is called (no ZIP)
- THEN the mPDF temp directory MUST be cleaned up regardless of whether `download` or `saveToNextCloud` was requested

---

### REQ-DWN-010: Handle missing publications with appropriate error responses

The system MUST return a descriptive error response when the requested publication does not exist.

#### Scenario: Publication not found returns 500 error

- GIVEN `createPublicationFile()` or `createPublicationZip()` is called with a non-existent publication ID
- WHEN `ObjectService.getObject()` throws `DoesNotExistException` or `NotFoundException`
- THEN the exception MUST be caught
- AND a `JSONResponse` with HTTP status 500 MUST be returned
- AND the response body MUST include the error message from the exception

#### Scenario: No file generation occurs for missing publication

- GIVEN a request for a publication ID that does not exist
- THEN NO temporary files MUST be created
- AND NO Nextcloud storage operations MUST be attempted
- AND the error response MUST be returned immediately

## Non-Requirements

- This spec does NOT cover bulk download of multiple publications in a single ZIP
- This spec does NOT cover content transformation, redaction, or watermarking of attachment files
- This spec does NOT cover WOO-specific formatting of the metadata PDF (handled by `woo-transparency` spec)
- This spec does NOT cover PDF thumbnail or preview generation
- This spec does NOT define the HTML layout of the Twig template (implementation detail)

## Dependencies

- `FileService` (`lib/Service/FileService.php`) — all Nextcloud file system and mPDF operations
- `ObjectService` (OpenRegister) — publication and attachment data retrieval
- `mpdf/mpdf` — PHP PDF rendering library
- `Twig` — HTML template engine for PDF generation (`lib/Templates/publication.html.twig`)
- Nextcloud `IRootFolder` — file system access (via `FileService`)
- Nextcloud `IManager` — share link management (via `FileService`)
