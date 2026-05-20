---
status: reviewed
---

# File Management Specification

## Purpose

Defines the complete contract for `FileService` (`lib/Service/FileService.php`), the foundational file layer in OpenCatalogi. The service is responsible for all Nextcloud file-system operations: folder management, file CRUD, share link lifecycle, HTTP upload handling, publication folder hierarchy, file metadata enrichment, PDF generation, and ZIP archive creation and download. It is consumed by `DownloadService`, the auto-publishing pipeline, and WOO sitemap generation.

## Context

OpenCatalogi stores publication attachments in a structured folder hierarchy inside the Nextcloud user's storage (`Publicaties/{id} {title}/Bijlagen/`). Files are made publicly accessible via Nextcloud's share link mechanism (IShare type 3). PDFs are generated on-demand from Twig templates using the mPDF library. ZIP archives bundle an entire publication folder for bulk download. All operations run in the context of the authenticated Nextcloud user retrieved via `IUserSession`.

**Relation to existing specs:**
- `download-service`: Consumes `FileService.createZip()` and `FileService.downloadZip()` to deliver ZIP bundles to clients
- `auto-publishing`: Calls `FileService.handleFile()` and `FileService.createShareLink()` as part of the publication workflow
- `woo-compliance`: Uses `FileService.createPdf()` to generate WOO disclosure PDFs from Twig templates

**Relation to OpenRegister platform (ADR-001):**
- OpenRegister's built-in `FileService` (platform layer) covers generic upload, download, share, and file-tab UI. OpenCatalogi's `FileService` extends this with domain-specific logic: publication folder hierarchy, PDF generation, and ZIP download — none of which are provided by the platform layer.
- No custom file upload UI components are introduced; `CnObjectSidebar → CnFilesTab` serves the UI layer.

## Requirements

### Requirement: REQ-FIL-001 — Folder creation MUST be idempotent

`createFolder()` MUST create the target folder in Nextcloud user storage and return `true`. If the folder already exists it MUST return `false` without error, leaving the existing folder untouched.

#### Scenario: Create a new folder

- GIVEN the current user's Nextcloud storage does not contain the folder `Publicaties/`
- WHEN `createFolder('Publicaties/')` is called
- THEN the folder is created in the user's storage
- AND the method returns `true`

#### Scenario: Skip creation when folder already exists

- GIVEN the folder `Publicaties/` already exists in the user's storage
- WHEN `createFolder('Publicaties/')` is called
- THEN the folder is NOT created again
- AND the method returns `false`
- AND no exception or error is raised

---

### Requirement: REQ-FIL-002 — File upload MUST fail non-destructively when the target path is occupied

`uploadFile()` MUST create a new file at the given path and return `true`. If a file already exists at that path it MUST return `false` and log a warning; it MUST NOT overwrite the existing file.

#### Scenario: Upload a new file

- GIVEN no file exists at `Publicaties/(abc-123) Jaarverslag/Bijlagen/rapport.pdf`
- WHEN `uploadFile($content, 'Publicaties/(abc-123) Jaarverslag/Bijlagen/rapport.pdf')` is called
- THEN the file is created with the given content
- AND the method returns `true`

#### Scenario: Skip upload when file already exists

- GIVEN a file already exists at `Publicaties/(abc-123) Jaarverslag/Bijlagen/rapport.pdf`
- WHEN `uploadFile($content, 'Publicaties/(abc-123) Jaarverslag/Bijlagen/rapport.pdf')` is called
- THEN the existing file is NOT modified
- AND the method returns `false`
- AND a warning is logged

---

### Requirement: REQ-FIL-003 — File update MUST overwrite existing files and optionally create new ones

`updateFile()` MUST overwrite the file at the target path with the new content and return `true`. When `$createNew = true` and the file does not exist, it MUST create the file. When `$createNew = false` and the file does not exist, it MUST return `false`.

#### Scenario: Overwrite an existing file

- GIVEN a file exists at `Publicaties/(abc-123) Jaarverslag/Bijlagen/rapport.pdf`
- WHEN `updateFile($newContent, 'Publicaties/(abc-123) Jaarverslag/Bijlagen/rapport.pdf', false)` is called
- THEN the file is overwritten with `$newContent`
- AND the method returns `true`

#### Scenario: Create a new file when createNew is true

- GIVEN no file exists at `Publicaties/(def-456) Besluit/Bijlagen/bijlage.docx`
- WHEN `updateFile($content, 'Publicaties/(def-456) Besluit/Bijlagen/bijlage.docx', true)` is called
- THEN the file is created with `$content`
- AND the method returns `true`

