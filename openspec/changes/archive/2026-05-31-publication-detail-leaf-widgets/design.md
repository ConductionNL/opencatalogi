# Design: publication-detail-leaf-widgets

## Context

OpenCatalogi detail pages render OR-backed objects via the manifest-driven
`CnDetailPage` (ADR-024 / ADR-036). Today the `PublicationDetail` manifest entry
(`src/manifest.json`) places only built-in `data` / `file-manager` / `metadata`
widgets. The Organisation detail is likewise a plain object detail.

Two pieces of existing data are ripe for a leaf widget:

- **`Publication.geo`** — GeoJSON, defined by
  `lib/Migration/Version6Date20241011085015.php` (`name: 'geo'`) and typed in
  `src/entities/publication/publication.ts`. Currently shown (if at all) as raw
  JSON.
- **Organisation** — the contactable bestuursorgaan behind publications, with
  ad-hoc contact text rather than structured linked contacts.

## Decision: place leaf widgets declaratively via the manifest

Per **hydra ADR-022** + **ADR-024 / ADR-036**, these capabilities are added by
referencing OR integration leaves as manifest widgets — NOT by writing bespoke
Vue map / contact components:

1. **Maps leaf → publication detail.** Add a maps widget to the
   `PublicationDetail` manifest entry, bound to `publication.geo`. Renders the
   GeoJSON geometry (point / area / route) on a Leaflet-backed map. For
   publications without `geo`, the widget hides / shows an empty state. Placement
   form follows the existing `widgets[].widgetKey` / `sidebarTabs[].widgets[].type`
   convention already used in `src/manifest.json`.
2. **Contacts leaf → Organisation detail.** Add a contacts widget to the
   Organisation object-detail manifest surface, binding to the Organisation's
   linked OR contacts. Replaces free-text contact fields with the structured
   contacts leaf.
3. **Optional: photos + bookmarks → publication detail.** Photos surfaces image
   attachments as a gallery; bookmarks surfaces curated external links per
   publication. Optional placements — reviewers MAY omit either if its leaf is
   not yet available.

All placements are manifest-declared. No new PHP, no bespoke map/contact JS.

## Why declarative leaf widgets, not bespoke components

- A hand-built Leaflet map or contact card on OR objects is the ADR-022
  "app-local mechanism that mirrors an OR integration" anti-pattern.
- Manifest placement means OpenCatalogi inherits future leaf improvements
  (clustering, WMS/WFS layers for maps; address validation for contacts) with
  zero per-app work.

## Kept in-app (documented ADR-022 exceptions)

Stated so reviewers do not flag them as un-migrated:

- **Public-facing CMS layer (Pages / Menus / Themes / Glossary).** This is
  anonymous web rendering of catalogue websites — NOT an authenticated
  object-detail tab — and has no leaf equivalent. It is NOT migrated to a leaf
  and stays in OpenCatalogi.
- **PDF / ZIP `DownloadService`.** Bundling a publication into a downloadable
  PDF/ZIP archive has no OR leaf equivalent (DocuDesk is the document-generation
  partner). Stays in-app.

## Status

`status: pr-created`

## Dependencies / sequencing

- Maps leaf, contacts leaf (required); photos / bookmarks leaves (optional) — all
  from the OR integration registry (ADR-019). Each widget's apply is gated on its
  leaf being available; widgets land incrementally as leaves ship.

## Risks

- **`geo` shape variance.** `publication.geo` may hold point vs polygon vs
  feature-collection; the maps widget must handle each or show a clean empty
  state. Verify against `publication.mock.ts` fixtures at apply.
- **Public/anonymous rendering.** Publication detail is reachable by anonymous
  WOO consumers; confirm the maps widget renders (or cleanly hides) without an
  authenticated session, consistent with the rest of the public publication view.
- **Optional widgets gated independently.** Photos / bookmarks must not block the
  maps + contacts placements if their leaves are not yet available.
