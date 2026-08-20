# Tasks: file-management

## Task 1: Deduplication check

- **Spec ref**: design.md (Reuse Analysis)
- **Status**: todo
- **Acceptance criteria**:
  - [ ] 1.1 Search `openregister/lib/Service/` for `FileService` and compare method signatures with `lib/Service/FileService.php` in OpenCatalogi.
  - [ ] 1.2 Document findings in a comment at the top of `FileService.php`: either "delegates to OpenRegister FileService for upload/share" or "custom implementation justified because: {reason}".
  - [ ] 1.3 If delegation is viable for `uploadFile()` and/or `createShareLink()`, replace the custom implementation with a call to the platform's service via DI injection.
  - [ ] 1.4 Confirm no overlap exists for `createPdf()`, `createZip()`, `downloadZip()`, `handleFile()`, and `AddFileInfoToData()` — these are OpenCatalogi-specific.

## Task 2: Fix getCurrentDomain() — replace `$_SERVER` with IURLGenerator

- **Spec ref**: specs/file-management/spec.md (REQ-FIL-007)
- **Status**: todo
- **Files**: `lib/Service/FileService.php`
- **Acceptance criteria**:
  - [ ] 2.1 Add `private readonly IURLGenerator $urlGenerator` as a constructor parameter in `FileService`.
  - [ ] 2.2 Update `lib/AppInfo/Application.php` (or the DI container configuration) if `FileService` is registered manually — verify that `IURLGenerator` is auto-wired by Nextcloud's DI; if not, register it.
  - [ ] 2.3 Rewrite `getShareLink()` (or `getCurrentDomain()`) to call `$this->urlGenerator->getAbsoluteURL("/index.php/s/{$share->getToken()}")` instead of reading `$_SERVER`.
  - [ ] 2.4 Remove the `getCurrentDomain()` method entirely if it is no longer needed after the refactor, or mark it `@deprecated` if other callers exist (grep `lib/` to confirm).
  - [ ] 2.5 Verify that the returned URL is correct in a running dev environment by creating a share and comparing the URL.

## Task 3: Fix mPDF use-statement typo

- **Spec ref**: specs/file-management/spec.md (REQ-FIL-011)
- **Status**: todo
- **Files**: `lib/Service/FileService.php`
- **Acceptance criteria**:
  - [ ] 3.1 Change `use Mpdf\MpMpdfdf;` to `use Mpdf\Mpdf;`.
  - [ ] 3.2 Run `composer check:strict` (PHPCS / PHPMD / Psalm / PHPStan) and confirm zero errors related to this file.
  - [ ] 3.3 Run `createPdf()` in a dev environment and confirm a PDF is generated without errors.

## Task 4: Add @spec PHPDoc traceability tags

- **Spec ref**: ADR-003 (Spec traceability requirement)
- **Status**: todo
- **Files**: `lib/Service/FileService.php`
- **Acceptance criteria**:
  - [ ] 4.1 Add a file-level docblock above the class declaration (or in the namespace block) with `@spec openspec/changes/file-management/tasks.md`.
  - [ ] 4.2 Add `@spec openspec/changes/file-management/tasks.md#task-4` to the class-level PHPDoc block of `FileService`.
  - [ ] 4.3 Add `@spec openspec/changes/file-management/tasks.md#task-4` to every public method: `createFolder()`, `getPublicationFolderName()`, `uploadFile()`, `updateFile()`, `deleteFile()`, `findShare()`, `createShareLink()`, `getShareLink()`, `handleFile()`, `AddFileInfoToData()`, `createPdf()`, `createZip()`, `downloadZip()`.
  - [ ] 4.4 Confirm PHPCS passes with the added docblocks.

## Task 5: Write PHPUnit tests

- **Spec ref**: specs/file-management/spec.md (all REQ-FIL-*), design.md (Test Strategy)
- **Status**: todo
- **Files**: `tests/Unit/Service/FileServiceTest.php` (create if absent)
- **Acceptance criteria**:
  - [ ] 5.1 **REQ-FIL-007** — `testGetShareLinkUsesUrlGenerator()`: mock `IURLGenerator`; assert `getShareLink()` calls `getAbsoluteURL()` and the returned URL contains the share token; assert `$_SERVER` is never read.
  - [ ] 5.2 **REQ-FIL-002** — `testUploadFileReturnsFalseWhenFileExists()`: mock `IRootFolder` to return an existing file node; assert `uploadFile()` returns `false` and logs a warning.
  - [ ] 5.3 **REQ-FIL-002** — `testUploadFileReturnsTrueWhenFileIsNew()`: mock `IRootFolder` to throw `NotFoundException`; assert `uploadFile()` creates the file and returns `true`.
  - [ ] 5.4 **REQ-FIL-003** — `testUpdateFileCreatesNewFileWhenCreateNewIsTrue()`: assert file is created and `true` returned when `$createNew = true` and file is absent.
  - [ ] 5.5 **REQ-FIL-003** — `testUpdateFileReturnsFalseWhenFileAbsentAndCreateNewIsFalse()`: assert `false` returned when `$createNew = false` and file is absent.
  - [ ] 5.6 **REQ-FIL-004** — `testDeleteFileReturnsFalseWhenNotFound()`: mock `IRootFolder` to throw `NotFoundException`; assert `deleteFile()` returns `false` without exception.
  - [ ] 5.7 **REQ-FIL-005** — `testShareLinkDefaultsToReadOnlyForPublicShare()`: assert `permissions = 1` is set on the IShare when `$shareType = 3` and `$permissions = null`.
  - [ ] 5.8 **REQ-FIL-005** — `testShareLinkDefaultsToAllPermissionsForNonPublicShare()`: assert `permissions = 31` is set when `$shareType != 3` and `$permissions = null`.
  - [ ] 5.9 **REQ-FIL-008** — `testHandleFileHappyPath()`: mock `IRequest` with a valid `_file`; assert folders created in order, file uploaded, and returned array contains `reference`, `type`, `size`, `title`, `extension`, `accessUrl`, `downloadUrl`.
  - [ ] 5.10 **REQ-FIL-008** — `testHandleFileReturnsJsonResponseOnValidationError()`: mock `IRequest` with upload error; assert return type is `JSONResponse`.
  - [ ] 5.11 **REQ-FIL-012** — `testCreateZipReturnsNullOnSuccess()`: use real temp directory with two test files; assert ZIP is created and return value is `null`.
  - [ ] 5.12 **REQ-FIL-012** — `testCreateZipReturnsErrorStringOnFailure()`: pass non-existent input folder; assert return value is a non-empty string.
  - [ ] 5.13 All tests pass via `./vendor/bin/phpunit tests/Unit/Service/FileServiceTest.php`.

## Task 6: Manual verification

- **Status**: todo
- **Acceptance criteria**:
  - [ ] 6.1 In a running dev environment, upload a file attachment to a publication via the OpenCatalogi UI and confirm the file appears under `Publicaties/{id} {title}/Bijlagen/` in Nextcloud Files.
  - [ ] 6.2 Confirm a public share link is generated and accessible in a browser (unauthenticated).
  - [ ] 6.3 Run WOO sitemap generation as a background job and confirm share URLs are correctly formed (not empty or `://example.nl/...` artifacts from missing `$_SERVER`).
  - [ ] 6.4 Download a publication as ZIP and confirm the archive contains the expected files and the temp file is deleted after download.
  - [ ] 6.5 Tail the Nextcloud log during all operations and confirm no PHP warnings, `ini_set` failures, or Mpdf class-not-found errors appear.