#### Scenario: Refuse to create when createNew is false and file is absent

- GIVEN no file exists at `Publicaties/(def-456) Besluit/Bijlagen/bijlage.docx`
- WHEN `updateFile($content, 'Publicaties/(def-456) Besluit/Bijlagen/bijlage.docx', false)` is called
- THEN no file is created
- AND the method returns `false`

---

### Requirement: REQ-FIL-004 — File deletion MUST return false gracefully when the file is absent

`deleteFile()` MUST delete the file at the given path and return `true`. If no file exists at that path it MUST return `false` without raising an exception.

#### Scenario: Delete an existing file

- GIVEN a file exists at `Publicaties/(abc-123) Jaarverslag/Bijlagen/rapport.pdf`
- WHEN `deleteFile('Publicaties/(abc-123) Jaarverslag/Bijlagen/rapport.pdf')` is called
- THEN the file is removed from storage
- AND the method returns `true`

#### Scenario: Return false when file is not found

- GIVEN no file exists at `Publicaties/(abc-123) Jaarverslag/Bijlagen/ontbreekt.pdf`
- WHEN `deleteFile('Publicaties/(abc-123) Jaarverslag/Bijlagen/ontbreekt.pdf')` is called
- THEN the method returns `false`
- AND no exception is raised

---

### Requirement: REQ-FIL-005 — Share link creation MUST apply correct default permissions per share type

`createShareLink()` MUST create a public share (IShare) for the given path and return the full share URL. When `$permissions` is `null`, it MUST default to `1` (read-only) for public share types (`$shareType = 3`) and to `31` (all) for other share types.

#### Scenario: Create a public share link with default read-only permission

- GIVEN a file exists at `Publicaties/(abc-123) Jaarverslag/rapport.pdf`
- WHEN `createShareLink('Publicaties/(abc-123) Jaarverslag/rapport.pdf', 3, null)` is called
- THEN a new IShare is created with `shareType = 3` and `permissions = 1`
- AND the method returns the full URL `{protocol}://{host}/index.php/s/{token}`

#### Scenario: Create a non-public share link with default all-permissions

- GIVEN a file exists at `Publicaties/(abc-123) Jaarverslag/rapport.pdf`
- WHEN `createShareLink('Publicaties/(abc-123) Jaarverslag/rapport.pdf', 1, null)` is called
- THEN a new IShare is created with `shareType = 1` and `permissions = 31`
- AND the method returns the full share URL

#### Scenario: Create a share link with explicit permissions

- GIVEN a file exists at `Publicaties/(abc-123) Jaarverslag/rapport.pdf`
- WHEN `createShareLink('Publicaties/(abc-123) Jaarverslag/rapport.pdf', 3, 17)` is called
- THEN a new IShare is created with `shareType = 3` and `permissions = 17`

---

### Requirement: REQ-FIL-006 — Share link discovery MUST return an existing share or null

`findShare()` MUST search the shares for the given path and share type and return the first matching `IShare` object. If no matching share exists it MUST return `null`.

#### Scenario: Find an existing share

- GIVEN a public share (type 3) exists for `Publicaties/(abc-123) Jaarverslag/rapport.pdf`
- WHEN `findShare('Publicaties/(abc-123) Jaarverslag/rapport.pdf', 3)` is called
- THEN the matching `IShare` object is returned

#### Scenario: Return null when no share exists

- GIVEN no share exists for `Publicaties/(abc-123) Jaarverslag/rapport.pdf`
- WHEN `findShare('Publicaties/(abc-123) Jaarverslag/rapport.pdf', 3)` is called
- THEN `null` is returned

---

### Requirement: REQ-FIL-007 — Share link URL construction MUST use IURLGenerator

`getShareLink()` MUST construct and return the full URL for a share using `IURLGenerator` to resolve the protocol and host. It MUST NOT read `$_SERVER['HTTPS']` or `$_SERVER['HTTP_HOST']` directly.

#### Scenario: Construct share URL via IURLGenerator

- GIVEN an IShare object with token `abc123`
- WHEN `getShareLink($share)` is called
- THEN `IURLGenerator::getAbsoluteURL()` is used to build the URL
- AND the returned URL has the form `https://example.nl/index.php/s/abc123`
- AND the method does NOT access `$_SERVER` superglobals

#### Scenario: Share URL is correct in CLI/cron context

