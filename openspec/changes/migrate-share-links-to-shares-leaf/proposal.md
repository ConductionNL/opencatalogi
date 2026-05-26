# Change: migrate-share-links-to-shares-leaf

## Why

OpenCatalogi rolls its own public share-link creation in
`lib/Service/FileService.php` — `createShareLink()` (FIL-005), `findShare()`
(FIL-006), and `getShareLink()` (FIL-007) directly drive Nextcloud's
`OCP\Share\IManager` to mint type-3 public links, resolve permissions, and
assemble the public URL. This is exactly the "app-local linked-files /
sharing mechanism that mirrors an OR integration" anti-pattern called out in
**hydra ADR-022**: OpenRegister now exposes a **shares leaf** in its
integration registry (ADR-019) that owns public/internal share creation,
discovery, expiry, and password protection for files attached to OR objects,
and surfaces them as a share widget/tab on the object detail page (ADR-024).

Keeping a parallel `IManager` path in OpenCatalogi means:

- **Missed features** — the shares leaf gains expiry, password, download-limit,
  and per-recipient internal shares; the bespoke `createShareLink()` never does.
- **Drift** — OpenCatalogi's hand-built `{protocol}://{host}/index.php/s/{token}`
  URL assembly diverges from the leaf's canonical link resolution.
- **No UI parity** — share management is buried in the auto-publishing code path
  with no user-facing surface on the attachment/publication detail page.

This change migrates OpenCatalogi to **consume the OR shares leaf** for
attachment/publication file sharing, surfaced as the shares widget on the
publication detail page, and retires the bespoke `FileService` share methods.

## What Changes

- **Consume the OR shares leaf** for creating, finding, and resolving public
  share links on files attached to Attachment / Publication objects, replacing
  `FileService::createShareLink()`, `findShare()`, and `getShareLink()`.
- **Surface the shares leaf widget** on the publication detail page
  (`src/views/publications/PublicationDetail.vue`) via the app manifest
  (ADR-024) so users manage shares from the object, not from buried service code.
- **Re-point auto-publishing** (`EventService` / the auto-publish listeners) to
  request shares through the leaf instead of the bespoke method when
  `auto_publish_attachments` mints a public link.
- **Retire** FIL-005 / FIL-006 / FIL-007 from the `file-management` spec, marking
  them consumed-from-leaf rather than in-app.

## Impact

- Affected specs: `file-management` (FIL-005/006/007 → consumed from shares leaf).
- Affected code: `lib/Service/FileService.php` (share methods removed),
  `lib/Service/EventService.php` (auto-publish share call re-pointed),
  `src/views/publications/PublicationDetail.vue` + `src/manifest.json` (shares
  widget placement).
- Dependency: OpenRegister shares leaf (integration registry, ADR-019). Apply is
  blocked until the leaf is available; this is a SPEC-ONLY change.
