# Tasks: migrate-share-links-to-shares-leaf

This change consumes the OpenRegister shares leaf (hydra ADR-022) for public
share-link creation/lookup/resolution on Attachment / Publication files, and
retires the bespoke `FileService` share methods. SPEC-ONLY — apply runs through
Hydra once the shares leaf is available upstream.

## Task 1: Implementation planning
- **Spec ref**: specs/file-management/spec.md
- **Status**: todo
- **Acceptance criteria**: Requirements decomposed into implementable tasks
  respecting the consume-the-leaf approach; OR shares-leaf availability confirmed
  as the apply gate.

## Task 2: Consume the shares leaf for share creation (FIL-005)
- **Spec ref**: specs/file-management/spec.md — FIL-005
- **Status**: todo
- **Acceptance criteria**:
  - Public (type-3, read-only default) shares for attachment files are created by
    calling the OpenRegister shares leaf service via DI.
  - Auto-publishing (`auto_publish_attachments`) routes through the leaf.
  - `FileService::createShareLink()` and private `createShare()` are removed.
  - Published attachments remain anonymously downloadable.
  - Graceful "sharing integration required" handling when the leaf is absent.

## Task 3: Consume the shares leaf for share lookup (FIL-006)
- **Spec ref**: specs/file-management/spec.md — FIL-006
- **Status**: todo
- **Acceptance criteria**:
  - Existing shares (leaf-minted and legacy NC type-3) are discovered via the leaf.
  - `FileService::findShare()` is removed; no duplicate shares are created.

## Task 4: Consume the shares leaf for URL resolution (FIL-007)
- **Spec ref**: specs/file-management/spec.md — FIL-007
- **Status**: todo
- **Acceptance criteria**:
  - Full share URLs are obtained from the leaf; no PHP-side `{protocol}://{host}/...`
    assembly remains.
  - `FileService::getShareLink()` is removed.

## Task 5: Surface the shares widget on the publication detail page
- **Spec ref**: specs/file-management/spec.md — FIL-007; ADR-024 / ADR-036
- **Status**: todo
- **Acceptance criteria**:
  - The shares leaf widget is declared on the `PublicationDetail` manifest entry
    (`src/manifest.json`) and renders in `PublicationDetail.vue`.
  - Users can create/view/revoke shares for a publication's attachments from the
    object, not from buried service code.

## Task 6: Verify WOO public-download and legacy-share continuity
- **Spec ref**: specs/file-management/spec.md — FIL-005, FIL-006
- **Status**: todo
- **Acceptance criteria**:
  - Anonymous download of a published attachment works end-to-end via the leaf.
  - Links minted by the legacy bespoke path remain resolvable after migration.
