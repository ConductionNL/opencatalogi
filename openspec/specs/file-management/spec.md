---
status: needs-rewrite
or_dep: register-resolver-service
audit_ref: .claude/audit-2026-05-03/02-spec-rewrite.md
---

# File Management

> **NEEDS-REWRITE notice:** This spec was rewritten as part of
> `opencatalogi-adopt-or-abstractions` (Phase 3). The bespoke
> `FileService` implementations for file CRUD, share creation/discovery,
> URL assembly, and ZIP generation are replaced by OR's File Attachments
> capability (`x-openregister-file` schema annotation + `IFileService`)
> and `OCP\Share\IShareManager`. See the Breaking Changes section and the
> per-requirement REMOVED annotations below.
>
> Upstream dependencies:
> - `openregister/openspec/changes/register-resolver-service/`
> - OR File Attachments capability (`x-openregister-file`)

## Purpose

File management in opencatalogi is the set of operations by which publications
acquire, store, share, and bundle file attachments. After Phase 3 lands,
opencatalogi is a **thin consumer** of OR's File Attachments capability and
Nextcloud's native share management — it does not own file storage, share
creation, share discovery, URL assembly, ZIP generation, or bespoke PDF
rendering of publication metadata. Those operations are either delegated to OR
or to the `download-service` spec (which is itself a streaming wrapper;
see [download-service/spec.md](../download-service/spec.md)).

## ADDED Requirements

### Requirement: file attachment goes through the OR file service (FIL-OR-001)

Every upload, update, and delete of a file attachment on a publication MUST be
handled by the OR file service resolved via dependency injection. The schema
MUST declare the relevant attachment property with `x-openregister-file`.
`lib/Controller/FilesController.php` becomes a thin delegate that:

1. Resolves the register/schema for the target publication via
   `RegisterResolverService::resolvePair('publications')`.
2. Delegates the operation to the OR file service.
3. Returns the OR response unchanged.

`lib/Service/FileService.php` MAY remain as a wrapper for OR file service
invocations not otherwise covered by the controller delegate; it MUST NOT
contain bespoke Nextcloud file storage, share creation, or URL assembly logic.

> @e2e exclude Backend file-service delegation contract (FilesController resolves register/schema via RegisterResolverService and delegates upload/update/delete to the DI-resolved OR file service, returns the OR response unchanged, and emits a 503 with no bespoke NC fallback when OR is absent) — a controller/network contract, not a UI surface; verified by PHPUnit (delegation + 503) and Newman. The upload UI flow itself is already real-UI covered under file-management::upload-a-file-to-the-active-publication.

#### Scenario: upload delegates to OR

- **GIVEN** a request to upload a file against a publication,
- **WHEN** `FilesController` handles the request,
- **THEN** it resolves the `publications` register/schema via
  `RegisterResolverService`,
- **AND** calls the OR file service to persist the file,
- **AND** returns the OR-issued attachment metadata to the caller.

#### Scenario: OR file service absent

- **GIVEN** OR is not installed or does not expose the file service,
- **WHEN** an upload is attempted,
- **THEN** `FilesController` returns a 503 with operator-actionable detail,
- **AND** opencatalogi does NOT fall back to a bespoke Nextcloud file write.

### Requirement: share creation goes through `OCP\Share\IShareManager` (FIL-OR-002)

When opencatalogi needs to create a public share link on an attachment,
it MUST call `OCP\Share\IShareManager::createShare()`. The legacy
bespoke `FileService::createShareLink()` and `FileService::createShare()`
methods are removed (see REMOVED section). opencatalogi MUST NOT hold a
parallel share-creation implementation.

The OpenRegister shares leaf (integration registry, ADR-019) is the
preferred route when available; `IShareManager` is the fallback when the
leaf is absent.

> @e2e exclude Backend share-creation contract (public shares created through the OR shares leaf when available, falling back to `IShareManager::createShare()` type-3 read-only, with the share URL taken from `IShare` rather than hand-assembled from $_SERVER, and no bespoke FileService::createShareLink) — a server-side integration path with no UI surface; verified by PHPUnit asserting the leaf/IShareManager call and URL source.

