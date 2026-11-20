# Design: open-data-table-query-and-dictionary

Read at opencatalogi development `9aa54150`; OpenRegister routes and import services read on OpenRegister development on 2026-09-27.

## Where it lands

| piece | file | what is there now |
|---|---|---|
| publication and its files | schema `publication` in `lib/Settings/publication_register.json`; files through OpenRegister's files integration (`pub-files` on `PublicationDetail` in `src/manifest.json`) | a CSV is a file like any other |
| public API | `lib/Controller/PublicationsController.php:433` `index()` and `:603` `show()` under `/api/{catalogSlug}` (`appinfo/routes.php:251-252`), `#[PublicPage]` with `#[AnonRateLimit]` | publications, not rows |
| DCAT | `lib/Service/DcatMappingService.php:356` `mapDistribution()` | one distribution per file, no schema link |
| OpenRegister import | `POST /api/registers/{id}/import`, `POST /api/import-previews` and `/{id}/commit`; `lib/Service/Import/SourceRowReader.php`, `SchemaMappingCheck.php`, `ImportPreviewService.php` | reads CSV rows into a schema's objects, previews before writing |
| OpenRegister schemas | opencatalogi reaches `OCA\OpenRegister\Db\SchemaMapper` through the container (`lib/Service/OoapiService.php:110`) | available |

## D1. A published table is a schema plus its rows

A new schema `publishedTable` in `lib/Settings/register.d/open-data-tables.json` (publication register): `publication` ($ref), `file` (the CSV's file id), `title`, `schema` (the OpenRegister schema id that holds the rows), `rowCount`, `importedAt`, `status` (`importing`, `ready`, `failed`), `lastError`. The rows live in a register `open-data-tables` that the app provisions, one schema per table, named `table-<publicationSlug>-<n>`.

## D2. Turning a CSV into a table uses OpenRegister's import

The editor presses Publish as table on a CSV attachment. `PublishedTableService`:

1. reads the header and the first 200 rows through OpenRegister's `SourceRowReader`, guesses each column's type (integer, number, boolean, date, string), and creates the schema with one property per column (title = header, `description` empty, `facetable` true for columns with fewer than 50 distinct values in the sample);
2. starts an OpenRegister import preview of the whole file into that schema, and commits it when the preview refuses no row, else stores `failed` with the preview's first refused row as `lastError`;
3. sets the schema's read rule to public only while the publication is public, the same published predicate the publication uses.

Large files import in OpenRegister's pages; the table shows `importing` until done.

## D3. The query endpoint

`GET /api/{catalogSlug}/{publicationId}/tables/{tableId}/rows`, `#[PublicPage]`, `#[AnonRateLimit]`, passes the OpenRegister filter vocabulary (`field=value`, ranges, `_search`, `_order`, `_limit` up to 1000, `_page`) to `ObjectService::searchObjectsPaginated()` on the table's schema with RBAC on, and answers rows with `total`, `page`, `pages`. It refuses a filter on a property the schema does not declare with 400 (OpenRegister would otherwise match nothing). CORS as the publication list.

## D4. The dictionary is the schema, edited in place

`GET .../tables/{tableId}/dictionary` answers each column's name, title, description and type from the schema. The publication page gets a Tables section: each table with its row count, a link to try the query in the browser, and the dictionary as an editable table for editors (writing the schema's property titles and descriptions through OpenRegister's schema API). The public API's publication answer lists its tables with their rows and dictionary URLs. The DCAT distribution of the CSV gets `dct:conformsTo` pointing at the dictionary URL.

## Declarative or imperative

- `publishedTable` and the generated schemas are declared data.
- Creating a schema from a file and driving the import is imperative (ADR-031 exception: scheduled bulk work and an external file format).
- The query endpoint is a thin pass-through with a scope check, not a second query engine (ADR-022).

## Seed data

None. A table needs a real CSV file, which the register JSON cannot seed.

## Risks

- A CSV with a column that looks numeric in the sample and has text further down. The import preview refuses those rows, the table shows `failed` with the row, and the editor can set the column to text and retry.
- Many tables mean many schemas. Each table is one schema in its own register, so they do not mix with the app's own schemas.
