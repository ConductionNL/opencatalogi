---
status: reviewed
retrofit_extensions:
  - FIL-016
  - FIL-017
  - FIL-018
  - FIL-019
---

# File Management

## Purpose

The File Management service provides all file-related operations for OpenCatalogi: creating folders in Nextcloud, uploading and updating files, deleting files, managing share links, handling file uploads from HTTP requests, generating PDFs via Twig/mPDF, and creating/downloading ZIP archives. It is the foundational file layer used by the DownloadService, auto-publishing system, and WOO sitemap generation.
## Requirements
### Requirement: Create folders in Nextcloud user storage, skip if already exists (FIL-001)
The system MUST create folders in Nextcloud user storage and skip if they already exist.

**Priority:** Must **Status:** Implemented

### Requirement: Upload new files to Nextcloud user storage (fail if file already exists) (FIL-002)
The system MUST upload new files to Nextcloud user storage (and MUST fail if the file already exists).

**Priority:** Must **Status:** Implemented

### Requirement: Update/overwrite existing files, optionally create if not exists (FIL-003)
The system MUST update/overwrite existing files, optionally creating them if not exists.

**Priority:** Must **Status:** Implemented

### Requirement: Delete files from Nextcloud user storage (FIL-004)
The system MUST allow deleting files from Nextcloud user storage.

**Priority:** Must **Status:** Implemented

### Requirement: Create public share links (IShare type 3) for files with configurable permissions (FIL-005)
The system MUST create public share links (IShare type 3) for files with configurable permissions.

**Priority:** Must **Status:** Implemented

### Requirement: Find existing share links for a file path (FIL-006)
The system MUST be able to find existing share links for a file path.

**Priority:** Must **Status:** Implemented

### Requirement: Return full share link URLs including protocol and domain (FIL-007)
The system MUST return full share link URLs including protocol and domain.

**Priority:** Must **Status:** Implemented

### Requirement: Handle HTTP file uploads via `_file` key in multipart requests (FIL-008)
The system MUST handle HTTP file uploads via the `_file` key in multipart requests.

**Priority:** Must **Status:** Implemented

### Requirement: Create structured folder hierarchy for publications: `Publicaties/{id} {title}/Bijlagen/` (FIL-009)
The system MUST create a structured folder hierarchy for publications: `Publicaties/{id} {title}/Bijlagen/`.

**Priority:** Must **Status:** Implemented

### Requirement: Add file metadata (reference, type, size, title, extension, accessUrl, downloadUrl) to data arrays (FIL-010)
The system MUST add file metadata (reference, type, size, title, extension, accessUrl, downloadUrl) to data arrays.

**Priority:** Must **Status:** Implemented

### Requirement: Generate PDFs using Twig templates and mPDF library (FIL-011)
The system MUST generate PDFs using Twig templates and the mPDF library.

**Priority:** Must **Status:** Implemented

### Requirement: Create ZIP archives from folder contents (FIL-012)
The system MUST create ZIP archives from folder contents.

**Priority:** Must **Status:** Implemented

### Requirement: Send ZIP archives as download responses with proper headers (FIL-013)
The system MUST send ZIP archives as download responses with proper headers.

**Priority:** Must **Status:** Implemented

### Requirement: Clean up temporary files after ZIP operations (FIL-014)
The system SHOULD clean up temporary files after ZIP operations.

**Priority:** Should **Status:** Implemented

### Requirement: Memory limit set to 2048M for large file operations (FIL-015)
The system SHOULD set the memory limit to 2048M for large file operations.

**Priority:** Should **Status:** Implemented

### Requirement: Upload files to a publication from the frontend (FIL-016)
The system SHALL provide an `UploadFiles` modal that uploads one or more files to a
publication's OpenRegister files endpoint
(`/index.php/apps/openregister/api/objects/{register}/{schema}/{publicationId}/files`,
PUT for an existing file id, with file content and optional tags), reading the active
publication's register/schema/id from the object store and supporting tag assignment via
`/api/tags`.

