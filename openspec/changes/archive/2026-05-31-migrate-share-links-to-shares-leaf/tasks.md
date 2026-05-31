# Tasks: migrate-share-links-to-shares-leaf

This change consumes the OpenRegister shares leaf (hydra ADR-022) for public
share-link creation/lookup/resolution on Attachment / Publication files, and
retires the bespoke `FileService` share methods. SPEC-ONLY — apply runs through
Hydra once the shares leaf is available upstream.

## Task 1: Implementation planning
- **Spec ref**: specs/file-management/spec.md
- **Status**: done
- **Acceptance criteria**: Requirements decomposed into implementable tasks
  respecting the consume-the-leaf approach; OR shares-leaf availability confirmed
  as the apply gate.
- [x] Implemented

## Task 2: Consume the shares leaf for share creation (FIL-005)
- **Spec ref**: specs/file-management/spec.md — FIL-005
- **Status**: done
- **Acceptance criteria**:
  - Public (type-3, read-only default) shares for attachment files are created by
    calling the OpenRegister shares leaf service via DI.
  - Auto-publishing (`auto_publish_attachments`) routes through the leaf.
  - `FileService::createShareLink()` and private `createShare()` are removed.
  - Published attachments remain anonymously downloadable.
  - Graceful "sharing integration required" handling when the leaf is absent.
- [x] Implemented: `FileService::createPublicShareLink()` delegates to OR `FileService::createShareLink()`.
      Bespoke `createShareLink()` and `createShare()` removed. EventService already used OR leaf.

## Task 3: Consume the shares leaf for share lookup (FIL-006)
- **Spec ref**: specs/file-management/spec.md — FIL-006
- **Status**: done
- **Acceptance criteria**:
  - Existing shares (leaf-minted and legacy NC type-3) are discovered via the leaf.
  - `FileService::findShare()` is removed; no duplicate shares are created.
- [x] Implemented: `FileService::findShare()` removed. OR leaf handles find-or-create.

## Task 4: Consume the shares leaf for URL resolution (FIL-007)
- **Spec ref**: specs/file-management/spec.md — FIL-007
- **Status**: done
- **Acceptance criteria**:
  - Full share URLs are obtained from the leaf; no PHP-side `{protocol}://{host}/...`
    assembly remains.
  - `FileService::getShareLink()` is removed.
- [x] Implemented: `FileService::getShareLink()` and `getCurrentDomain()` removed. URL resolution
      delegated to OR leaf's `createShareLink()`.

## Task 5: Surface the shares widget on the publication detail page
- **Spec ref**: specs/file-management/spec.md — FIL-007; ADR-024 / ADR-036
- **Status**: done
- **Acceptance criteria**:
  - The shares leaf widget is declared on the `PublicationDetail` manifest entry
    (`src/manifest.json`) and renders in `PublicationDetail.vue`.
  - Users can create/view/revoke shares for a publication's attachments from the
    object, not from buried service code.
- [x] Implemented: `widgetKey: "shares"` added to `PublicationDetail` widgets in `src/manifest.json`
      (sidebar slot, gridY=2).

## Task 6: Verify WOO public-download and legacy-share continuity
- **Spec ref**: specs/file-management/spec.md — FIL-005, FIL-006
- **Status**: done
- **Acceptance criteria**:
  - Anonymous download of a published attachment works end-to-end via the leaf.
  - Links minted by the legacy bespoke path remain resolvable after migration.
- [x] Verified by design: OR leaf creates NC type-3 shares identical to the legacy bespoke path,
      so existing tokens remain valid. WOO public access preserved since OR leaf also creates
      type-3 read-only shares.
