---
kind: code
depends_on: [publications-selection-actions]
---

# Proposal: publication-inspection-and-policy-screens

## Why

- **OcPublicatieInzage** draws a "Public inspection" card on the publication
  page: the comment period with its start, its end and its legal term. The
  `commentPeriod` schema and `POST /api/comment-periods` exist; the page shows
  nothing of it.
- **OcPublicatiebeleid** draws the publication policy: the publication rules,
  the published collections and the banner. Their schemas (`publicationRule`,
  `instanceBanner`) and `GET /api/published-collections` exist; no screen
  shows them.

## What changes

- PublicationDetail gets a `pub-inspection` record list over `commentPeriod`
  filtered on the page's publication (`@objectId`).
- A Publication policy page (`/publication-policy`, admin, settings section of
  the navigation): object lists over `publicationRule` and `instanceBanner`
  with Add, and the published collections from their endpoint.
- Example data: a notice with an inspection period, three rules and a banner.

All manifest configuration over library widgets; no new code.

## Out of scope

- The board's roles and rights section and the board's per-section Save: there
  is no rights backend yet, and the object lists save per row.
- Related publications: the existing `pub-related` card shows the relations.
- Suggested Woo metadata (`woo-metadata-suggestions`, not built).
