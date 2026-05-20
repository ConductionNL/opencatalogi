# Design: file-management

## Architecture Overview

The entire file management surface is encapsulated in a single service class:

```
lib/Service/FileService.php   (FileService)
```

`FileService` follows the strict **Controller → Service → Mapper** layering mandated by ADR-003. It is stateless between requests and receives all dependencies via constructor injection. It is consumed by:

- `DownloadService` — calls `createZip()` and `downloadZip()`
- Auto-publishing pipeline — calls `handleFile()`, `createShareLink()`, `uploadFile()`
- WOO sitemap generation — calls `createPdf()`

No controller, mapper, or store change is introduced by this change. The change formalises the existing implementation and resolves two defects.

## Constructor Dependencies

| Dependency | Type | Purpose |
|---|---|---|
| `$userSession` | `OCP\IUserSession` | Provides the current Nextcloud user for all storage operations |
| `$logger` | `Psr\Log\LoggerInterface` | Logs warnings and errors without exposing them to callers |
| `$rootFolder` | `OCP\Files\IRootFolder` | Entry point to Nextcloud user file storage |
| `$shareManager` | `OCP\Share\IManager` | Creates and queries Nextcloud share objects |
| `$urlGenerator` | `OCP\IURLGenerator` | **(new)** Resolves absolute URLs for share links — replaces raw `$_SERVER` access |

## Key Design Decisions

### Decision 1: Inject IURLGenerator instead of reading `$_SERVER`

`getCurrentDomain()` currently accesses `$_SERVER['HTTPS']` and `$_SERVER['HTTP_HOST']` directly. This pattern fails in CLI and cron contexts (WOO sitemap generation runs as a background job) where no HTTP request is available and `$_SERVER` is empty or incorrect. Nextcloud provides `IURLGenerator::getAbsoluteURL()` precisely for this use case — it reads the configured base URL from `IAppConfig` and is fully testable via mocking.

**Change**: add `private readonly IURLGenerator $urlGenerator` to the constructor; rewrite `getShareLink()` / `getCurrentDomain()` to call `$this->urlGenerator->getAbsoluteURL("/index.php/s/{$share->getToken()}")`.

### Decision 2: Fix the mPDF use-statement typo

`use Mpdf\MpMpdfdf;` is a typo for `use Mpdf\Mpdf;`. PHP resolves the class reference in `createPdf()` by looking up the fully-qualified name at runtime, so the malformed use statement is currently harmless. However it creates confusion in IDE analysis and static analysis tools. It is corrected to `use Mpdf\Mpdf;`.

### Decision 3: Add `@spec` PHPDoc traceability to FileService

ADR-003 requires every class and public method to carry `@spec` PHPDoc tags linking to the OpenSpec change. The class docblock and all public methods in `FileService` receive `@spec openspec/changes/file-management/tasks.md#task-N` tags as part of this change.

### Decision 4: No custom seed data (exception applies)

ADR-001 requires 3–5 seed objects per schema when a change introduces or modifies OpenRegister schemas. `FileService` manages files in the Nextcloud file system — it does not create or modify any OpenRegister schemas. The seed data requirement does not apply (see ADR-001: "Changes that only modify frontend components or non-schema backend logic do not require seed data").

### Decision 5: No new OpenRegister schemas

All state managed by `FileService` lives in Nextcloud's `IRootFolder` (files) and `IShare` objects (shares). No OpenRegister entity is created, modified, or queried. No `lib/Settings/opencatalogi_register.json` changes are needed.

## Reuse Analysis

ADR-001 mandates a reuse analysis before proposing new capability.

| Capability | OpenRegister platform layer | OpenCatalogi FileService | Verdict |
|---|---|---|---|
| Single file upload | `FileService.uploadFile()` (platform) | `FileService.uploadFile()` (app) | **Overlap** — OpenCatalogi's method is a thin wrapper with identical semantics. At implementation time, assess whether the app method can delegate to the platform layer or whether the Nextcloud `IRootFolder` dependency differs. |
| Share link creation | `FileService.createShareLink()` (platform) | `FileService.createShareLink()` (app) | **Overlap** — platform layer covers this. Consider delegating. |
| Bulk ZIP download | `FileService.createObjectFilesZip()` (platform) | `FileService.createZip()` + `downloadZip()` | **Partial overlap** — platform's `createObjectFilesZip()` targets OpenRegister objects. App's version targets arbitrary Nextcloud folder paths used by publication hierarchy. Custom logic is justified. |
| PDF generation | Not provided by platform | `FileService.createPdf()` (app) | **No overlap** — domain-specific; app is responsible. |
| Publication folder hierarchy | Not provided by platform | `FileService.createFolder()` + `handleFile()` | **No overlap** — `Publicaties/{id} {title}/Bijlagen/` is OpenCatalogi-specific naming. |
| File metadata enrichment | Not provided by platform | `FileService.AddFileInfoToData()` | **No overlap** — publication-specific metadata fields. |

**Summary**: the upload and share-link methods overlap with the platform layer's `FileService`. During task implementation, verify whether the OpenRegister `FileService` can be injected and delegated to for these operations, reducing duplication. If the platform signatures differ (e.g. they accept OpenRegister object IDs rather than raw paths), the overlap is nominal and custom methods remain justified.

## Test Strategy

All tests are PHPUnit tests located in `tests/Unit/Service/FileServiceTest.php`.

1. **Share link URL** — mock `IURLGenerator`; assert `getShareLink()` calls `getAbsoluteURL()` and never accesses `$_SERVER`.
2. **Upload idempotency** — mock `IRootFolder`; assert `uploadFile()` returns `false` when file node exists.
3. **Update with createNew** — assert `updateFile()` creates a file when `$createNew = true` and returns `false` when `$createNew = false` and file is absent.
4. **Delete absent file** — assert `deleteFile()` returns `false` without exception when node is not found.
5. **handleFile() happy path** — mock `IRequest` with a valid `_file`; assert folders are created in order and `AddFileInfoToData()` fields are present.
6. **handleFile() invalid upload** — mock `IRequest` with upload error; assert `JSONResponse` is returned.
7. **Share permission defaulting** — assert permissions default to `1` for `shareType = 3` and `31` for other types when `$permissions = null`.
8. **createZip() success** — use a real temp directory; assert ZIP exists and contains expected files; assert return value is `null`.
9. **createZip() failure** — pass a non-existent input folder; assert return value is a non-empty string.
10. **createPdf() mPDF integration** — assert `Mpdf` object is returned (smoke test; mPDF is hard to mock, an integration test against the real library is acceptable).