#### Scenario: share created via OR shares leaf (preferred path)

- **GIVEN** the OR shares leaf integration is available,
- **WHEN** a public share is requested for an attachment,
- **THEN** the share is created through the OR shares leaf,
- **AND** no call is made to a bespoke `FileService::createShareLink()`.

#### Scenario: share created via IShareManager (fallback)

- **GIVEN** the OR shares leaf is absent,
- **WHEN** a public share is requested,
- **THEN** `IShareManager::createShare()` is called directly (type 3,
  read-only by default),
- **AND** the share URL is obtained from `IShare` — NOT hand-assembled
  from `$_SERVER['HTTPS']` and `$_SERVER['HTTP_HOST']`.

### Requirement: file uploads from the frontend use the OR files endpoint (FIL-OR-003)

Frontend upload modals (`UploadFiles`, `MassAttachmentModal`) MUST target the
OR files endpoint:
`/index.php/apps/openregister/api/objects/{register}/{schema}/{publicationId}/files`.

The register and schema identifiers MUST be read from the object store's
`@self.register` and `@self.schema` envelope, not hard-coded.

> @e2e exclude Frontend network-target contract (upload modals POST to the OR `/objects/{register}/{schema}/{publicationId}/files` endpoint with register/schema read from the `@self` envelope, not hard-coded, and apply selected tags via the OR tags API) — the assertion is the request target/payload, not a distinct browsable surface; verified by vitest mocking the OR endpoint and asserting the URL + envelope-derived ids. The upload UI flow is already real-UI covered under file-management::upload-a-file-to-the-active-publication.

#### Scenario: upload modal sends file to OR endpoint

- **GIVEN** the upload modal is open with the active publication selected,
- **WHEN** the user uploads a file,
- **THEN** the file is sent to the publication's OR `.../files` endpoint,
- **AND** any selected tags are applied via the OR tags API.

### Requirement: attachment deletion goes through OR (FIL-OR-004)

`DeleteAttachmentDialog` MUST issue `DELETE` to the OR files endpoint:
`/api/objects/{register}/{schema}/{publicationId}/files/{attachmentId}`.
After deletion it MUST refresh the publication's attachments and close the
dialog.

### Requirement: attachment metadata editing goes through the object store (FIL-OR-005)

`EditAttachmentModal` MUST persist updates via
`objectStore.updateObject('attachment', id, attachment)`. It MUST NOT call
a bespoke `FileService` update method.

### Requirement: file-selection composable is preserved (FIL-OR-006)

The `useFileSelection` composable (drop-zone state, file list, tag setters,
duplicate rejection, reset/open helpers) is a UI-only abstraction that does
NOT conflict with OR's file service. It remains as-is.

## REMOVED Requirements

The following requirements described bespoke implementations that OR's file
capability now owns. They are retained here for traceability; implementation
MUST NOT re-introduce them.

