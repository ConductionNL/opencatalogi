# File Management

## Purpose

The File Management service provides all file-related operations for OpenCatalogi: creating folders in Nextcloud, uploading and updating files, deleting files, managing share links, handling file uploads from HTTP requests, generating PDFs via Twig/mPDF, and creating/downloading ZIP archives. It is the foundational file layer used by the DownloadService, auto-publishing system, and WOO sitemap generation.

## Requirements

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| FIL-001 | Create folders in Nextcloud user storage, skip if already exists | Must | Implemented |
| FIL-002 | Upload new files to Nextcloud user storage (fail if file already exists) | Must | Implemented |
| FIL-003 | Update/overwrite existing files, optionally create if not exists | Must | Implemented |
| FIL-004 | Delete files from Nextcloud user storage | Must | Implemented |
| FIL-005 | Create public share links (IShare type 3) for files with configurable permissions | Must | Implemented |
| FIL-006 | Find existing share links for a file path | Must | Implemented |
| FIL-007 | Return full share link URLs including protocol and domain | Must | Implemented |
| FIL-008 | Handle HTTP file uploads via `_file` key in multipart requests | Must | Implemented |
| FIL-009 | Create structured folder hierarchy for publications: `Publicaties/{id} {title}/Bijlagen/` | Must | Implemented |
| FIL-010 | Add file metadata (reference, type, size, title, extension, accessUrl, downloadUrl) to data arrays | Must | Implemented |
| FIL-011 | Generate PDFs using Twig templates and mPDF library | Must | Implemented |
| FIL-012 | Create ZIP archives from folder contents | Must | Implemented |
| FIL-013 | Send ZIP archives as download responses with proper headers | Must | Implemented |
| FIL-014 | Clean up temporary files after ZIP operations | Should | Implemented |
| FIL-015 | Memory limit set to 2048M for large file operations | Should | Implemented |

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
