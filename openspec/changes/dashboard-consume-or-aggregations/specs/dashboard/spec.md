# Dashboard spec delta — dashboard-consume-or-aggregations

## MODIFIED Requirements

### Requirement: dashboard widgets consume OR aggregations (DSH-OR-001)

Every dashboard widget that displays a count or histogram MUST consume the
corresponding OR schema aggregation declared on the relevant schema via
`x-openregister-aggregations` (e.g. publications by status, publications by
category, unpublished attachments count, unpublished publications count),
resolved through OR's `GET /api/objects/aggregations/{register}/{schema}/{name}`
family of endpoints. OpenCatalogi MUST NOT compute aggregation results by
fetching a page of full objects (bounded or unbounded) and counting/filtering
them client-side in JavaScript.

This corrects the prior "Status: Implemented" annotation, which was not
verified against the live code: `Dashboard.vue`, `UnpublishedAttachmentsWidget.vue`,
and `UnpublishedPublicationsWidget.vue` all derived their counts from a
client-side `.filter()`/`.length` over a full (or `_limit=1000`-capped)
object collection prior to this change.

#### Scenario: a dashboard widget is backed by an OR aggregation

- **GIVEN** the `publication` schema declares
  `x-openregister-aggregations.countByStatus`
- **WHEN** `Dashboard.vue` renders the publication-count KPI tiles
- **THEN** each count (`publicationCount`, `conceptPublicationCount`,
  `publishedPublicationCount`, `depublishedPublicationCount`) MUST be read
  from the `countByStatus` aggregation response
- **AND** no request to `GET /api/publications` with `_limit` greater than
  the page size actually needed for on-screen display MUST be the source of
  a KPI count

#### Scenario: aggregation is missing or the request fails

- **GIVEN** the relevant schema has no `x-openregister-aggregations`
  annotation, or the aggregation endpoint returns a non-2xx response
- **WHEN** a dashboard widget attempts to read its count
- **THEN** the widget MUST degrade gracefully (show "N/A" or an empty state)
  rather than silently falling back to a bespoke count query

### Requirement: Dashboard overview view (DSH-010)

The system SHALL provide a `Dashboard.vue` overview. The total publication
count, the concept/published/depublished counts, and the "Publications by
Category" chart data MUST be sourced from OR's `x-openregister-aggregations`
declarations on the `publication` schema (see DSH-OR-001), NOT from a bespoke
count query nor from counting/filtering a fetched object list in JavaScript.

**Priority:** Should **Status:** Not Implemented — tracked by this change.

#### Scenario: dashboard overview shows the publication count

- **GIVEN** `Dashboard.vue` renders the overview
- **WHEN** it displays the total publication count and the per-status KPI
  tiles
- **THEN** every count MUST be sourced from OR's `x-openregister-aggregations`
  declaration, not from `allPublications.length` or a filtered-array
  `.length`

#### Scenario: catalogs with more than one page of publications report correct counts

- **GIVEN** a catalog with more publications than any single bespoke
  page-fetch would return (e.g. more than 1000)
- **WHEN** `Dashboard.vue` renders its KPI tiles and category chart
- **THEN** the counts and chart totals MUST match the true totals, not a
  value truncated by a client-side page-size cap

### Requirement: Unpublished-content dashboard widgets (DSH-011)

The system SHALL provide two Nextcloud dashboard widgets —
`UnpublishedAttachmentsWidget` and `UnpublishedPublicationsWidget` — plus the
OpenCatalogi SPA's own "Concept Attachments" KPI tile. Counts MUST come from
OR schema aggregations (DSH-OR-001), not from fetching the full `attachment`
or `publication` collection and filtering client-side.

**Priority:** Should **Status:** Not Implemented — tracked by this change.

#### Scenario: unpublished widgets render their counts

- **GIVEN** the Nextcloud dashboard shows the opencatalogi widgets
- **WHEN** `UnpublishedAttachmentsWidget` and `UnpublishedPublicationsWidget`
  render
- **THEN** their counts MUST be sourced from OR schema aggregations
- **AND** neither widget MUST call `objectStore.fetchCollection(...)` for the
  full, unfiltered collection as its sole data source

#### Scenario: the Concept Attachments KPI tile is restored

- **GIVEN** the `attachment` schema declares
  `x-openregister-aggregations.countByStatus`
- **WHEN** the OpenCatalogi SPA dashboard renders
- **THEN** the "Concept Attachments" KPI tile (previously disabled pending a
  "scalable fetch strategy") MUST be present and MUST read its count from the
  aggregation, not from a per-publication file-listing loop
