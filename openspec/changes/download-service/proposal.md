# Download Service

## Why

Publications in OpenCatalogi are stored as structured objects in OpenRegister. Citizens, archivists, and integration partners need to retrieve publication data as portable files — a PDF summary of the publication metadata for reading and archival, and a ZIP bundle containing that PDF alongside all attached documents. Without a dedicated service, callers would have to assemble these files themselves and manage Nextcloud storage interactions directly.

## What Changes

- **`DownloadService`** (`lib/Service/DownloadService.php`) — new service responsible for PDF generation, ZIP assembly, Nextcloud storage, and share link creation for publications
- **`FileService`** (`lib/Service/FileService.php`) — provides the low-level operations: `createPdf()`, `createFolder()`, `updateFile()`, `findShare()`, `getShareLink()`, `createShareLink()`, `createZip()`, `downloadZip()`
- **`lib/Templates/publication.html.twig`** — Twig HTML template rendered into the metadata PDF
- **Publication `/download` sub-resource** — endpoint backed by DownloadService, exposed via `PublicationsController`

## Capabilities

### New Capabilities

- `createPublicationFile`: Render a publication's metadata fields into a branded PDF, optionally persist it to Nextcloud under a structured path, and return a public share link and/or an HTTP download response
- `createPublicationZip`: Assemble a ZIP archive containing the metadata PDF (in the root) and all publication attachments (in a `Bijlagen/` subfolder), then send it as a download response

## Impact

- `lib/Service/DownloadService.php`: Primary service — orchestrates PDF and ZIP generation, Nextcloud storage, and share link management
- `lib/Service/FileService.php`: Low-level Nextcloud file operations and mPDF rendering used by DownloadService
- `lib/Templates/publication.html.twig`: Twig template driving PDF layout and metadata fields
- `appinfo/routes.php`: `/download` sub-resource route under the publications endpoint
- `lib/Controller/PublicationsController.php`: Download action delegates to DownloadService

## Success Criteria

- PDF file contains all metadata fields of the requested publication
- ZIP archive places the metadata PDF in the archive root and all attachments under `Bijlagen/`
- Nextcloud storage path follows the pattern `Publicaties/({id}) {title}/{title}.pdf`
- Public share link is created or retrieved for each file saved to Nextcloud
- Calling with `download=false` and `saveToNextCloud=false` returns a 500 error without generating any files
- Requesting a non-existent publication returns a descriptive error response
- Temporary files in `/tmp/` are removed after each generation run
