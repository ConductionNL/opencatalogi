# Open data tables: query the rows of a CSV and read its data dictionary

A publication's CSV attachment can be published as a **table**. Its rows are then
OpenRegister objects that anyone who may read the publication can query through
the public API, page by page, without downloading the file. Each column has a
title, a description and a type: the table's **data dictionary**.

Spec: `openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md`
(REQ-ODT-001 to REQ-ODT-003).

## Publishing a table (editors)

1. Open the publication and add the CSV as an attachment.
2. In the **Tables** card, choose **Publish as table** next to the CSV.
3. The app makes one column per header field, guesses each column's type from
   the first 200 rows, and imports every row through OpenRegister's import. The
   import is previewed first: when it refuses a row, the table shows
   *Not published* with that row, and no row is written.
4. Once the import is done the card shows the row count, a link to try the query
   in the browser, and the dictionary.

Edit a column's title or description in the dictionary and choose
**Save dictionary**. The column names and types stay as the import made them.

Only users who may change the publication can publish a table or edit its
dictionary. The editor endpoints are:

| Request | What it does |
| --- | --- |
| `GET /api/publications/{id}/tables` | The publication's tables, whatever their status, with each ready table's dictionary |
| `POST /api/publications/{id}/tables` with `fileId` | Publish that CSV attachment as a table |
| `PUT /api/publications/{id}/tables/{tableId}/dictionary` with `columns: [{name, title, description}]` | Write column titles and descriptions |

## Querying a table (anyone)

```bash
curl -s "https://example.org/index.php/apps/opencatalogi/api/speeltuinen/{publicationId}/tables/{tableId}/rows?wijk=Centrum&_order[naam]=asc&_limit=10"
```

- Filter on any declared column with `column=value`, search all text with
  `_search`, sort with `_order[column]=asc|desc`, and page with `_limit` (at most
  1000) and `_page`.
- The answer holds `results`, `total`, `page` and `pages`.
- A filter on a column the table does not have is answered with `400` and the
  unknown column names.
- The rows of a table are only readable while its publication is: a draft or
  withdrawn publication's table answers `404` to anonymous readers.

## The data dictionary

```bash
curl -s "https://example.org/index.php/apps/opencatalogi/api/speeltuinen/{publicationId}/tables/{tableId}/dictionary"
```

answers each column's `name`, `title`, `description` and `type`.

The public publication answer (`GET /api/{catalogSlug}/{publicationId}`) lists
its tables under `tables`, each with `rowsUrl` and `dictionaryUrl`. In the DCAT
feed the CSV's distribution carries `dct:conformsTo` with the dictionary URL, so
harvesters find it.

## Views: a table, chart or map on the public page

An editor can show a published table on the public publication page in one or
more views (dataset-views-on-the-public-page). A view is one of four kinds:

| Kind | What it shows | What it needs |
|---|---|---|
| `table` | the rows, with only the chosen columns | `columns`, in order |
| `bar`, `line` | one value per group of a column | `x`, and `aggregate` `count`, `sum` or `avg`; `y` for a sum or an average |
| `map` | a point per row | `latitude` and `longitude` (WGS84), optionally `label` |

Editors who may change the publication add, change and remove views through
`GET|POST /api/publications/{id}/tables/{tableId}/views` and
`DELETE /api/publications/{id}/tables/{tableId}/views/{viewId}`. A view that names
a column the table does not have, sums or averages a column that does not hold
numbers, or uses a coordinate column that does not hold numbers is refused with
`400`, and the message names the column.

On the publication page, each ready table lists its views under "Views on the
public page", in the order readers see them. "Show on the public page" opens the
view dialog: choose how to show the table, give the view the title readers see,
and pick the columns for that kind. The pickers for a sum, an average and the map
coordinates offer only the columns that hold numbers. After a save the dialog
previews what readers get: the rows on the first page, the groups in the chart or
the points on the map, with a warning when a chart is cut at 200 groups or a map
at 5,000 points, and the number of rows a map leaves out for want of usable
coordinates. The preview reads the public data answer, so it shows only while
the publication is public. Each listed view has Edit, Move up, Move down and
Remove.

Anyone reads the views of a public publication:

```bash
curl -s "https://example.org/index.php/apps/opencatalogi/api/speeltuinen/{publicationId}/tables/{tableId}/views"
curl -s "https://example.org/index.php/apps/opencatalogi/api/speeltuinen/{publicationId}/tables/{tableId}/views/{viewId}/data"
```

- A `table` view answers the first page of rows with only its `columns`.
- A `bar` or `line` view answers `points`, one `{x, value}` per group, from
  OpenRegister's grouped aggregation; more than 200 groups answers the first 200
  with `truncated: true`.
- A `map` view answers a GeoJSON `FeatureCollection` of at most 5,000 points.
  Rows with an empty or out-of-range coordinate are left out and counted in
  `skipped`.
- Both routes answer `404` unless the publication is public at that moment.

The public publication answer lists each table's `views`, each with its
`dataUrl`. The portal draws them and offers every chart and map as a table too.
