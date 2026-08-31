# Change: publication-detail-leaf-widgets

## Why

OpenCatalogi's detail pages (Publication, Organisation) render OR-backed objects
but surface none of OpenRegister's integration **leaves** that fit their data
shape. Per **hydra ADR-022** (apps consume OR abstractions over local
duplication) and **ADR-024 / ADR-036** (declarative manifest widget placement),
the way to add these capabilities is to *place leaf widgets via the app
manifest* — not to build bespoke map / contact components in-app.

Two leaves map directly onto existing OpenCatalogi data:

- **Maps leaf** — `Publication.geo` already carries GeoJSON
  (`lib/Migration/Version6Date20241011085015.php` defines the `geo` property;
  `publication.ts` types it). Geo publications (locations, areas, routes) should
  show their geometry on a map widget instead of a raw JSON blob.
- **Contacts leaf** — an Organisation is a contactable entity (the
  responsible bestuursorgaan behind publications). The OR contacts leaf surfaces
  linked contact persons / addresses on the Organisation detail, replacing
  ad-hoc free-text contact fields.

Optionally, **photos** (image attachments as a gallery) and **bookmarks**
(curated external links per object) are leaves that also fit Publications and may
be placed in the same change where they add value.

This is a **net-new consume** change (no bespoke code is being replaced — these
capabilities don't exist yet), placing leaf widgets on the relevant detail pages
via the manifest.

## What Changes

- **Place the maps leaf widget** on the publication detail page for publications
  that carry `geo` GeoJSON, via the `PublicationDetail` manifest entry
  (`detail.config.sidebarTabs[].widgets[].type: "maps"` / a `type: "map-viewer"`
  widget bound to `publication.geo`), surfaced in
  `src/views/publications/PublicationDetail.vue`.
- **Place the contacts leaf widget** on the Organisation detail (the
  manifest-driven Organisation object detail / `OrganizationIndex` surface),
  binding to the Organisation's linked contacts.
- **Optionally place the photos and bookmarks leaf widgets** on the publication
  detail page where they add value (image-gallery + curated links). Included as
  optional manifest placements; reviewers may drop them if a leaf is unavailable.
- **No bespoke map/contact/photo/bookmark code** is added — all placements are
  manifest-declared leaf widgets (ADR-024 / ADR-036).

## Impact

- Affected specs: `publications` (adds geo-map + optional photos/bookmarks widget
  placement requirements); a small `content-management`/organisation requirement
  for the contacts widget on the Organisation detail.
- Affected code: `src/manifest.json` (widget placements), and the detail views
  they render in (`src/views/publications/PublicationDetail.vue`, the Organisation
  detail surface). No new PHP.
- Dependency: OpenRegister maps / contacts (and optional photos / bookmarks)
  leaves (integration registry, ADR-019). Apply per-widget is blocked on each
  leaf's availability; this is a SPEC-ONLY change.
