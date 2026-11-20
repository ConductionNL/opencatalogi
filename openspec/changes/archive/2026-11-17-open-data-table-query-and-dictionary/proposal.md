---
kind: code
depends_on: []
---

# Proposal: open-data-table-query-and-dictionary

## Why

An organisation that publishes a spreadsheet, such as the addresses of all playgrounds or a year of parking permits, publishes it as a file. A developer who wants ten rows must download all of it, and a reader cannot see what each column means.

Two rows in the opencatalogi matrix, both rated no with nothing built.

- Row `od-datastore`, "Query the rows inside a published table through an API without downloading the file." Own evidence: "grep -i datastore in opencatalogi lib/src and portaliq src/lib: 0 hits; no row-query route in appinfo/routes.php".
- Row `od-dictionary`, "Describe the columns of a dataset in a data dictionary readers can see." Own evidence: "grep 'dictionary|dataDictionary' in opencatalogi lib/src and portaliq: 0 relevant hits; no column-description property on the publication schema (publication_register.json:93)".

What the competitors show, quoted from the matrix:

| row | system | evidence |
|---|---|---|
| `od-datastore` | CKAN, yes | "the datastore plugin (setup.cfg:65) serves rows of an uploaded table over /api/action/datastore_search with filters, full text and sorting (ckan/ckanext/datastore/logic/action.py:651) and SQL queries (datastore_search_sql, :778); 2.12 adds range and nested AND/OR filters and keyset pagination" |
| `od-datastore` | DKAN, yes | "CSV and TSV distributions are imported into database tables ... and queried without download through /api/1/datastore/query/{dataset}/{index}, /api/1/datastore/query/{identifier} and the SQL endpoint /api/1/datastore/sql, all under 'access content'" |
| `od-dictionary` | CKAN, yes | "for datastore tables the resource page shows the data dictionary with field labels and descriptions (ckan/ckanext/datastore/templates/package/resource_read.html:34-38, snippet datastore/snippets/dictionary_view.html), editable at /dataset/<id>/dictionary/<resource_id>" |
| `od-dictionary` | DKAN, yes | "data dictionaries are a metastore item kind (schema/collections/data-dictionary.json) ... readers get them without login at /api/1/metastore/schemas/data-dictionary/items/{id}" |

No demand row. Both rows are `build` under the rule "two or more competitors rated yes". They share one store, the table's rows, so they are one change.

## What changes

- An editor turns a CSV attachment of a publication into a published table. Its rows go into OpenRegister as objects of a schema made from the file's header, through OpenRegister's own import.
- Anyone can query the rows of a published table through a public endpoint, with filters, sorting, a text search and pages, without downloading the file.
- Each column has a title, a description and a type that the editor can edit. Readers see this data dictionary on the publication and through the API, and the DCAT feed links to it.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| opencatalogi | `od-datastore` | Query the rows inside a published table through an API without downloading the file. | no | no row store, no query endpoint |
| opencatalogi | `od-dictionary` | Describe the columns of a dataset in a data dictionary readers can see. | no | no column descriptions anywhere |

## Existing work it builds on

- OpenRegister's register import (`POST /api/registers/{id}/import`) with its import preview (`/api/import-previews`, `ImportPreviewService`, `SourceRowReader`), read on OpenRegister development. The rows are imported by it, not by new code here (ADR-011, ADR-022).
- OpenRegister's object query: filters, `_order`, `_search`, `_limit` and `_page`, the same vocabulary the public publication API passes through.
- Main spec `dcat-ap-harvest` DCAT-006 (attachments as distributions).
- Row `od-table-download` (owned by openregister): filtered download of rows as CSV and other formats. Once rows are OpenRegister objects, that export applies to them.

## Out of scope

- SQL queries over tables. The filter vocabulary is the contract.
- Spreadsheets other than CSV and TSV in the first version.
- Harvesting tables from other portals.
