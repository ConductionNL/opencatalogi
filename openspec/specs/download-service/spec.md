---
status: reviewed
---

# Download Service

## Purpose

The Download Service provides functionality for generating downloadable export files from publications. It creates PDF metadata files from publication data using Twig templates and mPDF, and ZIP archives containing the metadata PDF along with all publication attachments (bijlagen). Files can be saved to the user's Nextcloud storage with share links or sent directly as download responses. This service is used by the publication endpoints to support the `/download` sub-resource.

## Requirements

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| DWN-001 | Generate a PDF file containing all metadata of a publication | Must | Implemented |
| DWN-002 | Save generated PDF to Nextcloud file storage in structured folder hierarchy | Should | Implemented |
| DWN-003 | Create and return share links for saved files | Should | Implemented |
| DWN-004 | Support direct download response for generated PDFs | Should | Implemented |
| DWN-005 | Generate ZIP archive containing metadata PDF and all publication attachments | Must | Implemented |
| DWN-006 | Organize ZIP contents with attachments in a "Bijlagen" subfolder | Must | Implemented |
| DWN-007 | Support configurable options: download-only, save-to-Nextcloud, or both | Should | Implemented |
| DWN-008 | Validate that at least one output option (download or saveToNextCloud) is enabled | Must | Implemented |
| DWN-009 | Clean up temporary files after ZIP/PDF generation | Should | Implemented |
| DWN-010 | Handle missing publications with appropriate error responses | Must | Implemented |

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