- GIVEN the application is running as a background job (no HTTP request context)
- AND `IURLGenerator` is configured with the Nextcloud base URL
- WHEN `getShareLink($share)` is called
- THEN the correct absolute URL is returned
- AND no empty or malformed URL is produced (as would occur with raw `$_SERVER` access)

---

### Requirement: REQ-FIL-008 — HTTP file uploads MUST be fully handled via the handleFile() flow

`handleFile()` MUST extract the uploaded file from the `_file` key of the request, validate it, create the publication folder hierarchy, persist the file, enrich the data array with metadata, and return the enriched data array. If validation fails it MUST return a `JSONResponse` with an appropriate error.

#### Scenario: Handle a valid multipart file upload

- GIVEN an HTTP request with a file in the `_file` field
- AND headers `Publication-Id: abc-123` and `Publication-Title: Jaarverslag 2024`
- WHEN `handleFile($request, $data)` is called
- THEN `checkUploadedFile()` validates the file (no errors, size > 0)
- AND `createFolder()` is called for `Publicaties/`, `Publicaties/(abc-123) Jaarverslag 2024/`, and `Publicaties/(abc-123) Jaarverslag 2024/Bijlagen/`
- AND the file is uploaded to `Publicaties/(abc-123) Jaarverslag 2024/Bijlagen/{filename}`
- AND `AddFileInfoToData()` enriches `$data` with `reference`, `type`, `size`, `title`, `extension`, `accessUrl`, `downloadUrl`
- AND the enriched data array is returned

#### Scenario: Reject an invalid upload

- GIVEN an HTTP request where the `_file` field contains an upload with errors
- WHEN `handleFile($request, $data)` is called
- THEN `checkUploadedFile()` returns a validation error
- AND a `JSONResponse` with an error message is returned
- AND no folder or file is created

---

### Requirement: REQ-FIL-009 — Publication folder names MUST follow the `({id}) {title}` convention

`getPublicationFolderName()` MUST return a string in the format `({publicationId}) {publicationTitle}` for use as the folder name component within the `Publicaties/` tree.

#### Scenario: Compose a publication folder name

- GIVEN `$publicationId = 'abc-123'` and `$publicationTitle = 'Jaarverslag 2024'`
- WHEN `getPublicationFolderName('abc-123', 'Jaarverslag 2024')` is called
- THEN the returned string is `(abc-123) Jaarverslag 2024`

#### Scenario: Full path is constructed from folder name

- GIVEN `getPublicationFolderName()` returns `(abc-123) Jaarverslag 2024`
- THEN the full attachment path is `Publicaties/(abc-123) Jaarverslag 2024/Bijlagen/`

---

### Requirement: REQ-FIL-010 — File metadata enrichment MUST add all required fields to the data array

`AddFileInfoToData()` MUST return the `$data` array extended with `reference`, `type`, `size`, `title`, `extension`, `accessUrl`, and `downloadUrl` derived from the uploaded file and its storage path.

#### Scenario: Enrich data array with file metadata

- GIVEN `$uploadedFile` with `name = 'rapport.pdf'`, `type = 'application/pdf'`, `size = 204800`
- AND `$filePath = 'Publicaties/(abc-123) Jaarverslag/Bijlagen/rapport.pdf'`
- AND a public share link `https://example.nl/index.php/s/xyz789` exists for the file
- WHEN `AddFileInfoToData($data, $uploadedFile, $filePath)` is called
- THEN the returned array contains:
  - `reference` — the storage path or share token
  - `type` — `application/pdf`
  - `size` — `204800`
  - `title` — `rapport`
  - `extension` — `pdf`
  - `accessUrl` — the share link URL
  - `downloadUrl` — the direct download URL

---

### Requirement: REQ-FIL-011 — PDF generation MUST render a Twig template and return an Mpdf object

`createPdf()` MUST load the named Twig template from `lib/Templates/`, render it with the provided context, convert the resulting HTML to PDF using mPDF with `/tmp/mpdf/` as its temp directory, and return the `Mpdf` object. The caller chooses the output mode (`FILE`, `DOWNLOAD`, etc.).

#### Scenario: Generate a PDF from a Twig template

- GIVEN a Twig template `publicatie.html.twig` exists in `lib/Templates/`
- AND context `['title' => 'Jaarverslag 2024', 'body' => '...']` is provided
- WHEN `createPdf('publicatie.html.twig', $context)` is called
- THEN Twig renders the template with the context to an HTML string
- AND mPDF converts the HTML to a PDF document using `/tmp/mpdf/` for temp files
- AND an `Mpdf` object is returned

