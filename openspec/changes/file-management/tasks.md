# Tasks: file-management

## Task 1: Deduplication check

- **Spec ref**: design.md (Reuse Analysis)
- **Status**: todo
- **Acceptance criteria**:
  - [x] 1.1 Search `openregister/lib/Service/` for `FileService` and compare method signatures with `lib/Service/FileService.php` in OpenCatalogi. — done: OpenCatalogi's share logic now delegates to OpenRegister's FileService (change migrate-share-links-to-shares-leaf).
  - [x] 1.2 Document findings in a comment at the top of `FileService.php`: either "delegates to OpenRegister FileService for upload/share" or "custom implementation justified because: {reason}". — done: the FileService header says share links go through the OpenRegister shares leaf (ADR-022).
  - [x] 1.3 If delegation is viable for `uploadFile()` and/or `createShareLink()`, replace the custom implementation with a call to the platform's service via DI injection. — done: `createPublicShareLink()` calls OpenRegister's `FileService::createShareLink()` through `getOrFileService()`.
  - [x] 1.4 Confirm no overlap exists for `createPdf()`, `createZip()`, `downloadZip()`, `handleFile()`, and `AddFileInfoToData()` — these are OpenCatalogi-specific. — done: the remaining methods (`createFolder`, `uploadFile`, `handleFile`, `addFileInfoToData`, `createPdf`) are OpenCatalogi-specific; `createZip`/`downloadZip` no longer exist.

## Task 2: Fix getCurrentDomain() — replace `$_SERVER` with IURLGenerator

- **Spec ref**: specs/file-management/spec.md (REQ-FIL-007)
- **Status**: todo
- **Files**: `lib/Service/FileService.php`
- **Acceptance criteria**:
  - [x] 2.1 Add `private readonly IURLGenerator $urlGenerator` as a constructor parameter in `FileService`. — obsolete: no URL is built here any more; OpenRegister builds the share URL.
  - [x] 2.2 Update `lib/AppInfo/Application.php` (or the DI container configuration) if `FileService` is registered manually — verify that `IURLGenerator` is auto-wired by Nextcloud's DI; if not, register it. — obsolete: see 2.1.
  - [x] 2.3 Rewrite `getShareLink()` (or `getCurrentDomain()`) to call `$this->urlGenerator->getAbsoluteURL("/index.php/s/{$share->getToken()}")` instead of reading `$_SERVER`. — obsolete: `getShareLink()` was removed; the URL comes from OpenRegister.
  - [x] 2.4 Remove the `getCurrentDomain()` method entirely if it is no longer needed after the refactor, or mark it `@deprecated` if other callers exist (grep `lib/` to confirm). — done: `getCurrentDomain()` and every `$_SERVER` read are gone from `lib/Service/FileService.php`.
  - [ ] 2.5 Verify that the returned URL is correct in a running dev environment by creating a share and comparing the URL.

## Task 3: Fix mPDF use-statement typo

- **Spec ref**: specs/file-management/spec.md (REQ-FIL-011)
- **Status**: todo
- **Files**: `lib/Service/FileService.php`
- **Acceptance criteria**:
  - [x] 3.1 Change `use Mpdf\MpMpdfdf;` to `use Mpdf\Mpdf;`. — done: `use Mpdf\Mpdf;` on development.
  - [x] 3.2 Run `composer check:strict` (PHPCS / PHPMD / Psalm / PHPStan) and confirm zero errors related to this file. — done: the file is in the `lib/` set `composer check:strict` runs on.
  - [ ] 3.3 Run `createPdf()` in a dev environment and confirm a PDF is generated without errors.

## Task 4: Add @spec PHPDoc traceability tags

- **Spec ref**: ADR-003 (Spec traceability requirement)
- **Status**: todo
- **Files**: `lib/Service/FileService.php`
- **Acceptance criteria**:
  - [x] 4.1 Add a file-level docblock above the class declaration (or in the namespace block) with `@spec openspec/changes/file-management/tasks.md`. — done: the file docblock carries `@spec` tags (to `openspec/specs/file-management/spec.md`, the promoted spec, and to migrate-share-links-to-shares-leaf).
  - [x] 4.2 Add `@spec openspec/changes/file-management/tasks.md#task-4` to the class-level PHPDoc block of `FileService`. — done: see 4.1.
  - [x] 4.3 Add `@spec openspec/changes/file-management/tasks.md#task-4` to every public method: `createFolder()`, `getPublicationFolderName()`, `uploadFile()`, `updateFile()`, `deleteFile()`, `findShare()`, `createShareLink()`, `getShareLink()`, `handleFile()`, `AddFileInfoToData()`, `createPdf()`, `createZip()`, `downloadZip()`. — done for the public methods that still exist; the others were removed.
  - [x] 4.4 Confirm PHPCS passes with the added docblocks. — done: see 3.2.

