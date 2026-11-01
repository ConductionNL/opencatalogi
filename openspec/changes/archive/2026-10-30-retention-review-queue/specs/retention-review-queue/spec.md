# retention-review-queue

## ADDED Requirements

### Requirement: A retention decision is taken on one or more publications with a note (RRQ-001)

The retention review page SHALL offer Extend term, Depublish now and Archive
on a row and on a selection. Each MUST open one dialog over the chosen
publications that requires a note, and for Extend term a positive number of
extra months, and SHALL show each publication's current and new end date. The
dialog MUST send one decision per publication, so each keeps its own history
entry, and MUST name every publication the server refused.

@e2e exclude The decision endpoint and its audit are covered by RetentionControllerTest; RetentionDecisionDialog.spec.js drives the dialog against that contract.

#### Scenario: Extending two terms

- **GIVEN** "Woo-besluit over de parkeervergunningen van 2021" ending 4 October 2026 and "Convenant leerlingenvervoer 2016 tot 2026" ending 1 October 2026, both selected
- **WHEN** the officer chooses Extend term, keeps 12 months and writes "Beide spelen nog een rol in het beroep dat loopt tot medio 2027."
- **THEN** the dialog shows 4 Oct 2026 to 4 Oct 2027 and 1 Oct 2026 to 1 Oct 2027
- **AND** two decision requests are sent, each with `extendMonths: 12` and the note

#### Scenario: No note, no decision

- **GIVEN** the dialog for Archive with an empty note
- **THEN** the confirm button is disabled

### Requirement: The queue lists publications by retention status (RRQ-002)

The page SHALL list the publications whose retention term ends within 30 days
or has ended, with tabs Expiring soon, Review needed, Both and Archived, and
the dashboard widget's counts MUST open it.

@e2e exclude Manifest configuration over the library's index page, validated against the app-manifest-v2 schema; the tabs are its quick filters.

#### Scenario: The dashboard count opens the queue

- **WHEN** the officer clicks "Review required" on the retention widget
- **THEN** the retention review page opens
