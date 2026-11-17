# Tasks: Download Service

## 0. Deduplication Check (ADR-001)

- [x] 0.1 Confirm `FileService.createZip()` / `downloadZip()` are not duplicated by OpenRegister's `createObjectFilesZip()` — document findings (expected: no overlap; platform ZIP is flat-file, DownloadService ZIP includes a custom metadata PDF under a domain-specific folder structure)
  - Finding 17 Nov (round 2): no overlap left. `FileService.createZip()` / `downloadZip()` no longer exist in OpenCatalogi; `PublicationService::download()` takes the archive from OpenRegister's `createObjectFilesZip()` and only moves its entries under `Bijlagen/` and adds the metadata PDF at the root.
- [x] 0.2 Confirm `FileService.createPdf()` is not duplicated by any OpenRegister export capability — document findings (expected: no overlap; platform exports are JSON/CSV/Excel, not Twig-rendered branded PDFs)
  - Finding 17 Nov (round 2): no overlap. OpenRegister main has no Twig or mPDF rendering (`grep -rl mpdf lib/` is empty); its exports are JSON/CSV/Excel. `FileService::createPdf()` stays the app's only PDF renderer.

## 1. Bug Fix: Temporary File Cleanup (REQ-DWN-009)

- [x] 1.1 Replace `rmdir('/tmp/mpdf')` in `DownloadService.createPublicationFile()` with a recursive delete (e.g. `array_map('unlink', glob('/tmp/mpdf/*')); rmdir('/tmp/mpdf')`) so cleanup succeeds even when mPDF leaves files in the directory (verified: lib/Service/DownloadService.php, tests/Unit/Service/DownloadServiceTest.php::testCreatePublicationFileRemovesTemporaryFile; temp file removed in a finally block, no rmdir of /tmp/mpdf any more)
- [ ] 1.2 Verify the ZIP temp folder `publicatie_{title}` is recursively removed after `FileService.downloadZip()` in `createPublicationZip()`
  - Obsolete (17 Nov, round 2): `createPublicationZip()` and the `publicatie_{title}` folder are gone; the ZIP is OpenRegister's temp file, which `PublicationService::download()` unlinks on every path.
- [x] 1.3 Add cleanup to error paths (ensure temp files are removed even if an exception is thrown mid-generation) (verified: lib/Service/DownloadService.php, tests/Unit/Service/DownloadServiceTest.php::testCreatePublicationFileRemovesTemporaryFile)

## 2. Modernise Attachment Retrieval (Tech Debt)

- [ ] 2.1 Replace the legacy `getObject()` / `getMultipleObjects()` calls in `DownloadService.publicationAttachments()` with `ObjectService.searchObjectsPaginated()` to align with the current OpenRegister API
  - Left open (17 Nov, round 2): `DownloadService::publicationAttachments()` has no caller left (the download takes files from OpenRegister, and `FederationController::publicationAttachments()` is its own route), so migrating it changes nothing a reader sees. Removing the dead method is the better follow-up.
- [ ] 2.2 Verify that paginated attachment retrieval handles publications with more than one page of attachments
  - Left open with 2.1.

## 3. Controller Wiring (REQ-DWN-001 – REQ-DWN-008)

- [ ] 3.1 Confirm `PublicationsController` has a `download()` action that reads `download` and `saveToNextCloud` query/body parameters and passes them to `DownloadService`
  - Obsolete (17 Nov, round 2): `saveToNextCloud` was removed by DWN-OR-003 (main spec); `download()` takes no output options any more.
- [x] 3.2 Confirm the `/download` sub-resource route is present in `appinfo/routes.php` with correct HTTP method and authentication annotations (`@NoCSRFRequired`, `@PublicPage` if public, or `@NoAdminRequired` if auth-required) (verified: appinfo/routes.php:285, lib/Controller/PublicationsController.php download() with PublicPage and NoCSRFRequired)
- [x] 3.3 Verify CORS headers are present on the download endpoint (required by `config.yaml` rules for public endpoints) (verified: appinfo/routes.php:127 OPTIONS preflight route)

## 4. Unit Tests (ADR-008)

- [x] 4.1 Write `DownloadServiceTest` covering `createPublicationFile()`: (verified: tests/Unit/Service/DownloadServiceTest.php)
  - Happy path: `download=true`, `saveToNextCloud=true`
  - Download-only mode (`saveToNextCloud=false`)
  - Save-only mode (`download=false`)
  - Both-false validation error (expects 500 JSONResponse, no FileService calls)
  - Publication not found (expects 500 JSONResponse from caught exception)
