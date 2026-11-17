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
