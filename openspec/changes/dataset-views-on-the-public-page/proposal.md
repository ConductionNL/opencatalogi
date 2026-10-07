---
kind: code
depends_on: [open-data-table-query-and-dictionary]
---

# Proposal: dataset-views-on-the-public-page

## Why

An organisation publishes the playgrounds of a town as a CSV. A reader who opens the publication sees a file to download. They cannot see the rows, cannot see that most playgrounds are in the north, and cannot see where they are.

opencatalogi matrix, row `od-visualise`, "Show a dataset as a table, chart or map on its public page." Own rating no, built.state `none`, owner `ConductionNL/portaliq`.

- Own evidence: "grep chart/visuali/leaflet/maplibre: opencatalogi hits only dashboard widgets (src/composables/useChartColors.js); portaliq hits only traffic widgets (src/widgets/Traffic*.vue); PublicationDetailBlock.vue has no dataset preview".
- CKAN shows a table view by default and has no chart in core; DKAN renders the rows as a table through its external frontend and has no chart or map. No competitor in the matrix is rated yes on the whole row.

Ownership is split today, and the two matrices disagree. portaliq's gap decision for its sibling row `sib-opencatalogi-od-visualise` (28 Sep) says "Dataset visualisation is opencatalogi's publication surface". opencatalogi's gap decision for `publication-theme-pages` says public rendering is portaliq's (ADR-086, ADR-109). Both are right about one half. This change takes the opencatalogi half: what a view is, who defines it and what data it serves. The portaliq half is the block that draws it, listed as cross-repo tasks.

## What changes

- An editor adds one or more views to a published table: a table of chosen columns, a bar or line chart of one column against another, or a map from a latitude and a longitude column.
- Each view is stored with the table and listed in the public publication answer.
- A public endpoint answers each view's data: rows for a table, grouped values for a chart, GeoJSON for a map. It answers only while the publication is public.
- portaliq's publication block draws the views. Every chart and map also offers its data as a table, so a screen reader user gets the same facts.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| opencatalogi | `od-visualise` | Show a dataset as a table, chart or map on its public page. | no | no view definition, no chart or map data, no public rendering |

## Existing work it builds on

- `open-data-table-query-and-dictionary` (open change in this repo): the `publishedTable` schema, the rows held as OpenRegister objects, and the public rows endpoint `GET /api/{catalogSlug}/{publicationId}/tables/{tableId}/rows`. A view is always a view of such a table, so this change cannot start before that one is merged.
- OpenRegister's grouped aggregation (`GET /api/objects/aggregations/{register}/{schema}/grouped`, used by `dashboard-consume-or-aggregations`). A chart's numbers come from it, not from a new aggregation in this app (ADR-022).
- Main spec `publications`, PUB-MAP-001: the editor side shows maps through the OpenRegister maps leaf, never a bespoke Leaflet component. The public map is drawn by portaliq.

## Out of scope

- Views of a file that is not a published table. A PDF or a spreadsheet that was not turned into a table has no rows to show.
- Free chart styling. The kinds are fixed: table, bar, line, map.
- Maps of polygons from a geometry column. Points from two columns first; areas follow when `openregister/geometry-on-a-map` lands.

## Open for Ruben

The two gap decisions above point at each other. This change assumes the split written here: opencatalogi defines and serves, portaliq draws. If portaliq's `decided-no` for its sibling row stands, tasks 4.1 to 4.3 have no home and the row cannot reach yes.
