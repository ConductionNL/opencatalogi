# Proposal: file-management

## Summary
Formalize the FileService spec for OpenCatalogi, covering all file-related operations: folder creation, file CRUD, share link management, HTTP upload handling, publication folder hierarchy, file metadata enrichment, PDF generation via Twig/mPDF, and ZIP archive creation and download. Two known defects are also addressed: `getCurrentDomain()` bypasses `IURLGenerator`, and a typo in the mPDF use statement. All public methods receive `@spec` PHPDoc traceability tags as required by ADR-003.

## Motivation
The `FileService` is the foundational file layer relied on by `DownloadService`, the auto-publishing system, and WOO sitemap generation. While the implementation exists, its requirements have not been formally specified with testable acceptance criteria. Two defects identified during review degrade reliability in CLI/cron contexts and create a latent PHP parse risk. Formalising the spec enables regression testing, prevents silent breakage, and satisfies ADR-003 traceability requirements.

## Scope

### In scope
- Formal requirements (REQ-FIL-NNN) with GIVEN/WHEN/THEN acceptance scenarios for all 15 FIL requirements from the context brief
- Fix `getCurrentDomain()` to use `IURLGenerator` instead of `$_SERVER['HTTPS']` / `$_SERVER['HTTP_HOST']`
- Fix the use-statement typo: `use Mpdf\MpMpdfdf;` → `use Mpdf\Mpdf;`
- Add `@spec` PHPDoc tags to `FileService` class and all public methods (`lib/Service/FileService.php`)
- PHPUnit tests for: share link creation, file upload/update/delete, upload handling flow, PDF generation, ZIP creation and download
- Deduplication check against OpenRegister's built-in `FileService` to document intentional overlap and custom domain logic

### Out of scope
- Replacing `mPDF` with a different PDF library — the library choice is outside this change
- Changing the publication folder naming convention (`Publicaties/{id} {title}/Bijlagen/`) — this is a stable contract used by multiple callers
- Frontend file upload components — `CnFilesTab` / `CnObjectSidebar` from `@conduction/nextcloud-vue` are the prescribed UI layer (ADR-001); no custom upload UI is needed
- Multi-tenancy or RBAC changes to file access — out of scope for this change

## Risks
- **IURLGenerator injection**: `FileService` constructor must gain an `IURLGenerator` dependency. Any caller that instantiates `FileService` directly (rather than via DI) will break at construction time. Risk is low — Nextcloud's DI container wires all services; direct construction is not present in OpenCatalogi.
- **Existing tests using `$_SERVER`**: If any existing test stubs `$_SERVER['HTTPS']`/`$_SERVER['HTTP_HOST']` to exercise `getCurrentDomain()`, those tests must be updated to mock `IURLGenerator` instead.