| ID | Title | Reason removed |
|----|-------|----------------|
| FIL-001 | Create folders in Nextcloud user storage | REMOVED — OR file service owns folder management; opencatalogi MUST NOT call `IRootFolder` for folder creation. |
| FIL-002 | Upload new files to Nextcloud user storage | REMOVED — OR file service owns file creation; `FileService::uploadFile()` is deleted. |
| FIL-003 | Update/overwrite existing files | REMOVED — OR file service owns file updates; `FileService::updateFile()` is deleted. |
| FIL-004 | Delete files from Nextcloud user storage | REMOVED — OR file service owns file deletion; `FileService::deleteFile()` is deleted. |
| FIL-005 (legacy) | Create public share links (IShare type 3) via bespoke FileService::createShareLink() | REMOVED — share creation goes through OR shares leaf or IShareManager directly; `FileService::createShareLink()` is deleted. The requirement is superseded by FIL-OR-002. |
| FIL-006 (legacy) | Find existing share links via bespoke FileService::findShare() | REMOVED — share discovery goes through the OR shares leaf; `FileService::findShare()` is deleted. |
| FIL-007 (legacy) | Return full share link URLs via bespoke FileService::getShareLink() | REMOVED — URL is obtained from `IShare` via `IShareManager`; hand-assembled `{protocol}://{host}/index.php/s/{token}` strings are deleted. |
| FIL-008 | Handle HTTP file uploads via `_file` key in multipart requests | REMOVED — upload routing is the OR file controller's responsibility; `FileService::handleFile()` is deleted. |
| FIL-009 | Create structured folder hierarchy `Publicaties/{id} {title}/Bijlagen/` | REMOVED — OR file service owns the storage layout; opencatalogi MUST NOT prescribe a folder name pattern in its own code. |
| FIL-010 | Add file metadata to data arrays | REMOVED — OR file service returns standardised attachment metadata; `FileService::AddFileInfoToData()` is deleted. |
| FIL-011 | Generate PDFs using Twig/mPDF | REMOVED — PDF generation is the responsibility of the `download-service` spec (streaming wrapper over OR file attachments); `FileService::createPdf()` is deleted from opencatalogi's file management layer. |
| FIL-012 | Create ZIP archives from folder contents | REMOVED — ZIP generation is the responsibility of the `download-service` spec. |
| FIL-013 | Send ZIP archives as download responses | REMOVED — see FIL-012. |
| FIL-014 | Clean up temporary files after ZIP operations | REMOVED — see FIL-012. |
| FIL-015 | Memory limit set to 2048M for large file operations | REMOVED — `ini_set('memory_limit', '2048M')` in the file management layer is deleted; memory budget for large operations is managed by the download-service or the OR file service, not here. |

FIL-016, FIL-017, FIL-018, FIL-019 (frontend upload/delete/edit/composable) are superseded by
FIL-OR-003, FIL-OR-004, FIL-OR-005, FIL-OR-006 respectively, which redirect the same
operations through the OR endpoint or the object store.

## Breaking Changes

| Breaking change | Old behaviour | New behaviour |
|---|---|---|
| `FileService::createShareLink()` removed | Bespoke share creation; silently fails or produces stale share if OR is misconfigured | Calls OR shares leaf or `IShareManager::createShare()`; throws on misconfiguration |
| `FileService::handleFile()` removed | Multipart upload handled locally; folder created under Nextcloud user storage | Upload routed to OR files endpoint; OR owns storage |
| `FileService::createPdf()` / `createZip()` moved | Called from FilesController directly | Lives only in download-service's streaming wrapper; FilesController no longer calls them |
| `ini_set('memory_limit', '2048M')` removed | Set for every request touching FileService | No longer set; OR file service and download-service own their own memory budgets |

## Architecture

After Phase 3:

| Component | Responsibility |
|---|---|
| `lib/Controller/FilesController.php` | Thin delegate: resolve register/schema via `RegisterResolverService`, forward to OR file service |
| OR file service (injected) | File storage, attachment metadata, versioning |
| `OCP\Share\IShareManager` | Share creation/discovery/URL resolution (fallback when OR shares leaf absent) |
| OR shares leaf (ADR-019) | Preferred route for share creation; delegates to IShareManager internally |
| `useFileSelection` composable | Frontend UI state only; file content goes to OR endpoint |

## References

- `openregister/openspec/changes/register-resolver-service/` (upstream dependency)
- OR File Attachments capability (`x-openregister-file` schema annotation)
- `.claude/audit-2026-05-03/02-spec-rewrite.md` (Stream 2 NEEDS-REWRITE rationale)
- `openspec/changes/opencatalogi-adopt-or-abstractions/` (Phase 3 implementation change)
- `openspec/specs/download-service/spec.md` (PDF/ZIP streaming wrapper, dependent on Phase 3)
- ADR-022 — Apps consume OR abstractions
