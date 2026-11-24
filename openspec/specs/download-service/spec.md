---
status: needs-rewrite
or_dep: file-attachments
audit_ref: .claude/audit-2026-05-03/02-spec-rewrite.md
---

# Download Service

> **NEEDS-REWRITE notice:** This spec was rewritten as part of
> `opencatalogi-adopt-or-abstractions` (Phase 7, dependent on Phase 3).
> The bespoke file CRUD, share-link creation, PDF rendering inside the
> download service layer, and bespoke versioning described in the previous
> version are replaced by a streaming wrapper over OR's File Attachments
> + versioning capability. See the REMOVED section and Breaking Changes.
>
> Upstream dependencies:
> - `openregister/openspec/changes/register-resolver-service/`
> - OR File Attachments capability (`x-openregister-file`)
> - `openspec/specs/file-management/spec.md` (Phase 3 — must land first)

## Purpose

@e2e exclude OR-abstraction-consumer spec — file streaming/versioning delegated to OpenRegister's File Attachments and verified by PHPUnit/Newman, a download response not a browser-UI surface.

The download service provides downloadable export files from publications.
After the Phase 7 rewrite, it is a thin wrapper over OpenRegister:

- ZIP archive generation asks OR's file service for the publication's
  attachments ZIP; opencatalogi keeps no local copy of attached files.
- PDF metadata generation (publication metadata PDF) remains as
  a lightweight rendering step using Twig + mPDF, and the resulting PDF
  goes into the ZIP rather than into Nextcloud storage.
- The download serves the current version of each attachment. There is no
  version selector and no local version history or snapshot.

## Requirements

### Requirement: ZIP generation streams from OR file attachments (DWN-OR-001)

When generating a ZIP archive of a publication's attachments, the download service MUST:

1. Resolve the publication's register/schema via `RegisterResolverService`.
2. Obtain the attachments from OR's file service (resolved via DI, not by
   calling Nextcloud's `IRootFolder` directly).
3. Return the ZIP as the response. The ZIP is read into one buffered
   response; publication ZIPs are small, and streaming is not required.
4. Clean up temporary files after the response is sent.

opencatalogi MUST NOT maintain a local copy of attached files for download
purposes.

