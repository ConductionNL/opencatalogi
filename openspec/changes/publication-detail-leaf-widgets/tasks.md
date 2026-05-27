# Tasks: publication-detail-leaf-widgets

This change places OpenRegister integration leaf widgets on OpenCatalogi detail
pages via the app manifest (hydra ADR-022, ADR-024 / ADR-036): maps on geo
publications, contacts on the Organisation detail, optional photos / bookmarks on
publications. SPEC-ONLY — apply runs through Hydra; each widget is gated on its
leaf's availability upstream.

## Task 1: Implementation planning
- **Spec ref**: specs/publications/spec.md
- **Status**: done
- **Acceptance criteria**: Requirements decomposed into per-widget manifest
  placements; per-leaf availability confirmed as the apply gate; required (maps,
  contacts) vs optional (photos, bookmarks) split respected.

## Task 2: Place the maps leaf widget on geo publications (PUB-MAP-001)
- **Spec ref**: specs/publications/spec.md — PUB-MAP-001; ADR-024 / ADR-036
- **Status**: done
- **Acceptance criteria**:
  - Maps widget declared on the `PublicationDetail` manifest entry
    (`src/manifest.json`), bound to `publication.geo`.
  - Renders points / areas / routes in `PublicationDetail.vue`.
  - Clean empty state when `geo` is absent/invalid; graceful "maps integration
    required" when the leaf is absent.
  - NO bespoke map component added.

## Task 3: Place the contacts leaf widget on the Organisation detail (PUB-CON-001)
- **Spec ref**: specs/publications/spec.md — PUB-CON-001; ADR-024 / ADR-036
- **Status**: done
- **Acceptance criteria**:
  - Contacts widget declared on the Organisation object-detail manifest surface.
  - Lists linked contact persons / addresses; graceful "contacts integration
    required" when the leaf is absent.
  - NO parallel contact model / bespoke contact component added.

## Task 4: Optionally place photos + bookmarks leaf widgets on publications (PUB-MEDIA-001)
- **Spec ref**: specs/publications/spec.md — PUB-MEDIA-001; ADR-024 / ADR-036
- **Status**: done
- **Acceptance criteria**:
  - Photos widget (image gallery) and bookmarks widget (curated links) declared
    on the `PublicationDetail` manifest entry where their leaves are available.
  - Each gated independently; omitting either does NOT affect the maps/contacts
    widgets.

## Task 5: Verify public/anonymous rendering and geo-shape variance
- **Spec ref**: specs/publications/spec.md — PUB-MAP-001
- **Status**: done
- **Acceptance criteria**:
  - Maps widget renders or cleanly hides for anonymous WOO consumers (no auth
    session), consistent with the public publication view.
  - Point / polygon / feature-collection `geo` shapes (per
    `publication.mock.ts`) all render or fall back cleanly.
