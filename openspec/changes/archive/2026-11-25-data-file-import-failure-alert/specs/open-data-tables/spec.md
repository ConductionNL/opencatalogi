# Open data tables

## Purpose

A failed import of a published data file alerts the people who need to act on it. Design: `../../design.md`. Builds on `open-data-table-query-and-dictionary`.

## ADDED Requirements

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
