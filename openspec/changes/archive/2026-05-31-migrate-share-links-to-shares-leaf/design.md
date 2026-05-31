# Design: migrate-share-links-to-shares-leaf

## Context

`lib/Service/FileService.php` carries three share methods:

| Method | Spec | Behaviour |
|---|---|---|
| `createShareLink(string $path, ?int $shareType=3, ?int $permissions=null)` | FIL-005 | Resolves the user folder, fetches the file, calls private `createShare()` against `OCP\Share\IManager`, returns the URL. Defaults: type 3 (public), permissions 1 (read-only). |
| `findShare(string $path, ?int $shareType=3)` | FIL-006 | Looks up an existing `IShare` for a path. |
| `getShareLink(IShare $share)` | FIL-007 | Assembles `{protocol}://{host}/index.php/s/{token}`. |

These are called from the auto-publishing path (`EventService` →
`handleObjectCreateEvents` / `handleObjectUpdateEvents`) to make attachment files
publicly downloadable, and indirectly by the publication/attachment views.

## Decision: consume the OR shares leaf

Per **hydra ADR-022**, sharing files attached to OR objects is an OR
abstraction (shares leaf, ADR-019). OpenCatalogi MUST consume it:

1. **Backend** — replace the bespoke `IManager` calls with calls to the shares
   leaf's PHP service via DI (thin adapter only if needed). The leaf owns share
   creation, lookup, and URL resolution for files attached to an OR object.
2. **Frontend** — place the shares leaf widget on the publication detail page
   via the manifest (`detail.config.sidebarTabs[].widgets[].type: "shares"`,
   ADR-024 / ADR-036), giving users a real share-management surface.
3. **Auto-publishing** — when `auto_publish_attachments` is enabled, the listener
   requests a public share through the leaf instead of `createShareLink()`.

The bespoke `createShareLink()` / `findShare()` / `getShareLink()` and the
private `createShare()` helper are removed once all callers route through the leaf.

## Why not keep the bespoke path

- The leaf gains expiry / password / download-limit / internal-recipient shares
  that the bespoke method will never track (ADR-022 "missed features").
- Hand-built URL assembly drifts from the leaf's canonical resolution.
- A second sharing mechanism on OR-owned files is a review-blocking anti-pattern
  under ADR-022 ("app-local linked-files mechanism that mirrors an OR integration").

## Kept in-app (documented ADR-022 exceptions)

These are stated here so reviewers do not flag them as un-migrated:

- **Public-facing CMS layer (Pages / Menus / Themes / Glossary).** This is
  anonymous web rendering of catalogue websites, NOT an authenticated
  object-detail tab. There is no shares-leaf equivalent for "render a public CMS
  page"; it stays in OpenCatalogi.
- **PDF / ZIP `DownloadService`.** Bundling a publication into a downloadable
  PDF/ZIP archive has no OR leaf equivalent (DocuDesk is the document-generation
  partner). The DownloadService keeps its own file orchestration; only the
  *share-link* primitives migrate.

## Migration / sequencing

1. Land the OR shares leaf (upstream, ADR-019). This change's apply is blocked
   on it.
2. Re-point auto-publishing and any view callers to the leaf service.
3. Remove `createShareLink()` / `findShare()` / `getShareLink()` / `createShare()`
   from `FileService`.
4. Add the shares widget to the publication detail manifest entry.

## Risks

- **Public anonymous download must keep working.** WOO requires unauthenticated
  download of published attachments; verify the leaf's public-share output yields
  the same anonymous-accessible URL the bespoke path produced.
- **Existing live shares.** Links already minted by the bespoke path must remain
  resolvable; the leaf's `findShare`-equivalent must discover them (they are
  ordinary NC type-3 shares, so this should hold — verify at apply).

## Status

status: pr-created
