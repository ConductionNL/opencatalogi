# Design: Download Service

## Context

OpenCatalogi stores publications and their attachments in OpenRegister. The publication `/download` sub-resource must return portable files that citizens, archivists, and integration partners can open without the Nextcloud interface. Two file formats are required: a PDF of the publication metadata, and a ZIP bundle containing that PDF plus all attachments (bijlagen). Files may be persisted to the user's Nextcloud storage and shared via public links, or returned directly as HTTP download responses, depending on caller options.

## Goals / Non-Goals

**Goals:**
- Generate a PDF rendering all metadata fields of a publication using a Twig template and mPDF
- Generate a ZIP archive containing the metadata PDF and all publication attachments under a `Bijlagen/` subfolder
- Persist generated files to Nextcloud under a structured, human-readable folder hierarchy
- Create or retrieve public share links for persisted files
- Support configurable output modes: download-only, save-to-Nextcloud-only, or both
- Validate that at least one output mode is enabled before generating any files
- Clean up temporary files in `/tmp/` after each generation run
- Return descriptive error responses when the requested publication does not exist

**Non-Goals:**
- Content transformation or redaction of attachment files
- Bulk download spanning multiple publications simultaneously
- Thumbnail or document preview generation
- WOO-specific metadata formatting (handled by the `woo-transparency` spec)
- Watermarking or digital signing of generated PDFs

## Decisions

### Decision 1: mPDF renders HTML from a Twig template

PDF generation uses `mpdf/mpdf` to convert HTML into PDF. The HTML is produced by rendering `lib/Templates/publication.html.twig` with the publication data array. Separating layout (template) from generation logic (service) lets the PDF format be updated without touching the service, and lets future templates (e.g., per-catalog branding) be plugged in without architectural changes.

### Decision 2: Nextcloud folder hierarchy mirrors publication identity

Saved files are placed under `Publicaties/({publicationId}) {publicationTitle}/`. Including the UUID prefix ensures uniqueness even if two publications share a title; the human-readable title makes the folder navigable in the Nextcloud UI. Attachments are grouped under a `Bijlagen/` subfolder that mirrors the ZIP layout, so the storage tree is consistent with the downloadable archive.

### Decision 3: ObjectService injected as a method parameter, not via constructor

`DownloadService` receives `ObjectService` as a parameter on `createPublicationFile()` and `createPublicationZip()` rather than through constructor injection. This design preserves flexibility: calling contexts (e.g., different controllers or background jobs) may need ObjectService configured for a specific register/schema scope. Constructor injection would lock in one instance for the lifetime of the service.

### Decision 4: Configurable output options with mandatory validation

Each call supplies boolean flags `download` and `saveToNextCloud`. Both may be true simultaneously (save and send). If both are false the service returns a 500 `JSONResponse` immediately — no PDF or ZIP is generated — to surface a configuration error early rather than producing orphaned temp files.

### Decision 5: Temporary files in `/tmp/mpdf/` and `/tmp/publicatie_*/`

mPDF writes its working directory to `/tmp/mpdf/`. ZIP assembly uses a temp folder under `/tmp/publicatie_{title}/`. Both are cleaned up after use. The cleanup of `/tmp/mpdf/` uses `rmdir()`, which silently fails if the directory is non-empty (known limitation — see tasks for the fix).

## File Changes

| File | Change |
|------|--------|
| `lib/Service/DownloadService.php` | Primary service: PDF and ZIP generation, Nextcloud storage, share link management |
| `lib/Service/FileService.php` | Low-level Nextcloud operations: `createPdf()`, `createFolder()`, `updateFile()`, `findShare()`, `getShareLink()`, `createShareLink()`, `createZip()`, `downloadZip()` |
| `lib/Templates/publication.html.twig` | HTML template rendered into the metadata PDF |
| `appinfo/routes.php` | `/download` sub-resource route under publications |
| `lib/Controller/PublicationsController.php` | Download action: parse options, delegate to DownloadService |

## Reuse Analysis (ADR-001)

**Platform capabilities reused — no custom re-implementation:**

| Capability | Provider | Usage |
|------------|----------|-------|
| File storage, folder creation, file write | `FileService` (own, `lib/Service/FileService.php`) | All Nextcloud FS operations are delegated here; `DownloadService` never touches `IRootFolder` directly |
| Share link creation and retrieval | `FileService` → Nextcloud `IManager` | `findShare()` / `getShareLink()` / `createShareLink()` |
| PDF rendering | `FileService.createPdf()` → `mpdf/mpdf` | Invoked with the rendered Twig HTML; result path returned to `DownloadService` |
| ZIP creation and HTTP download | `FileService.createZip()` / `downloadZip()` | Standard archive assembly and streaming |
| Publication and attachment data | `ObjectService` (OpenRegister) | `getObject()` for publication; `getMultipleObjects()` for attachments — no direct DB queries |

**Overlap assessment:**

OpenRegister ships `ExportService` and a `createObjectFilesZip()` method (bulk download of files attached to one or more objects). These are intentionally not reused here because:

1. `DownloadService` generates a **custom branded PDF** from a domain-specific Twig template. The platform export produces generic JSON/CSV/Excel, not a PDF metadata summary.
2. The ZIP structure is domain-specific: the PDF is placed in the archive root; attachments go under `Bijlagen/`. The platform's `createObjectFilesZip()` creates a flat archive of raw file attachments with no metadata document.

No duplication of existing platform capabilities. `FileService` and `ObjectService` are the correct integration points; no new generic infrastructure is needed.

## Seed Data

Not applicable. `DownloadService` introduces no new OpenRegister schemas. It reads existing `Publication` objects and their file attachments via `ObjectService`. Per ADR-001, seed data is only required for changes that introduce or modify OpenRegister schemas.
