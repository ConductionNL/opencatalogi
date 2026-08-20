# Tasks: Download Service

## 0. Deduplication Check (ADR-001)

- [ ] 0.1 Confirm `FileService.createZip()` / `downloadZip()` are not duplicated by OpenRegister's `createObjectFilesZip()` — document findings (expected: no overlap; platform ZIP is flat-file, DownloadService ZIP includes a custom metadata PDF under a domain-specific folder structure)
- [ ] 0.2 Confirm `FileService.createPdf()` is not duplicated by any OpenRegister export capability — document findings (expected: no overlap; platform exports are JSON/CSV/Excel, not Twig-rendered branded PDFs)

## 1. Bug Fix: Temporary File Cleanup (REQ-DWN-009)

- [ ] 1.1 Replace `rmdir('/tmp/mpdf')` in `DownloadService.createPublicationFile()` with a recursive delete (e.g. `array_map('unlink', glob('/tmp/mpdf/*')); rmdir('/tmp/mpdf')`) so cleanup succeeds even when mPDF leaves files in the directory
- [ ] 1.2 Verify the ZIP temp folder `publicatie_{title}` is recursively removed after `FileService.downloadZip()` in `createPublicationZip()`
- [ ] 1.3 Add cleanup to error paths (ensure temp files are removed even if an exception is thrown mid-generation)

## 2. Modernise Attachment Retrieval (Tech Debt)

- [ ] 2.1 Replace the legacy `getObject()` / `getMultipleObjects()` calls in `DownloadService.publicationAttachments()` with `ObjectService.searchObjectsPaginated()` to align with the current OpenRegister API
- [ ] 2.2 Verify that paginated attachment retrieval handles publications with more than one page of attachments

## 3. Controller Wiring (REQ-DWN-001 – REQ-DWN-008)

- [ ] 3.1 Confirm `PublicationsController` has a `download()` action that reads `download` and `saveToNextCloud` query/body parameters and passes them to `DownloadService`
- [ ] 3.2 Confirm the `/download` sub-resource route is present in `appinfo/routes.php` with correct HTTP method and authentication annotations (`@NoCSRFRequired`, `@PublicPage` if public, or `@NoAdminRequired` if auth-required)
- [ ] 3.3 Verify CORS headers are present on the download endpoint (required by `config.yaml` rules for public endpoints)

## 4. Unit Tests (ADR-008)

- [ ] 4.1 Write `DownloadServiceTest` covering `createPublicationFile()`:
  - Happy path: `download=true`, `saveToNextCloud=true`
  - Download-only mode (`saveToNextCloud=false`)
  - Save-only mode (`download=false`)
  - Both-false validation error (expects 500 JSONResponse, no FileService calls)
  - Publication not found (expects 500 JSONResponse from caught exception)
- [ ] 4.2 Write `DownloadServiceTest` covering `createPublicationZip()`:
  - Happy path: publication with 2 attachments produces correct ZIP structure
  - Publication not found
- [ ] 4.3 Write `DownloadServiceTest` covering `publicationAttachments()`:
  - Returns attachment list for a publication with 3 attachments
  - Returns empty array when publication has no attachments
- [ ] 4.4 Mock `FileService` and `ObjectService` in all tests — no real file system or database calls

## 5. Documentation (ADR-009)

- [ ] 5.1 Create or update `docs/features/download-service.md` describing:
  - Purpose and use cases (archival, WOO export, citizen download)
  - API endpoint: `GET /api/{catalogSlug}/{id}/download?download=true&saveToNextCloud=true`
  - Response formats (JSON with `downloadUrl` + `filename`, or direct file stream)
  - Folder structure in Nextcloud (`Publicaties/({id}) {title}/`)
  - ZIP structure (`{title}.pdf` at root, `Bijlagen/` subfolder)
  - Known limitations (single-publication scope, no content redaction)

## 6. Internationalization (ADR-007)

- [ ] 6.1 Audit the `DownloadService` error messages and any UI strings in the controller for untranslated literals
- [ ] 6.2 Wrap any user-visible error messages with `$this->l->t(...)` in the controller
- [ ] 6.3 Add missing l10n keys via `node scripts/l10n-ai.js add` for both `en` and `nl` locales
- [ ] 6.4 Run `npm run check:l10n` and confirm zero MISSING entries related to download-service

## 7. Spec Sync

- [ ] 7.1 Run `openspec sync --change download-service` (or manually copy `specs/download-service/spec.md` to `openspec/specs/download-service/spec.md`) to promote the change spec to the living specs directory
- [ ] 7.2 Confirm the synced spec matches the implementation by reviewing `lib/Service/DownloadService.php` against each REQ-DWN-NNN requirement

## 8. Verify

- [ ] 8.1 All unit tests pass: `./vendor/bin/phpunit tests/Unit/Service/DownloadServiceTest.php`
- [ ] 8.2 `npm run check:l10n` reports zero MISSING and zero UNWRAPPED for download-service strings
- [ ] 8.3 Manual smoke test: call `/api/{catalogSlug}/{id}/download?download=true` for a publication with at least one attachment and verify the ZIP contains a PDF at the root and the attachment under `Bijlagen/`
- [ ] 8.4 Manual smoke test: call with `saveToNextCloud=true` and verify the file appears in Nextcloud under `Publicaties/({id}) {title}/` with a working share link
- [ ] 8.5 Verify calling with `download=false&saveToNextCloud=false` returns HTTP 500 with a descriptive message and creates no files
