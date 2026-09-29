---
status: proposed
---

# Woo obligation overview

## ADDED Requirements

### Requirement: The overview reads every registered source and shows the ones it could not read (REQ-WOO-001)

`GET /api/obligations` SHALL, for an admin, read every enabled `obligationSource` and return the assembled overview with totals for published, late and outstanding obligations. A source that fails or has no reader SHALL appear in `unreadSources` with its reason and SHALL NOT be counted as zero obligations. The endpoint SHALL refuse a caller who is not an admin.

#### Scenario: One source answers and one fails

- **GIVEN** two enabled sources, one answering with three obligations and one that throws
- **WHEN** an admin requests `GET /api/obligations`
- **THEN** the response lists the three obligations
- **AND** `unreadSources` names the failing source with its reason

#### Scenario: A non-admin asks

- **GIVEN** a signed-in user who is not an admin
- **WHEN** the user requests `GET /api/obligations`
- **THEN** the response is 403

### Requirement: Harvested items count as a source (REQ-WOO-002)

The harvest intake SHALL be registered as the source `harvest`, and its obligations SHALL be listed beside those of the other sources.

#### Scenario: A harvested obligation appears

- **GIVEN** a harvest feed that produced one item with a due date in the past and no publication
- **WHEN** an admin opens the overview
- **THEN** the item is listed with source `harvest` and state late

### Requirement: An admin can open the overview as a page (REQ-WOO-003)

The admin navigation SHALL offer an Obligations page that shows the totals, each obligation with its state, source and a link to its record, and the unread sources above the table. A late obligation SHALL be marked with the word Late as well as a colour.

#### Scenario: An admin reads the page

- **GIVEN** an overview with two published, one late and one unread source
- **WHEN** an admin opens the Obligations page
- **THEN** the page shows the totals 2 published and 1 late
- **AND** the unread source is listed with its reason above the table
