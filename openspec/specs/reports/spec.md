---
status: in-progress
---

# reports Specification

## Purpose
Give a publication officer three ready-made reports, on publishing, on usage and on federation, without building a dashboard. The reports are declared in `src/manifest.json` and drawn by nextcloud-vue's page renderer: a `type: reports` index page and three `type: dashboard` pages whose widgets ask OpenRegister's aggregation endpoint for counts, sums and groupings. OpenCatalogi has no report controller of its own (ADR-022).

This spec describes the code as it is on development (7 October 2026). Known defect: every widget names the register slug `opencatalogi`, while the only register the app ships has the slug `publication` (`lib/Settings/publication_register.json`, `components.registers.publication`). Until the widgets name `publication`, the reports can render empty. The requirements below state what the pages declare; the row `ops-reports` stays `partial` until that is fixed and checked on a live instance.

Capability row: `ops-reports`.

## Requirements

### Requirement: A reports page lists the three reports (REP-001)
The system SHALL offer a Reports entry in the settings section of the navigation (`src/manifest.json:389-395`, id `ReportsMenu`, order 95) that opens the page `Reports` at `/reports` (`src/manifest.json:1375-1412`). The page SHALL show three cards in two categories: Publications (category Publishing), Usage and Catalogs and directory (category Reach and federation), each with a one-line description, opening `/reports/publications`, `/reports/usage` and `/reports/federation`.

#### Scenario: An officer opens a report from the list
<!-- @e2e exclude No e2e test covers /reports yet; the page is declarative manifest config drawn by nextcloud-vue. -->
- WHEN an officer chooses Reports in the navigation
- THEN the page lists the cards Publications, Usage and Catalogs and directory, grouped as Publishing and Reach and federation
- WHEN the officer chooses Usage
- THEN the app opens `/reports/usage`

### Requirement: The publications report (REP-002)
The page `PublicationsReport` at `/reports/publications` (`src/manifest.json:1415-1655`) SHALL show over the `publication` schema: counts of published and archived publications and of publications whose `retentionAction` is `review`; donut charts by `status` and by `retentionAction`; a bar chart of the ten organisations with the most publications plus an other bucket, labelled with the organisation title; and the eight most recent publications by `publicationDate`. Each count SHALL link to the search page. Retention SHALL be grouped by what `retentionAction` says, not filtered on a date, because the aggregation endpoint only evaluates equality filters.

#### Scenario: The officer reads what retention says to do
<!-- @e2e exclude No e2e test covers /reports/publications yet; widgets are OpenRegister aggregations. -->
- WHEN the officer opens `/reports/publications`
- THEN the page shows the Published, Archived and Retention says review counts
- AND a donut What retention says to do grouped by `retentionAction`

### Requirement: The usage report (REP-003)
The page `UsageReport` at `/reports/usage` (`src/manifest.json:1658-1842`) SHALL show over the `usageCounter` schema: total views and total downloads as sums of the `count` field (not row counts); a donut of views against downloads; a bar chart of the ten most read publications plus an other bucket, labelled with the publication title; and the eight most recent counter rows with date, kind and count. Usage counters hold no personal data (spec `publication-usage-analytics`).

#### Scenario: Totals are sums of the counters
<!-- @e2e exclude No e2e test covers /reports/usage yet; the sum is computed by OpenRegister's aggregation endpoint. -->
- GIVEN the only view counters are 5 for yesterday and 3 for today
- WHEN the officer opens `/reports/usage`
- THEN the Views total reads 8, not 2

### Requirement: The catalogs and directory report (REP-004)
The page `FederationReport` at `/reports/federation` (`src/manifest.json:1845` onward) SHALL show: the number of catalogues with status `stable`, the number of directory listings and the number with `integrationLevel` `search`; donut charts of catalogues by status, listings by status and listings by integration level; and the eight most recently synced listings with last sync, title and integration level. The catalogue counts SHALL link to the catalogues page and the listing counts to the directory.

#### Scenario: The officer sees how far the catalogue reaches
<!-- @e2e exclude No e2e test covers /reports/federation yet; widgets are OpenRegister aggregations. -->
- WHEN the officer opens `/reports/federation`
- THEN the page shows Stable catalogs, Directory listings and Searchable listings
- AND choosing Directory listings opens the directory
