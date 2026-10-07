---
status: proposed
---

# Harvest feed intake

## Purpose

An administrator registers an external DCAT JSON-LD feed. The OpenRegister flow engine harvests it on a schedule into draft publications that name their source. Checksums keep a re-run cheap, a collision with local work is parked, and a dataset that leaves the feed is flagged, never deleted. Design: `../../design.md`. Screen: board `OcInstellingen` on canvas 5NkFW28vZUUij43xzxHg5a (design D8).

## ADDED Requirements

### Requirement: An administrator registers a harvest feed (REQ-HFI-001)

The system SHALL store a harvest feed as an object of schema `harvest-feed` in the register fragment `lib/Settings/register.d/harvest.json`, with `name`, exactly one of `sourceUrl` or `sourceSlug`, `protocol`, `schedule`, `runAs`, `enabled`, `targetCatalog`, `targetSchema`, `mapping` and `maxItemsPerRun`. Only administrators SHALL read or write feeds. On save the system MUST refuse a feed whose `sourceUrl` is not `http` or `https` or resolves to a private, loopback, link-local or metadata address; whose `schedule` is not a five-field cron expression; whose `runAs` names no enabled account; whose `mapping` names no OpenRegister mapping; or whose `targetSchema` is not one of `targetCatalog`'s schemas. `protocol` SHALL accept only `dcat-jsonld` in this change.

#### Scenario: A valid feed is saved
- **GIVEN** an administrator on the "Harvest feeds" section of the admin settings
- **WHEN** they save a feed with source `https://data.example.nl/catalog.jsonld`, schedule `15 3 * * *`, runAs `harvest-bot`, catalog "Open data", schema `publication` and mapping `dcat-dataset-to-publication`
- **THEN** the feed is stored and listed as a card with its source and "Never run"

#### Scenario: An internal address is refused at save
<!-- @e2e exclude Validation path; proven by HarvestFeedValidatorTest::testAPrivateAddressIsRefused. -->
- **GIVEN** a feed form with source `http://10.0.0.5/catalog.jsonld`
- **WHEN** the administrator saves it
- **THEN** the save is refused with a message naming the source address
- **AND** no feed and no flow exist

#### Scenario: A feed without an acting account is refused
<!-- @e2e exclude Validation path; proven by HarvestFeedValidatorTest::testAMissingRunAsIsRefused. -->
- **GIVEN** a feed form with an empty `runAs`
- **WHEN** the administrator saves it
- **THEN** the save is refused with a message that the harvest needs an account to act as

#### Scenario: A non-administrator cannot read feeds
<!-- @e2e exclude Authorization path; proven by HarvestFeedControllerTest::testANonAdminIsRefused. -->
- **GIVEN** a signed-in user who is not an administrator
- **WHEN** they request the feed list
- **THEN** the response is 403 and lists nothing

### Requirement: Each enabled feed runs as one OpenRegister flow (REQ-HFI-002)

For each enabled feed the system SHALL keep exactly one flow in OpenRegister's flow store, keyed by the feed's uuid. The flow SHALL hold a schedule trigger whose `cron` is the feed's `schedule` and whose `runAs` is the feed's `runAs`, a manual trigger, and the contributed node `opencatalogi.harvest-feed` registered through `RegisterFlowNodesEvent`. Saving the feed again SHALL update that flow, not add one. Disabling the feed SHALL disable the flow; deleting the feed SHALL delete the flow and keep its items and runs. The app MUST NOT ship a cron parser, scheduler, `TimedJob` or job dispatcher for harvesting.

#### Scenario: Enabling a feed creates one flow
<!-- @e2e exclude Flow store has no page in this slice; proven by HarvestFlowMaterialiserTest::testEnablingCreatesOneFlowWithScheduleAndRunAs. -->
- **GIVEN** a saved, disabled feed
- **WHEN** the administrator switches it on
- **THEN** one flow keyed by the feed's uuid exists and is enabled
- **AND** its schedule trigger carries the feed's cron and runAs

#### Scenario: Saving twice keeps one flow
<!-- @e2e exclude Idempotency; proven by HarvestFlowMaterialiserTest::testASecondSaveUpdatesTheSameFlow. -->
- **GIVEN** an enabled feed with its flow
- **WHEN** the administrator changes the schedule to `0 2 * * *` and saves
- **THEN** there is still one flow for the feed, and its cron is `0 2 * * *`

#### Scenario: Switching a feed off stops its schedule
<!-- @e2e exclude Flow state; proven by HarvestFlowMaterialiserTest::testDisablingDisablesTheFlow. -->
- **GIVEN** an enabled feed
- **WHEN** the administrator clicks "Switch off"
- **THEN** the feed's flow is disabled and the scheduler does not fire it

### Requirement: Run now uses the same flow (REQ-HFI-003)

"Run now" on a feed card SHALL start the feed's flow through its manual trigger, under the feed's `runAs`. It SHALL NOT call the harvest node directly. While a run of that flow is in progress, "Run now" SHALL be disabled and the card SHALL say a run is in progress.