> @e2e exclude Server-side ZIP-streaming contract (file streams obtained from OR's file service via DI, piped without buffering, no local copy in NC user storage, Bijlagen/ structure + metadata PDF included) — a streaming download response, not a browsable UI surface; verified by PHPUnit/Newman asserting the ZIP contents and that no file content is written to user storage.

#### Scenario: ZIP generation streams from OR

- **GIVEN** a user requests a ZIP of all attachments on a publication,
- **WHEN** the download service handles the request,
- **THEN** it obtains the attachments from OR's file service,
- **AND** no file content is written to Nextcloud user storage by
  opencatalogi during this operation.

#### Scenario: ZIP contains the metadata PDF and all OR attachments

- **GIVEN** a publication with N attachments,
- **WHEN** a ZIP is requested,
- **THEN** the ZIP contains:
  - one metadata PDF rendered from the publication's data (Twig + mPDF),
  - N files piped from OR's file service,
  - attachments in a `Bijlagen/` subfolder per the existing structure.

### Requirement: downloads serve the current version without local versioning (DWN-OR-002)

The download route takes no version selector: it serves the current version of each attachment as OR's file service returns it. The download service MUST NOT maintain a separate version history, snapshot table, or local versioning logic. A version selector, if one is ever needed, is passed through to OR's file service and is a new requirement.

> @e2e exclude Server-side contract (current version from OR's file service; no local version table consulted): a download response, not a UI surface; verified by PHPUnit asserting no local version table exists.

#### Scenario: file download serves the current version

- **GIVEN** a request for a publication's download,
- **WHEN** the download service handles the request,
- **THEN** it takes the attachments as OR's file service returns them,
- **AND** does NOT consult a local version table.

### Requirement: metadata PDF generation remains as a rendering step (DWN-OR-003)

Generating a publication metadata PDF (Twig + mPDF) MUST remain an in-app rendering step within the download service. This is a legitimate in-app rendering step because:

- It uses opencatalogi-specific Twig templates and publication schema layout.
- It is not a file that lives in OR's storage permanently — it is generated
  on-demand and piped into the ZIP or returned as a download response.

The download service MUST NOT save the generated PDF to Nextcloud user
storage via `IRootFolder` or `FileService`. If a persistent copy is needed
(e.g. for a share link), it MUST be saved through OR's file service.

> @e2e exclude Server-side PDF-rendering contract (metadata PDF rendered via Twig + mPDF to a temp location, piped into the ZIP, temp file deleted after the response, never saved to NC user storage) — a server rendering/streaming step, not a UI surface; verified by PHPUnit/Newman asserting the PDF is present in the ZIP and the temp file is cleaned up.

#### Scenario: metadata PDF is rendered and piped into ZIP

- **GIVEN** a ZIP is requested for a publication,
- **WHEN** the download service renders the metadata PDF,
- **THEN** the PDF is generated in a temporary location (e.g. `/tmp/mpdf/`),
- **AND** piped into the ZIP stream,
- **AND** the temporary file is deleted after the response is sent.

### Requirement: options validation remains in place (DWN-OR-004)

The one output option is `download` (return the file as the response). When it is disabled, the service MUST return a 400 error before generating any file content. There is no option to save the file; a persistent copy goes through OR's file service outside the download service.

#### Scenario: no output option enabled

- **GIVEN** a download request with `download` disabled,
- **WHEN** the download service validates the request,
- **THEN** it MUST return a 400 error,
- **AND** it MUST NOT generate any file content.

### Requirement: missing publication produces an error response (DWN-OR-005)

If the publication ID is not found via OR's object service, the download service MUST return a 404 response before generating any file content. This holds whether OR answers with no object or throws `DoesNotExistException`. A lookup that fails otherwise (more than one match, a container error) is a 500 carrying the message.

#### Scenario: publication not found

- **GIVEN** a download request for a publication ID that OR's object service cannot resolve,
- **WHEN** the download service handles the request,
- **THEN** it MUST return a 404 response,
- **AND** it MUST NOT generate any file content.

## Change history

24 Nov 2026 (`download-service` task 7.2): the review against the code found the spec asked for more than ships. DWN-OR-001 no longer asks for unbuffered streaming, DWN-OR-002 no longer asks for a version selector, DWN-OR-004 names `download` as the one option, and DWN-OR-005 now also covers a thrown `DoesNotExistException`, which the code maps to 404.

The following requirements described bespoke implementations that OR's
File Attachments capability now owns. They are retained for traceability;
implementation MUST NOT re-introduce them.

| ID | Title | Reason removed |
|----|-------|----------------|
| DWN-002 | Save generated PDF to Nextcloud file storage in structured folder hierarchy | REMOVED — re-implements OR file storage; opencatalogi MUST NOT call `IRootFolder` for saving attachment-related files. If persistence is needed, it goes through OR's file service. |
| DWN-003 | Create and return share links for saved files | REMOVED — share link creation is delegated to `OCP\Share\IShareManager` via the OR shares leaf (see `file-management/spec.md` FIL-OR-002). The download service is NOT responsible for share link creation. |
| DWN-007 | Support configurable options: download-only, save-to-Nextcloud, or both | REMOVED — "save-to-Nextcloud" as a bespoke option writing to `IRootFolder` is deleted. No save option replaces it (24 Nov 2026: DWN-OR-004 narrowed to `download` only). |

DWN-001, DWN-004 through DWN-006, DWN-008 through DWN-010 are superseded
by DWN-OR-001 through DWN-OR-005. Observable behaviours are preserved;
the implementation path now routes through OR's file service.

## Architecture

After Phase 7:

| Component | Responsibility |
|---|---|
| `DownloadService` | Orchestrates: resolves publication via OR, renders the metadata PDF to a temp file, takes the attachments ZIP from OR's file service |
| OR file service (injected) | Attachment source |
| `RegisterResolverService` | Resolves `publications` register/schema for object lookup |
| Twig + mPDF | Renders the publication metadata PDF (legitimate in-app rendering) |
| `OCP\Share\IShareManager` | Share URL resolution if a persistent share is needed (not initiated by the download service itself) |

### ZIP Archive Structure (unchanged)

```
publicatie_{title}.zip
  {title}.pdf          <-- Metadata PDF (generated in-memory, piped in)
  Bijlagen/
    attachment1.pdf    <-- Streamed from OR file service
    attachment2.docx   <-- Streamed from OR file service
```

## Breaking Changes

| Breaking change | Old behaviour | New behaviour |
|---|---|---|
| PDF saved to Nextcloud user storage removed | `DownloadService` saved PDF to `Publicaties/{id} {title}/{title}.pdf` via `IRootFolder` | PDF is generated in-memory (temp file) and piped into the response or ZIP stream; `IRootFolder` is not called by the download service |
| Share link creation removed from download service | `DownloadService` called `FileService::createShareLink()` and returned a share URL | Share links are created by the file management layer (FIL-OR-002), not by the download service |
| Attachment fetching via `getObject()` / `getMultipleObjects()` replaced | Legacy pattern `getObject(id)` / `getMultipleObjects(ids)` used | Attachments fetched via OR file service using the resolved register/schema pair |

## References

- OR File Attachments capability (upstream dependency, requires Phase 3 to land first)
- `openregister/openspec/changes/register-resolver-service/`
- `openspec/specs/file-management/spec.md` (Phase 3 — must land before Phase 7)
- `.claude/audit-2026-05-03/02-spec-rewrite.md` (Stream 2 NEEDS-REWRITE rationale)
- `openspec/changes/opencatalogi-adopt-or-abstractions/` (Phase 7 implementation change)
- ADR-022 — Apps consume OR abstractions