**Priority:** Must **Status:** Implemented

#### Scenario: Upload a file to the active publication
- GIVEN the upload modal is open with the active publication selected
- WHEN the user uploads a file
- THEN the file MUST be sent to the publication's OpenRegister `.../files` endpoint
- AND any selected tags MUST be applied

### Requirement: Delete a publication attachment (FIL-017)
The system SHALL provide a `DeleteAttachmentDialog` that deletes the active
`publicationAttachment` by issuing `DELETE` to the OpenRegister files endpoint
`/api/objects/{register}/{schema}/{publicationId}/files/{attachmentId}` (register/schema/id
read from the active publication's `@self`), then refreshes the publication's attachments
and closes the dialog after a short delay.

**Priority:** Must **Status:** Implemented

#### Scenario: Delete an attachment
- GIVEN the active publication and the active attachment
- WHEN the delete-attachment dialog is confirmed
- THEN a `DELETE` request MUST be sent to the `.../files/{attachmentId}` endpoint
- AND the publication's attachments MUST be refreshed afterward

### Requirement: Edit attachment metadata (FIL-018)
The system SHALL provide an `EditAttachmentModal` that updates an attachment's metadata via
`objectStore.updateObject('attachment', id, attachment)` and closes the modal through the
navigation store on completion.

**Priority:** Should **Status:** Implemented

#### Scenario: Edit an attachment
- GIVEN the edit-attachment modal is open
- WHEN the user saves changes
- THEN the attachment MUST be persisted via `objectStore.updateObject('attachment', id, attachment)`

### Requirement: File-selection composable and mass-attachment modal (FIL-019)
The system SHALL provide a `useFileSelection` composable exposing drop-zone state, a file
list, tag setters, duplicate rejection, and reset/open helpers
(`openFileUpload`, `files`, `setFiles`, `setTags`, `reset`, `isOverDropZone`,
`rejectedDuplicates`), and a `MassAttachmentModal` for bulk attachment operations built on
top of it.

**Priority:** Should **Status:** Implemented

#### Scenario: Select files via the composable
@e2e exclude headless drag-and-drop limitation — useFileSelection composable state (isOverDropZone, rejectedDuplicates) is internal reactive state not observable via DOM snapshot in headless Playwright; covered by Jest composable unit test.
- GIVEN a component using `useFileSelection`
- WHEN files are dropped or chosen
- THEN the composable's file list MUST update and duplicates MUST be rejected

## Architecture

### Key Components

| Component | Location | Responsibility |
|-----------|----------|----------------|
| FileService | `lib/Service/FileService.php` | All file operations for OpenCatalogi |

### Constructor Dependencies

| Dependency | Type | Purpose |
|------------|------|---------|
| IUserSession | `OCP\IUserSession` | Get current user for file operations |
| LoggerInterface | `Psr\Log\LoggerInterface` | Error and info logging |
| IRootFolder | `OCP\Files\IRootFolder` | Access Nextcloud file storage |
| IManager | `OCP\Share\IManager` | Create and manage share links |

## API Methods

### Folder Operations

| Method | Parameters | Returns | Description |
|--------|-----------|---------|-------------|
| `createFolder()` | `string $folderPath` | `bool` | Creates folder, returns false if exists |
| `getPublicationFolderName()` | `string $publicationId, string $publicationTitle` | `string` | Returns `({id}) {title}` format |

### File Operations

| Method | Parameters | Returns | Description |
|--------|-----------|---------|-------------|
| `uploadFile()` | `mixed $content, string $filePath` | `bool` | Creates new file, false if exists |
| `updateFile()` | `mixed $content, string $filePath, bool $createNew` | `bool` | Overwrites file, optionally creates |
| `deleteFile()` | `string $filePath` | `bool` | Deletes file, false if not found |

### Share Operations

| Method | Parameters | Returns | Description |
|--------|-----------|---------|-------------|
| `findShare()` | `string $path, ?int $shareType = 3` | `?IShare` | Find existing share for a file |
| `createShareLink()` | `string $path, ?int $shareType = 3, ?int $permissions = null` | `string` | Create share link URL |
| `getShareLink()` | `IShare $share` | `string` | Get full URL from IShare object |

### Upload Handling

| Method | Parameters | Returns | Description |
|--------|-----------|---------|-------------|
| `handleFile()` | `IRequest $request, array $data` | `JSONResponse\|array` | Full upload flow with folder creation |
| `AddFileInfoToData()` | `array $data, array $uploadedFile, string $filePath` | `array` | Enriches data with file metadata |

### PDF and ZIP

| Method | Parameters | Returns | Description |
|--------|-----------|---------|-------------|
| `createPdf()` | `string $twigTemplate, array $context` | `Mpdf` | Renders HTML template to PDF |
| `createZip()` | `string $inputFolder, string $tempZip` | `?string` | Creates ZIP archive, null on success |
| `downloadZip()` | `string $tempZip, ?string $inputFolder` | `void` | Sends ZIP as download response |

## Scenarios

### Scenario: Create share link for a file
- GIVEN a file exists at `Publicaties/(abc-123) Report/Report.pdf`
- WHEN createShareLink() is called with that path
- THEN the user folder is accessed via IRootFolder
- AND the file is retrieved by path
- AND a new IShare is created with shareType=3 (public), permissions=1 (read-only)
- AND the share link URL is returned: `{protocol}://{host}/index.php/s/{token}`

### Scenario: Handle file upload from request
- GIVEN an HTTP request with a file in the `_file` field
- AND headers `Publication-Id: abc-123` and `Publication-Title: Report`
- WHEN handleFile() is called
- THEN checkUploadedFile() validates the upload (exists, no errors)
- AND folders are created: `Publicaties/`, `Publicaties/(abc-123) Report/`, `Publicaties/(abc-123) Report/Bijlagen/`
- AND the file is uploaded to `Publicaties/(abc-123) Report/Bijlagen/{filename}`
- AND AddFileInfoToData() adds reference, type, size, title, extension, accessUrl, downloadUrl to the data array

### Scenario: Generate PDF from Twig template
- GIVEN a Twig template `publication.html.twig` exists in `lib/Templates/`
- WHEN createPdf() is called with template name and context data
- THEN Twig renders the HTML
- AND mPDF converts it to PDF using `/tmp/mpdf/` as temp directory
- AND the Mpdf object is returned for output (caller chooses FILE, DOWNLOAD, etc.)

### Scenario: File already exists on upload
- GIVEN a file already exists at the target path
- WHEN uploadFile() is called with the same path
- THEN the method returns false (file not overwritten)
- AND a warning is logged

### Scenario: Share link permissions
- GIVEN createShareLink() is called with shareType=3 (public link)
- AND permissions=null
- THEN permissions default to 1 (read-only) for public share types
- AND for non-public share types, permissions default to 31 (all)

## Dependencies

- **Nextcloud IRootFolder** - File system access via user folders
- **Nextcloud IManager** (Share) - Create, find, and manage file shares
- **Nextcloud IUserSession** - Current user context for file operations
- **mPDF** - PDF generation library (requires `/tmp/mpdf/` directory with write permissions)
- **Twig** - Template engine for PDF HTML rendering (`lib/Templates/` directory)
- **ZipArchive** - PHP extension for ZIP archive creation

## Notes

- The file has `ini_set('memory_limit', '2048M')` at the top of the namespace declaration, which increases PHP memory limit to 2GB for large file operations.
- The `getCurrentDomain()` method uses `$_SERVER['HTTPS']` and `$_SERVER['HTTP_HOST']` directly rather than Nextcloud's IURLGenerator, which may not work correctly in CLI/cron contexts.
- Share permissions use Nextcloud constants: 1=read, 2=update, 4=create, 8=delete, 16=share, 31=all.
- There is a typo in the use statement: `use Mpdf\MpMpdfdf;` (should be `use Mpdf\Mpdf;`), but the actual `Mpdf` class usage in createPdf() works because it references the class directly.