#### Scenario: An administrator runs a feed by hand
- **GIVEN** an enabled feed that has never run
- **WHEN** the administrator clicks "Run now"
- **THEN** a flow run with trigger `manual` starts
- **AND** after it ends the card shows "Last run" with its time and the number of datasets found

### Requirement: Fetching is guarded (REQ-HFI-004)

A bare-URL fetch SHALL go through the same outbound-URL guard as directory sync: `http` or `https` only, every redirect hop checked, and a private, loopback, link-local or metadata address refused. A fetch SHALL time out after 30 seconds, SHALL retry a timeout or 5xx at most three times with backoff, and SHALL stop reading after 50 MB. When the feed names `sourceSlug` and integriq is installed, the fetch SHALL run through integriq's `openconnector.source-call` instead. When the feed names `sourceSlug` and integriq is not installed, the run SHALL fail with a message saying so. A feed with `sourceUrl` MUST work without integriq.

#### Scenario: A redirect to a metadata address is refused
<!-- @e2e exclude Security path; proven by HarvestFetcherTest::testARedirectToTheMetadataAddressIsRefused. -->
- **GIVEN** a feed whose source answers 302 to `http://169.254.169.254/latest/meta-data`
- **WHEN** the feed runs
- **THEN** the redirect is not followed
- **AND** the run is `failed` and names the refused address

#### Scenario: A source that keeps failing ends the run cleanly
<!-- @e2e exclude Retry path; proven by HarvestFetcherTest::testThreeServerErrorsFailTheRun. -->
- **GIVEN** a source that answers 503 four times in a row
- **WHEN** the feed runs
- **THEN** the fetch is tried four times in total and the run is `failed`
- **AND** no item changes state

#### Scenario: A source slug without integriq says what is missing
<!-- @e2e exclude Degraded path; proven by HarvestFetcherTest::testASourceSlugWithoutIntegriqFailsWithAReason. -->
- **GIVEN** a feed with `sourceSlug` `data-overheid` on an instance without integriq
- **WHEN** the feed runs
- **THEN** the run is `failed` with the reason that integriq is not installed

### Requirement: DCAT JSON-LD datasets become draft publications through an OpenRegister mapping (REQ-HFI-005)

The harvest node SHALL read every node typed `dcat:Dataset` under the catalog's `dcat:dataset` or in `@graph`, and SHALL take the dataset's `@id` as its `externalUri`. A dataset without `@id` SHALL be skipped and recorded as a run error. Each dataset SHALL be mapped with the feed's OpenRegister mapping through `MappingService`, and saved through `ObjectService::saveObject()` into the feed's target catalog register and schema. A harvest MUST NOT set or change `publicatiedatum`, `status` or `unlisted`, so a harvested publication is a draft until an editor publishes it. A dataset the target schema refuses SHALL be recorded as a run error and SHALL NOT stop the other datasets.

#### Scenario: Three datasets become three drafts
<!-- @e2e exclude Covered end to end by tests/e2e/harvest-feed.spec.ts; this scenario fixes the node contract, proven by HarvestFeedNodeTest::testThreeDatasetsBecomeThreeDrafts. -->
- **GIVEN** a feed on a fixture catalog with three datasets that each have an `@id`
- **WHEN** the feed runs for the first time
- **THEN** three publications exist in the target schema, none with a `publicatiedatum`
- **AND** three items are `new`

#### Scenario: A dataset without an identifier is skipped
<!-- @e2e exclude Parser edge; proven by DcatJsonLdParserTest::testADatasetWithoutIdIsSkipped. -->
- **GIVEN** a catalog where one of four datasets has no `@id`
- **WHEN** the feed runs
- **THEN** three items exist and the run lists one error for the dataset without an identifier

#### Scenario: A harvest does not publish an updated dataset
<!-- @e2e exclude Fail-closed contract; proven by HarvestFeedNodeTest::testAnUpdateNeverTouchesThePublicationDate. -->
- **GIVEN** a harvested draft that an editor has not touched
- **WHEN** its dataset changes upstream and the feed runs
- **THEN** the publication is updated and still has no `publicatiedatum`

### Requirement: Checksums decide what a re-run does (REQ-HFI-006)

Each item SHALL carry a SHA-256 checksum over its mapped payload, serialised as JSON with keys sorted at every level. On a re-run an item with an equal checksum SHALL become `unchanged` and its object SHALL NOT be written. An item with a different checksum whose object was not edited since `lastAppliedAt` SHALL become `updated` and its object SHALL be written. Every item seen SHALL get a new `lastSeenAt`.

#### Scenario: Only the changed dataset is written
<!-- @e2e exclude Change detection; proven by HarvestFeedNodeTest::testOnlyAChangedDatasetIsWritten. -->
- **GIVEN** a feed that harvested three datasets yesterday
- **WHEN** one dataset's title changed and the feed runs again
- **THEN** that item is `updated` and its publication shows the new title
- **AND** the other two items are `unchanged` and their publications were not saved