- [ ] 4.2 Write `DownloadServiceTest` covering `createPublicationZip()`:
  - Obsolete (17 Nov, round 2): `createPublicationZip()` no longer exists; `DownloadServiceTest::testCreatePublicationZipMethodDoesNotExist` asserts that.
  - Happy path: publication with 2 attachments produces correct ZIP structure
  - Publication not found
- [x] 4.3 Write `DownloadServiceTest` covering `publicationAttachments()`: (verified: tests/Unit/Service/DownloadServiceTest.php::testPublicationAttachmentsSuccess)
  - Returns attachment list for a publication with 3 attachments
  - Returns empty array when publication has no attachments
- [ ] 4.4 Mock `FileService` and `ObjectService` in all tests — no real file system or database calls
  - Left open: `testRealPublicationTemplateRendersThroughFileService` renders the real template through a real `FileService` on purpose, to catch template breakage.

## 5. Documentation (ADR-009)

- [x] 5.1 Create or update `docs/features/download-service.md` describing:
  - Built 17 Nov (round 2): `docs/features/download-service.md`, written for the current behaviour (ZIP via OpenRegister, no save to Nextcloud), linked from `docs/features/README.md`.
  - Purpose and use cases (archival, WOO export, citizen download)
  - API endpoint: `GET /api/{catalogSlug}/{id}/download?download=true&saveToNextCloud=true`
  - Response formats (JSON with `downloadUrl` + `filename`, or direct file stream)
  - Folder structure in Nextcloud (`Publicaties/({id}) {title}/`)
  - ZIP structure (`{title}.pdf` at root, `Bijlagen/` subfolder)
  - Known limitations (single-publication scope, no content redaction)

## 6. Internationalization (ADR-007)

- [x] 6.1 Audit the `DownloadService` error messages and any UI strings in the controller for untranslated literals
  - 17 Nov (round 2): the controller's messages were already translated; `DownloadService` had three fixed English messages.
- [x] 6.2 Wrap any user-visible error messages with `$this->l->t(...)` in the controller
  - 17 Nov (round 2): `DownloadService` now takes `IL10N` and translates its three fixed messages (`DownloadServiceTest::testTheErrorMessagesAreTranslated`). Messages carried from an exception are left as they were.
- [x] 6.3 Add missing l10n keys via `node scripts/l10n-ai.js add` for both `en` and `nl` locales
  - 17 Nov (round 2): two new keys added to `l10n/en.json` and `l10n/nl.json` (the source of both formats here), then `npm run l10n:build`; `Publication not found` already existed.
- [x] 6.4 Run `npm run check:l10n` and confirm zero MISSING entries related to download-service
  - 17 Nov (round 2): zero MISSING; the server-side scan now finds 169 strings (was 167).

## 7. Spec Sync

- [ ] 7.1 Run `openspec sync --change download-service` (or manually copy `specs/download-service/spec.md` to `openspec/specs/download-service/spec.md`) to promote the change spec to the living specs directory
- [ ] 7.2 Confirm the synced spec matches the implementation by reviewing `lib/Service/DownloadService.php` against each REQ-DWN-NNN requirement

## 8. Verify

- [x] 8.1 All unit tests pass: `./vendor/bin/phpunit tests/Unit/Service/DownloadServiceTest.php`
  - 17 Nov (round 2): `DownloadServiceTest` and both download golden tests pass (56 tests in the Download filter).
- [x] 8.2 `npm run check:l10n` reports zero MISSING and zero UNWRAPPED for download-service strings
  - 17 Nov (round 2): zero MISSING; the 3 UNWRAPPED hits are `actionIconName` icon names in delete dialogs, none a download string.
- [x] 8.3 Manual smoke test: call `/api/{catalogSlug}/{id}/download?download=true` for a publication with at least one attachment and verify the ZIP contains a PDF at the root and the attachment under `Bijlagen/` (live pass, decision 139) Live pass 17 Nov (livepass-opencatalogi): after this pass's fix: the ZIP holds `<title>.pdf` (a real %PDF-1.4) at the root and `Bijlagen/bijlage-livepass.txt`. Before it the attachment sat at the root: OpenRegister's `createObjectFilesZip()` writes files there.
- [ ] 8.4 Manual smoke test: call with `saveToNextCloud=true` and verify the file appears in Nextcloud under `Publicaties/({id}) {title}/` with a working share link (live pass, decision 139)
  - Obsolete: `saveToNextCloud` was removed (DWN-OR-003).
- [ ] 8.5 Verify calling with `download=false&saveToNextCloud=false` returns HTTP 500 with a descriptive message and creates no files (live pass, decision 139)
  - Changed by DWN-OR-004: `download=false` is answered with 400 and no file by `createPublicationFile()` (unit-tested); the public route takes no options, so a live check needs a new route.
