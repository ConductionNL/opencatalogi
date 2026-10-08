# Design: dataset-views-on-the-public-page

Read at opencatalogi development `0d89f9dd5`. Board: `OcPublicatieBestanden` on the Zuiddrecht canvas (5NkFW28vZUUij43xzxHg5a). The board shows the file list of a publication with the columns Name, Size, Labels, Status and Actions, and the note "Publish or depublish it from its actions menu". The board has no view dialog yet; the action below joins that actions menu, and the dialog is not designed.

## Where it lands

| piece | file | what is there now |
|---|---|---|
| published table | schema `publishedTable` from `open-data-table-query-and-dictionary` (`lib/Settings/register.d/open-data-tables.json`) | rows as OpenRegister objects, one schema per table |
| rows endpoint | `GET /api/{catalogSlug}/{publicationId}/tables/{tableId}/rows` from the same change | table rows with filters and pages |
| public publication answer | `lib/Controller/PublicationsController.php` `show()` | lists the tables once that change lands |
| file list dialog | the publication's Files tab (`ViewObject`, board `OcPublicatieBestanden`) | per-file publish and depublish |
| public block | portaliq `src/site/components/PublicationDetailBlock.vue` | renders fields; no dataset preview |

## D1. A view is a small object next to its table

A schema `tableView` in `lib/Settings/register.d/dataset-views.json` (publication register):

| property | type | meaning |
|---|---|---|
| `table` | uuid, `$ref` publishedTable | the table it shows |
| `kind` | enum `table`, `bar`, `line`, `map` | how it is drawn |
| `title` | string, required | the heading readers see |
| `columns` | array of string | `table`: the columns shown, in order |
| `x` | string | `bar`, `line`: the column on the horizontal axis |
| `y` | string, optional | `bar`, `line`: the column summed or averaged; empty means count rows |
| `aggregate` | enum `count`, `sum`, `avg` | `bar`, `line` |
| `latitude`, `longitude` | string | `map`: the two coordinate columns, WGS84 |
| `label` | string, optional | `map`: the column shown on a marker |
| `position` | integer | order on the page |

No public read rule on `tableView` itself. The public reads go through the endpoint in D3, which checks the publication.

## D2. Saving a view checks it against the table

`DatasetViewService::save(array $view)` loads the table's schema and refuses with 400 when a named column does not exist, when `y` is not numeric for `sum` or `avg`, or when `latitude` and `longitude` are not numeric. The message names the column. This keeps a view from showing an empty chart because a column was renamed.

## D3. One data endpoint per view

`GET /api/{catalogSlug}/{publicationId}/tables/{tableId}/views` lists the views. `GET .../views/{viewId}/data` answers:

- `table`: the first page of rows with only `columns`, through the rows endpoint's own query path, so filters and pages behave the same.
- `bar`, `line`: `{x, y, points: [{x, value}]}` from OpenRegister's grouped aggregation on the table schema, grouped by `x`, at most 200 groups. More groups answer the first 200 and `truncated: true`.
- `map`: a GeoJSON FeatureCollection of at most 5,000 points with `label` as a property. Rows with an empty or out-of-range coordinate are left out and counted in `skipped`.

Both routes are `#[PublicPage]`, `#[NoCSRFRequired]`, `#[AnonRateLimit]`, with the CORS preflight the other public routes carry. Both answer 404 unless the publication is publicly readable at that moment, checked through the same path as `PublicationsController::show()`.

## D4. The editor adds views from the file list

On the file list (`OcPublicatieBestanden`), a published table's actions menu gains "Show on the public page". It opens `DatasetViewDialog.vue` in `src/modals/`: kind, title and the column pickers for that kind, each an `NcSelect` with `inputLabel`. A preview of the data endpoint shows under the form. Saved views list under the table on the publication page with edit, move and remove.

## D5. portaliq draws the views

`PublicationDetailBlock.vue` reads `views` from the publication answer and renders each under its table:

- `table`: an accessible table with pages, from the data endpoint.
- `bar`, `line`: an SVG chart using the NL Design System tokens, with a "Show as table" toggle that renders the same `points` as a table.
- `map`: the portal's map component when the portal has one, else the table of points. The table toggle is always there.

## Declarative or imperative

The schema and its validation rules are declared. The column check and the data shaping are imperative, because they depend on the table's generated schema.

## Risks

- A chart over a column with thousands of distinct values. The 200-group cap answers and says it truncated; the dialog warns when the preview shows `truncated`.
- A map of personal addresses. The publication itself is the gate: if the table is public, its coordinates already are. The dialog shows the number of points before saving, so the editor sees what goes on the map.