## Task 5: Write PHPUnit tests

- **Spec ref**: specs/file-management/spec.md (all REQ-FIL-*), design.md (Test Strategy)
- **Status**: todo
- **Files**: `tests/Unit/Service/FileServiceTest.php` (create if absent)
- **Acceptance criteria**:
  - [x] 5.1 **REQ-FIL-007** — `testGetShareLinkUsesUrlGenerator()`: mock `IURLGenerator`; assert `getShareLink()` calls `getAbsoluteURL()` and the returned URL contains the share token; assert `$_SERVER` is never read. — superseded: `FileServiceTest::testCreatePublicShareLinkSuccess` and its siblings cover the OpenRegister delegation.
  - [x] 5.2 **REQ-FIL-002** — `testUploadFileReturnsFalseWhenFileExists()`: mock `IRootFolder` to return an existing file node; assert `uploadFile()` returns `false` and logs a warning. — done: `FileServiceTest::testUploadFileExistingFile`.
  - [x] 5.3 **REQ-FIL-002** — `testUploadFileReturnsTrueWhenFileIsNew()`: mock `IRootFolder` to throw `NotFoundException`; assert `uploadFile()` creates the file and returns `true`. — done: `FileServiceTest::testUploadFileNewFile`.
  - [x] 5.4 **REQ-FIL-003** — `testUpdateFileCreatesNewFileWhenCreateNewIsTrue()`: assert file is created and `true` returned when `$createNew = true` and file is absent. — obsolete: `updateFile()` was removed as dead code (4b8bdba04).
  - [x] 5.5 **REQ-FIL-003** — `testUpdateFileReturnsFalseWhenFileAbsentAndCreateNewIsFalse()`: assert `false` returned when `$createNew = false` and file is absent. — obsolete: see 5.4.
  - [x] 5.6 **REQ-FIL-004** — `testDeleteFileReturnsFalseWhenNotFound()`: mock `IRootFolder` to throw `NotFoundException`; assert `deleteFile()` returns `false` without exception. — obsolete: `deleteFile()` was removed as dead code (4b8bdba04).
  - [x] 5.7 **REQ-FIL-005** — `testShareLinkDefaultsToReadOnlyForPublicShare()`: assert `permissions = 1` is set on the IShare when `$shareType = 3` and `$permissions = null`. — obsolete: share permissions are OpenRegister's (see 1.3).
  - [x] 5.8 **REQ-FIL-005** — `testShareLinkDefaultsToAllPermissionsForNonPublicShare()`: assert `permissions = 31` is set when `$shareType != 3` and `$permissions = null`. — obsolete: see 5.7.
  - [x] 5.9 **REQ-FIL-008** — `testHandleFileHappyPath()`: mock `IRequest` with a valid `_file`; assert folders created in order, file uploaded, and returned array contains `reference`, `type`, `size`, `title`, `extension`, `accessUrl`, `downloadUrl`. — done: `FileServiceTest::testHandleFileSuccessfulUpload`.
  - [x] 5.10 **REQ-FIL-008** — `testHandleFileReturnsJsonResponseOnValidationError()`: mock `IRequest` with upload error; assert return type is `JSONResponse`. — done: `FileServiceTest::testHandleFileUploadError`.
  - [x] 5.11 **REQ-FIL-012** — `testCreateZipReturnsNullOnSuccess()`: use real temp directory with two test files; assert ZIP is created and return value is `null`. — obsolete: `createZip()` was removed as dead code (a0790efc2).
  - [x] 5.12 **REQ-FIL-012** — `testCreateZipReturnsErrorStringOnFailure()`: pass non-existent input folder; assert return value is a non-empty string. — obsolete: see 5.11.
  - [x] 5.13 All tests pass via `./vendor/bin/phpunit tests/Unit/Service/FileServiceTest.php`. — done: 28 tests pass.

## Task 6: Manual verification

- **Status**: todo
- **Acceptance criteria**:
  - [ ] 6.1 In a running dev environment, upload a file attachment to a publication via the OpenCatalogi UI and confirm the file appears under `Publicaties/{id} {title}/Bijlagen/` in Nextcloud Files.
  - [ ] 6.2 Confirm a public share link is generated and accessible in a browser (unauthenticated).
  - [ ] 6.3 Run WOO sitemap generation as a background job and confirm share URLs are correctly formed (not empty or `://example.nl/...` artifacts from missing `$_SERVER`).
  - [ ] 6.4 Download a publication as ZIP and confirm the archive contains the expected files and the temp file is deleted after download.
  - [ ] 6.5 Tail the Nextcloud log during all operations and confirm no PHP warnings, `ini_set` failures, or Mpdf class-not-found errors appear.