#### Scenario: Key order does not change the checksum
<!-- @e2e exclude Pure function; proven by HarvestChecksumTest::testKeyOrderDoesNotChangeTheChecksum. -->
- **GIVEN** two mapped payloads with the same values in a different key order
- **WHEN** both are hashed
- **THEN** the checksums are equal

### Requirement: A harvested publication names its source (REQ-HFI-007)

Every object a harvest creates or updates SHALL carry `dct:source` with the item's `externalUri` and `prov:wasDerivedFrom` with the feed's uuid. The feed's mapping MUST NOT be able to override either value. The item SHALL record `localObjectId`, `firstSeenAt`, `lastSeenAt`, `lastAppliedAt` and, when the dataset has `dct:modified`, `sourceRevision`.

#### Scenario: The source is on the publication
<!-- @e2e exclude Covered end to end by tests/e2e/harvest-feed.spec.ts; proven by HarvestFeedNodeTest::testProvenanceIsWrittenAndCannotBeMappedAway. -->
- **GIVEN** a mapping that tries to write `dct:source` from the dataset's title
- **WHEN** the feed runs
- **THEN** the publication's `dct:source` is the dataset's `@id` and its `prov:wasDerivedFrom` is the feed's uuid

### Requirement: A collision with local work is parked as a conflict (REQ-HFI-008)

The harvest SHALL NOT write an object and SHALL set the item to `conflict` with a `conflictReason` when: a local object in the target schema already carries the item's `externalUri` as `dct:source` and no item of this feed links it (`claimed-locally`); the linked object's `@self.updated` is later than the item's `lastAppliedAt` (`edited-locally`); or the linked object was deleted (`deleted-locally`). A conflict item SHALL keep its new checksum, so the next run does not count it as changed again. Policies and review are `harvest-conflict-policies`.

#### Scenario: An editor's change is not overwritten
<!-- @e2e exclude Conflict parking; proven by HarvestFeedNodeTest::testALocalEditParksTheItem. -->
- **GIVEN** a harvested draft whose summary an editor changed after the last run
- **WHEN** the dataset changes upstream and the feed runs
- **THEN** the publication keeps the editor's summary
- **AND** the item is `conflict` with reason `edited-locally`

#### Scenario: A deleted publication is not brought back
<!-- @e2e exclude Conflict parking; proven by HarvestFeedNodeTest::testALocallyDeletedObjectIsNotRecreated. -->
- **GIVEN** a harvested publication an editor deleted
- **WHEN** the feed runs and the dataset is still there
- **THEN** no publication is created
- **AND** the item is `conflict` with reason `deleted-locally`

### Requirement: A dataset that leaves the feed is flagged, never deleted (REQ-HFI-009)

After a run that read the whole feed, an item of the feed that the run did not see SHALL get `tombstoned: true` and `tombstonedAt`. Its object SHALL NOT be deleted, depublished or changed. A run that stopped early (fetch error, `maxItemsPerRun` reached, parse error) MUST NOT tombstone anything. An item that is seen again SHALL lose its tombstone.

#### Scenario: A removed dataset is flagged
<!-- @e2e exclude Tombstone path; proven by HarvestFeedNodeTest::testADisappearedDatasetIsTombstoned. -->
- **GIVEN** a feed that harvested three datasets
- **WHEN** the feed now lists two and the run completes
- **THEN** the third item is tombstoned and its publication is unchanged

#### Scenario: A partial run flags nothing
<!-- @e2e exclude Fail-safe path; proven by HarvestFeedNodeTest::testAPartialRunTombstonesNothing. -->
- **GIVEN** a feed with `maxItemsPerRun` 2 and a catalog of three datasets
- **WHEN** the feed runs
- **THEN** the run is `partial` and no item is tombstoned

#### Scenario: A dataset that comes back is no longer flagged
<!-- @e2e exclude Tombstone path; proven by HarvestFeedNodeTest::testAReturningDatasetLosesItsTombstone. -->
- **GIVEN** a tombstoned item
- **WHEN** its dataset is back in the feed and the feed runs
- **THEN** the item is no longer tombstoned

### Requirement: Every run is accounted for (REQ-HFI-010)

Each execution of the harvest node SHALL write one `harvest-run` with `feedId`, `trigger`, `startedAt`, `finishedAt`, `status`, counts for `new`, `updated`, `unchanged` and `conflict`, the number tombstoned, and at most 100 errors, each with `externalUri` and message. Status SHALL be `success` when every dataset was handled, `partial` when some failed or the run hit `maxItemsPerRun`, and `failed` when the feed could not be read. The feed card SHALL show the last run's time, status and the number of datasets found.

#### Scenario: Run counts add up
<!-- @e2e exclude Covered end to end by tests/e2e/harvest-feed.spec.ts; proven by HarvestRunRecorderTest::testCountsMatchTheItems. -->
- **GIVEN** a re-run where one dataset changed, one is new, one is unchanged and one is parked
- **WHEN** the run ends
- **THEN** the run reads new 1, updated 1, unchanged 1, conflict 1 and status `success`
