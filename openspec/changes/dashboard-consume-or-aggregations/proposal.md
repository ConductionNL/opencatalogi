---
kind: code
depends_on: []
---

# Proposal: dashboard-consume-or-aggregations

## Why

`openspec/specs/dashboard/spec.md` declares two requirements as **Status:
Implemented (aggregation citation added by Phase 7)**:

- **DSH-010** ("Dashboard overview view") — "The total publication count MUST
  be sourced from OR's `x-openregister-aggregations` declaration on the
  publications schema... NOT from a bespoke count query."
- **DSH-011** ("Unpublished-content dashboard widgets") — "Counts MUST come
  from OR schema aggregations (DSH-OR-001)."
- **DSH-OR-001** ("dashboard widgets consume OR aggregations") — "Every
  dashboard widget that displays a count or histogram... MUST consume the
  corresponding OR schema aggregation declared on the relevant schema via
  `x-openregister-aggregations`."

The live code at HEAD does not honour any of the three:

- `src/views/dashboard/Dashboard.vue:508-517` (`fetchAllPublications()`) issues
  `GET /api/publications?_page=1&_limit=1000&_extend=@self.schema,@self.register`
  — a bespoke, **hard-capped-at-1000** fetch of full publication objects — and
  stores the raw list in `objectStore`.
- `src/views/dashboard/Dashboard.vue:411-420` (`kpis()`) computes
  `publicationCount`, `conceptPublicationCount`, `publishedPublicationCount`,
  and `depublishedPublicationCount` by client-side `.length` / `.filter()`
  over that capped list (`allPublications`, `conceptPublications`,
  `publishedPublications`, `depublishedPublications`, all defined at
  `Dashboard.vue:388-402`).
- `src/views/dashboard/Dashboard.vue:422-440` (`publicationsByCategoryData()`)
  builds the "Publications by Category" donut chart by looping the same
  capped list and tallying `schemaRef` occurrences in JS.
- `src/views/widgets/UnpublishedAttachmentsWidget.vue:50-58` fetches the
  **entire** `attachment` collection via `objectStore.fetchCollection('attachment')`
  and filters client-side for `status === 'Concept'`.
- `src/unpublishedPublicationsWidget.js` / `UnpublishedPublicationsWidget.vue`
  follow the identical full-collection-fetch-then-filter pattern.
- **The data source is additionally coupled to a seed-data coincidence.**
  There is no `/api/publications` route: the URL only works because it matches
  the wildcard `['name' => 'publications#index', 'url' => '/api/{catalogSlug}']`
  (`appinfo/routes.php:163`) with `catalogSlug = "publications"`, which
  resolves solely because the seed data ships a default catalog with
  `"slug": "publications"` (`lib/Settings/publication_register.json:1326-1333`).
  If an admin renames or deletes that catalog, `getCatalogBySlug()` returns
  null, `publications#index` 404s
  (`lib/Controller/PublicationsController.php:416-418`), and every dashboard
  KPI silently reads zero. On multi-catalog installs the "all publications"
  numbers only count the default catalog's scope
  (`buildCatalogSearchQuery` scopes to that one catalog's schemas/registers).

None of these paths call OpenRegister's declarative aggregation endpoint
(`GET /api/objects/aggregations/{register}/{schema}/{name}`, confirmed live at
`openregister/appinfo/routes.php:325-330` and already consumed by other
Conduction apps, e.g. shillinq's `x-openregister-aggregations` schema
annotations feeding `CashflowDashboard.vue`/`BBVComplianceDashboard.vue`).
Instead every count is derived from a raw object list capped at 1000 rows —
which is both a **spec violation** (DSH-010/DSH-011/DSH-OR-001) and a real
**correctness bug**: any catalog with more than 1000 publications silently
shows wrong (truncated) counts and an incomplete "Publications by Category"
chart, with no error or warning surfaced to the user.

The same scaling concern is explicitly called out in the very code that was
disabled for it: `Dashboard.vue:98-115` and `:204-215` comment out the
"Concept Attachments" widget with `// TODO: Re-add concept attachments widget
once a scalable fetch strategy is in place. Fetching files per-publication
does not scale for large catalogs. Do NOT remove this code.` The declarative
aggregation is exactly the "scalable fetch strategy" the TODO is waiting for —
today's fix restores that widget for free.

## What Changes

- Declare `x-openregister-aggregations` on the `publication` schema
  (`countByStatus` grouped by publication status, `countBySchema` grouped by
  `@self.schema`) in opencatalogi's register configuration
  (`lib/Settings/register.d/`), and on the `attachment` schema
  (`countByStatus` grouped by attachment status) — mirroring shillinq's
  declaration shape (`field`, `operation`, `groupBy`, `description`).
- Replace `Dashboard.vue`'s `fetchAllPublications()` bespoke `_limit=1000`
  fetch-and-count with calls to
  `GET /api/objects/aggregations/{register}/{schema}/grouped` (or the
  single-value `/value` endpoint for the plain total) for every KPI count
  currently derived from `allPublications.length` / `.filter().length`.
- Replace the "Publications by Category" donut chart's client-side tally
  (`publicationsByCategoryData()`) with the `countBySchema` aggregation
  result.
- Replace `UnpublishedAttachmentsWidget.vue` and
  `UnpublishedPublicationsWidget.vue`'s full-collection
  fetch-then-client-filter with the corresponding aggregation call.
- Re-enable the "Concept Attachments" dashboard widget (currently commented
  out at `Dashboard.vue:98-115`, `:204-224`, `:510-524`, `:409-410`,
  `:452-453`) backed by the new `attachment` `countByStatus` aggregation —
  removing the `// Do NOT remove this code` TODOs once the replacement ships.
- BREAKING: any operator relying on the current `_limit=1000` behaviour to
  eyeball "roughly how many publications" via network inspection will instead
  see the exact aggregated count; no API contract is removed since
  `GET /api/publications` is untouched (still used elsewhere, e.g. list
  views), only the dashboard's *count-sourcing* changes.
- Remove the dashboard's dependency on the literal `/api/publications` URL
  (the seed-slug coupling above): aggregation reads address the configured
  publication register/schema directly, catalog-slug independent; document
  any remaining legitimate uses of the default catalog slug.
- Update `openspec/specs/dashboard/spec.md`'s DSH-010/DSH-011/DSH-OR-001
  "Status: Implemented" annotations to reflect the corrected implementation
  once this change lands (tracked in the spec delta below).
