---
status: reviewed
---

# Download Service

## Purpose

@e2e exclude pure backend service spec — all scenarios test PHP service methods (PDF generation via Twig/mPDF, ZIP archive creation, Nextcloud file storage, share-link creation) with no browser-observable surface; covered by PHPUnit instead.

The Download Service provides functionality for generating downloadable export files from publications. It creates PDF metadata files from publication data using Twig templates and mPDF, and ZIP archives containing the metadata PDF along with all publication attachments (bijlagen). Files can be saved to the user's Nextcloud storage with share links or sent directly as download responses. This service is used by the publication endpoints to support the `/download` sub-resource.

## Requirements

### Requirement: Generate a PDF file containing all metadata of a publication (DWN-001)
The system MUST generate a PDF file containing all metadata of a publication.

**Priority:** Must **Status:** Implemented

### Requirement: Save generated PDF to Nextcloud file storage in structured folder hierarchy (DWN-002)
The system SHOULD save generated PDFs to Nextcloud file storage in a structured folder hierarchy.

**Priority:** Should **Status:** Implemented

### Requirement: Create and return share links for saved files (DWN-003)
The system SHOULD create and return share links for saved files.

**Priority:** Should **Status:** Implemented

### Requirement: Support direct download response for generated PDFs (DWN-004)
The system SHOULD support direct download response for generated PDFs.

**Priority:** Should **Status:** Implemented

### Requirement: Generate ZIP archive containing metadata PDF and all publication attachments (DWN-005)
The system MUST generate a ZIP archive containing the metadata PDF and all publication attachments.

**Priority:** Must **Status:** Implemented

### Requirement: Organize ZIP contents with attachments in a "Bijlagen" subfolder (DWN-006)
The system MUST organize ZIP contents with attachments in a "Bijlagen" subfolder.

**Priority:** Must **Status:** Implemented

### Requirement: Support configurable options: download-only, save-to-Nextcloud, or both (DWN-007)
The system SHOULD support configurable options: download-only, save-to-Nextcloud, or both.

**Priority:** Should **Status:** Implemented

### Requirement: Validate that at least one output option (download or saveToNextCloud) is enabled (DWN-008)
The system MUST validate that at least one output option (download or saveToNextCloud) is enabled.

**Priority:** Must **Status:** Implemented

### Requirement: Clean up temporary files after ZIP/PDF generation (DWN-009)
The system SHOULD clean up temporary files after ZIP/PDF generation.

**Priority:** Should **Status:** Implemented

### Requirement: Handle missing publications with appropriate error responses (DWN-010)
The system MUST handle missing publications with appropriate error responses.

**Priority:** Must **Status:** Implemented

## Architecture

### Key Components

| Component | Location | Responsibility |
|-----------|----------|----------------|
| DownloadService | `lib/Service/DownloadService.php` | PDF/ZIP generation, Nextcloud storage, share link creation |
| FileService | `lib/Service/FileService.php` | Low-level file operations, folder creation, share management, PDF rendering |

### Folder Structure in Nextcloud

```
Publicaties/
  ({publicationId}) {publicationTitle}/
    {publicationTitle}.pdf          <-- Metadata PDF
    Bijlagen/
      attachment1.pdf               <-- Publication attachments
      attachment2.docx
```

### ZIP Archive Structure

```
publicatie_{title}.zip
  {title}.pdf                       <-- Metadata PDF
  Bijlagen/
    attachment1.pdf
    attachment2.docx
```

## Scenarios

### Scenario: Generate publication metadata PDF
- GIVEN a publication with ID "abc-123" and title "Klimaatbeleid 2024"
- WHEN createPublicationFile() is called with download=true, saveToNextCloud=true
- THEN the publication data is fetched via ObjectService
- AND FileService.createPdf() renders the `publication.html.twig` template with publication data
- AND mPDF generates the PDF in `/tmp/mpdf/`
- AND the file is saved to `Publicaties/(abc-123) Klimaatbeleid 2024/Klimaatbeleid 2024.pdf`
- AND a public share link is created or retrieved
- AND the response includes `{ downloadUrl: "{shareLink}/download", filename: "Klimaatbeleid 2024.pdf" }`

### Scenario: Generate publication ZIP archive
- GIVEN a publication with ID "abc-123" has 2 attachments
- WHEN createPublicationZip() is called
- THEN the publication data and metadata PDF are generated first
- AND publicationAttachments() fetches all attachment objects
- AND prepareZip() creates a temp folder structure with the PDF and downloads all attachment files
- AND FileService.createZip() creates the ZIP archive
- AND FileService.downloadZip() sends the ZIP as a download response
- AND temporary files are cleaned up

### Scenario: Options validation
- GIVEN createPublicationFile() is called with download=false and saveToNextCloud=false
- THEN a 500 JSONResponse is returned with error message
- AND no PDF is generated

### Scenario: Publication not found
- GIVEN a request for publication ID "nonexistent"
- WHEN getPublicationData() queries ObjectService
- THEN a DoesNotExistException or NotFoundException is caught
- AND a 500 JSONResponse with error details is returned

## Dependencies

- **FileService** - createPdf(), createFolder(), updateFile(), findShare(), getShareLink(), createShareLink(), createZip(), downloadZip()
- **ObjectService** (passed as parameter) - getObject(), getMultipleObjects() for publication and attachment data
- **mPDF** - PDF rendering library (`mpdf/mpdf`)
- **Twig** - Template engine for PDF HTML generation (`lib/Templates/publication.html.twig`)
- **Nextcloud file system** - Storage and share link creation via IRootFolder and IManager

## Notes

- The DownloadService receives ObjectService as a method parameter rather than a constructor dependency, likely for flexibility in different calling contexts.
- The `publicationAttachments()` method uses the legacy `getObject()`/`getMultipleObjects()` pattern from an older ObjectService API, suggesting this code predates the current searchObjectsPaginated approach.
- Temporary files are created in `/tmp/` and should be cleaned up, but the `rmdir('/tmp/mpdf')` call in createPublicationFile() may fail if the directory is not empty.
