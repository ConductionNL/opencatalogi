---
status: proposed
---

# Harvest observability

## Purpose

An administrator sees what each harvest feed did and why a dataset was refused. Feeds are OpenRegister sources owned by OpenCatalogi, records are OpenRegister sync records, runs are runs of the source's flow (`openregister/app-harvest-fetchers-and-flow-node`). Design: `../../design.md`.

## ADDED Requirements

### Requirement: DCAT datasets are validated before they are harvested (REQ-HOB-001)

The DCAT fetchers SHALL validate each dataset against the bundled DCAT-AP-NL shape and, when the source's `config.shapeUrl` is set, against that shape too. A dataset with a SHACL violation SHALL NOT be returned as an item; it SHALL be returned in the batch's `errors` with its `@id` and the violation's path and message. While OpenRegister counts only items as seen, a batch with such an error SHALL report `complete: false`, so no record is tombstoned because it failed validation. `config.validate: false` SHALL switch validation off for that feed.

#### Scenario: A dataset without a title is refused with the reason
<!-- @e2e exclude Fetcher contract; proven by DcatJsonLdFetcherTest::testAViolatingDatasetIsAnErrorAndTheRunIsIncomplete. -->
- **GIVEN** a feed whose catalogue holds three datasets, one without `dct:title`
- **WHEN** the feed runs
- **THEN** two datasets are harvested
- **AND** the run summary lists the third by its `@id` with "dct:title: Less than 1 values"
- **AND** the run is `partial` and no record of the feed is tombstoned

### Requirement: Each feed has a page that reads OpenRegister's records and runs (REQ-HOB-002)

The system SHALL offer an admin page per OpenCatalogi harvest source showing its last and next run, its sync records counted per status and tombstoned, the errors per run over its last 20 runs, a paged list of its flow runs with their summaries, and Run now, which SHALL start the source's flow through OpenRegister. OpenCatalogi SHALL NOT store runs, records or logs of its own.

#### Scenario: An administrator reads why a run was partial
- **GIVEN** a feed whose last run refused one dataset
- **WHEN** the administrator opens the feed's page and the last run
- **THEN** the run shows status partial and the refused dataset with its reason

#### Scenario: Run now has one path
<!-- @e2e exclude Controller wiring; proven by HarvestFeedDetailTest (vitest) asserting Run now posts to /apps/openregister/api/sources/{id}/sync and to nothing else. -->
- **WHEN** the administrator clicks Run now on the feed page
- **THEN** OpenRegister's source sync starts the flow's manual trigger and the page shows the new run

### Requirement: Harvest runs are kept 30 days (REQ-HOB-003)

The flow of every OpenCatalogi harvest source SHALL carry a run retention override of 30 days, applied by OpenRegister's flow run retention.

#### Scenario: A saved feed keeps a month of runs
<!-- @e2e exclude Configuration contract; proven by HarvestFeedServiceTest::testTheFlowKeepsRunsThirtyDays. -->
- **WHEN** an administrator saves a feed
- **THEN** its flow declares a run retention of 30 days