#### Scenario: mPDF uses correct temp directory

- GIVEN `/tmp/mpdf/` exists and is writable
- WHEN `createPdf()` is called
- THEN the `Mpdf` instance is constructed with `tempDir = '/tmp/mpdf/'`
- AND no write-permission error occurs during PDF generation

---

### Requirement: REQ-FIL-012 — ZIP archive creation MUST package the contents of the input folder

`createZip()` MUST create a ZIP archive at `$tempZip` containing all files from `$inputFolder`. On success it MUST return `null`. On failure it MUST return an error string.

#### Scenario: Create a ZIP from a folder

- GIVEN the folder `Publicaties/(abc-123) Jaarverslag/` contains `rapport.pdf` and `bijlage.docx`
- AND `$tempZip = '/tmp/abc-123.zip'`
- WHEN `createZip('Publicaties/(abc-123) Jaarverslag/', '/tmp/abc-123.zip')` is called
- THEN a ZIP archive is created at `/tmp/abc-123.zip` containing both files
- AND the method returns `null`

#### Scenario: Return error string when ZIP creation fails

- GIVEN the input folder does not exist or is unreadable
- WHEN `createZip($inputFolder, $tempZip)` is called
- THEN the method returns a non-null error string describing the failure
- AND no partial ZIP file is left at `$tempZip`

---

### Requirement: REQ-FIL-013 — ZIP download MUST send the archive with correct HTTP headers

`downloadZip()` MUST output the ZIP file at `$tempZip` as an HTTP download response with `Content-Type: application/zip`, `Content-Disposition: attachment; filename="..."`, and `Content-Length` headers set.

#### Scenario: Send ZIP as download response

- GIVEN a ZIP archive exists at `/tmp/abc-123.zip`
- WHEN `downloadZip('/tmp/abc-123.zip', 'Publicaties/(abc-123) Jaarverslag/')` is called
- THEN the response includes `Content-Type: application/zip`
- AND the response includes `Content-Disposition: attachment; filename="abc-123.zip"` (or equivalent)
- AND the response includes `Content-Length` matching the file size
- AND the ZIP file contents are streamed to the client

---

### Requirement: REQ-FIL-014 — Temporary ZIP files MUST be deleted after the download response

After `downloadZip()` streams the ZIP file, the temporary archive at `$tempZip` MUST be deleted from disk to prevent temp directory accumulation.

#### Scenario: Clean up temp file after download

- GIVEN `downloadZip('/tmp/abc-123.zip', ...)` has been called and the response has been sent
- THEN the file `/tmp/abc-123.zip` no longer exists on disk

---

### Requirement: REQ-FIL-015 — PHP memory limit MUST be raised to 2048M for large file operations

The `FileService` MUST set `ini_set('memory_limit', '2048M')` to accommodate large file operations such as PDF rendering and ZIP archive creation, which may exceed the default PHP memory limit.

#### Scenario: Memory limit is applied

- GIVEN the default PHP `memory_limit` is `128M`
- WHEN the `FileService` class is loaded
- THEN `ini_set('memory_limit', '2048M')` has been applied
- AND subsequent file operations are not interrupted by memory-limit errors for files up to several hundred megabytes

## Current Implementation Status

All 15 requirements are **implemented** in `lib/Service/FileService.php`. Two known defects are outstanding:

1. **REQ-FIL-007 defect** — `getCurrentDomain()` reads `$_SERVER['HTTPS']` and `$_SERVER['HTTP_HOST']` directly. This fails in CLI/cron contexts where no HTTP request is present. Fix: inject and use `IURLGenerator::getAbsoluteURL()`.
2. **REQ-FIL-011 typo** — `use Mpdf\MpMpdfdf;` should be `use Mpdf\Mpdf;`. The class is referenced directly in `createPdf()` so it currently resolves at runtime, but the malformed use statement is a latent defect.

## Dependencies

- **`OCP\Files\IRootFolder`** — Nextcloud file system access via the current user's folder
- **`OCP\Share\IManager`** — Create and query Nextcloud share links
- **`OCP\IUserSession`** — Current user context for all file operations
- **`OCP\IURLGenerator`** — Absolute URL construction for share links (replaces raw `$_SERVER` access)
- **`mPDF`** (`mpdf/mpdf`) — PDF generation from rendered HTML; requires `/tmp/mpdf/` writable at runtime
- **`Twig\Environment`** — Template rendering for PDF HTML generation; templates in `lib/Templates/`
- **`ZipArchive`** — PHP core extension for ZIP archive creation
