# open-data-tables Specification

## Purpose
An editor publishes a CSV attachment as a table, so anyone can query its rows through the public API and read what each column means, instead of downloading the whole file.

## Requirements

### Requirement: An editor publishes a CSV attachment as a table (REQ-ODT-001)

An editor SHALL be able to turn a CSV attachment of a publication into a published table. The app SHALL create one column per header field with a guessed type and import every row through OpenRegister's import, previewing before it writes. When the preview refuses a row, the table SHALL be marked failed with that row named, and no row SHALL be written.

#### Scenario: An editor publishes the playground list

- **GIVEN** a publication with the attachment `speeltuinen.csv` with columns naam, wijk, adres and toestellen
- **WHEN** the editor chooses Publish as table on that attachment
- **THEN** the publication's Tables section shows the table with its row count once the import is done

#### Scenario: A row that does not fit

- **GIVEN** a CSV whose column toestellen holds a number in the first 200 rows and the text "onbekend" in row 350
- **WHEN** the editor publishes it as a table
- **THEN** the table shows failed with row 350 named, and no row is readable

### Requirement: Anyone queries the rows of a public table (REQ-ODT-002)

`GET /api/{catalogSlug}/{publicationId}/tables/{tableId}/rows` SHALL answer the rows of a table of a public publication without sign-in, with filters on declared columns, a text search, sorting and pages of at most 1000 rows. A filter on a column the table does not have SHALL get 400. The rows of a table whose publication is not public SHALL NOT be readable anonymously.

#### Scenario: A developer asks for one district

- **GIVEN** the public playground table
- **WHEN** a developer calls `GET /api/speeltuinen/{publicationId}/tables/{tableId}/rows?wijk=Centrum&_order[naam]=asc&_limit=10`
- **THEN** the answer holds at most ten rows, all in Centrum, sorted by name, with the total

#### Scenario: A column that does not exist

- **GIVEN** the same table
- **WHEN** the developer filters on `kleur=rood`
- **THEN** the answer is 400 and names the unknown column

### Requirement: Readers see what each column means (REQ-ODT-003)

Each published table SHALL have a data dictionary with each column's name, title, description and type. Editors SHALL be able to edit the titles and descriptions on the publication page. Readers SHALL get the dictionary from `GET .../tables/{tableId}/dictionary`, and the DCAT distribution of the CSV SHALL link to it.

#### Scenario: An editor explains a column

- **GIVEN** the playground table
- **WHEN** the editor sets the description of toestellen to "Number of play devices on the site" and saves
- **THEN** `GET .../tables/{tableId}/dictionary` returns that description for toestellen

#### Scenario: A harvester finds the dictionary

- **GIVEN** a catalogue with DCAT enabled and the playground publication
- **WHEN** a harvester reads the catalogue's DCAT feed
- **THEN** the CSV's distribution carries `dct:conformsTo` with the dictionary URL

### Requirement: A failed table import notifies the importer and the publication's managers (REQ-DFA-001)

The `publishedTable` schema SHALL declare an `x-openregister-notifications` rule that fires when a table's `status` changes to `failed`, addressed to the user in `importedBy` and to the users who may manage the table, on the Nextcloud notification channel, with a subject naming the table and a message holding `lastError`. OpenCatalogi SHALL NOT send this notification itself.

#### Scenario: A background import fails
<!-- @e2e exclude Notification dispatch is OpenRegister's; proven by PublishedTableImportJobTest::testAFailedImportEndsFailedWithImportedBy and PublishedTableNotificationSchemaTest::testTheRuleValidates. -->
- **GIVEN** an editor published a 20,000 row CSV as a table and row 12,408 has text in a numeric column
- **WHEN** the background import refuses that row
- **THEN** the table is `failed` with row 12,408 in `lastError`
- **AND** the editor gets a Nextcloud notification "Table <title> could not be imported" naming the row

### Requirement: Every import ends in ready or failed (REQ-DFA-002)

An import SHALL write the table as `importing` before it starts and SHALL end it as `ready` or `failed`. An exception during an import SHALL end the table `failed` with the exception's message. A table left `importing` for more than an hour SHALL be set `failed`.

#### Scenario: A crashed import still alerts
<!-- @e2e exclude Job path; proven by PublishedTableImportJobTest::testAThrowableEndsFailed. -->
- **GIVEN** a queued import whose OpenRegister import throws
- **WHEN** the job runs
- **THEN** the table is `failed` with the exception's message

### Requirement: A replaced data file is imported again (REQ-DFA-003)

When the CSV attachment behind a published table is replaced, the system SHALL queue a new import of that table, with `importedBy` the user who replaced the file.

#### Scenario: A new version of the file has a bad row
<!-- @e2e exclude Event path; proven by PublishedTableReimportListenerTest::testAReplacedFileQueuesAReimport. -->
- **GIVEN** a ready table and an editor who uploads a new version of its CSV with a refused row
- **WHEN** the re-import runs
- **THEN** that editor is notified of the failure

### Requirement: The publication page shows a failed table (REQ-DFA-004)

The Tables section on the publication page SHALL show a failed table with "Import failed", its `lastError`, the time of the failure and Retry.

#### Scenario: The editor follows the notification
- **GIVEN** a table that failed to import
- **WHEN** the editor opens the notification's link
- **THEN** the publication page opens on its Tables section with "Import failed" and the refused row
