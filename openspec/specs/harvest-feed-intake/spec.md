# harvest-feed-intake Specification

## Purpose
The rule every inbound harvest slice of the re-scoped `dcat-oai-pmh-harvesting` umbrella follows since decision 80: harvesting is OpenRegister's (`openregister/app-harvest-fetchers-and-flow-node`), OpenCatalogi contributes fetchers and settings.

## Requirements

### Requirement: Every inbound harvest runs as an OpenRegister source (REQ-DOH-001)

OpenCatalogi SHALL run every inbound harvest as an OpenRegister `Source` with `application: opencatalogi` and a fetcher it registers through `RegisterSourceFetchersEvent`. It MUST NOT ship a harvest feed, item or run schema, a scheduler, a flow node or writer for harvesting, a checksum or tombstone store, or an outbound URL guard of its own. Item state SHALL be read from OpenRegister's `SyncRecord` and run state from the source's flow runs.

#### Scenario: No harvest schema ships with the app
<!-- @e2e exclude Architecture rule; proven by HarvestArchitectureTest::testNoHarvestSchemaShips, which loads every register file under lib/Settings and fails on a schema named harvest-feed, harvested-item or harvest-run. -->
- **WHEN** the app's register files are loaded
- **THEN** none declares a schema `harvest-feed`, `harvested-item` or `harvest-run`

#### Scenario: A feed is a source
<!-- @e2e exclude Architecture rule; proven by HarvestFeedServiceTest::testAFeedIsSavedThroughOpenRegistersSourceService. -->
- **WHEN** an administrator saves a harvest feed
- **THEN** it is stored as an OpenRegister source with `application: opencatalogi` and no OpenCatalogi object is written for it
