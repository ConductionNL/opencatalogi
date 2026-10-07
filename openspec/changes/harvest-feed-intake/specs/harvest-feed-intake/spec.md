---
status: proposed
---

# Harvest feed intake

## Purpose

An administrator registers an external DCAT JSON-LD feed on the settings page. OpenCatalogi stores it as an OpenRegister source of type `opencatalogi.dcat-jsonld` and contributes the fetcher; OpenRegister's harvest node runs it on the source's flow (`openregister/app-harvest-fetchers-and-flow-node`). Each dataset lands as a draft publication that names its source. Design: `../../design.md`. Screen: board `OcInstellingen` on canvas 5NkFW28vZUUij43xzxHg5a (design D7).

## ADDED Requirements

### Requirement: OpenCatalogi registers a DCAT JSON-LD fetcher (REQ-HFI-001)

The system SHALL register one fetcher through OpenRegister's `RegisterSourceFetchersEvent` with type `opencatalogi.dcat-jsonld`, display name "DCAT catalogue (JSON-LD)" and a config schema that requires `targetCatalog` and exactly one of `sourceUrl` or `sourceSlug`. The fetcher SHALL implement `IBatchSourceFetcher`: it SHALL read the whole document through the `HarvestHttpClient` OpenRegister hands it and return every node typed `dcat:Dataset` (under `dcat:dataset` or in `@graph`) keyed by its `@id`. A dataset without `@id` SHALL be returned as an error, not an item. A fetch or parse failure SHALL return `complete: false` and no items. The app MUST NOT ship its own harvest node, flow writer, scheduler or URL guard.

#### Scenario: The type is offered to forms
<!-- @e2e exclude Registry contract; proven by RegisterSourceFetchersListenerTest against the real event class. -->
- **WHEN** an administrator lists OpenRegister's source types
- **THEN** the list holds `opencatalogi.dcat-jsonld` with display name "DCAT catalogue (JSON-LD)" and owning app `opencatalogi`

#### Scenario: Datasets in a graph are found
<!-- @e2e exclude Parser contract; proven by DcatJsonLdFetcherTest::testDatasetsInAGraphAreItems. -->
- **GIVEN** a JSON-LD document with a `@graph` holding one `dcat:Catalog` and three `dcat:Dataset` nodes with an `@id`
- **WHEN** the fetcher gathers it
- **THEN** the batch holds three items keyed by their `@id` and `complete` is true

#### Scenario: A dataset without an identifier is reported
<!-- @e2e exclude Parser edge; proven by DcatJsonLdFetcherTest::testADatasetWithoutIdIsAnError. -->
- **GIVEN** a catalogue where one of four datasets has no `@id`
- **WHEN** the fetcher gathers it
- **THEN** the batch holds three items and one error for the dataset without an identifier

#### Scenario: A broken document flags nothing
<!-- @e2e exclude Fail-safe path; proven by DcatJsonLdFetcherTest::testAParseErrorIsIncomplete. -->
- **GIVEN** a source that answers with invalid JSON
- **WHEN** the fetcher gathers it
- **THEN** the batch is `complete: false` with no items, so no harvested publication is flagged as gone

#### Scenario: A source slug without integriq says what is missing
<!-- @e2e exclude Degraded path; proven by DcatJsonLdFetcherTest::testASourceSlugWithoutIntegriqFailsWithAReason. -->
- **GIVEN** a feed with `sourceSlug` `data-overheid` on an instance without integriq
- **WHEN** the feed runs
- **THEN** the batch is `complete: false` with the error that integriq is not installed

### Requirement: A harvest feed is an OpenRegister source owned by OpenCatalogi (REQ-HFI-002)

The system SHALL store a feed as an OpenRegister `Source` written through OpenRegister's `SourceService`, with `application: opencatalogi`, `type: opencatalogi.dcat-jsonld`, `config` (`sourceUrl` or `sourceSlug`, `targetCatalog`), `mappingId`, `targetRegister` and `targetSchema` taken from the catalogue, `schedule`, `runAs`, `syncEnabled`, `identityProperty: source`, `deleteStrategy: flag` and `conflictStrategy: manual`. Only administrators SHALL read or write feeds. The system SHALL refuse a feed whose `targetSchema` is not one of `targetCatalog`'s schemas, with a message naming the schema. Refusals from OpenRegister (an invalid `config`, a `schedule` without `runAs`) SHALL be shown on the field they name. The app MUST NOT add a schema for feeds, items or runs.

#### Scenario: A valid feed is saved
- **GIVEN** an administrator on the "Harvest feeds" section of the settings page
- **WHEN** they save a feed with source `https://data.example.nl/catalog.jsonld`, schedule `15 3 * * *`, run as `harvest-bot`, catalogue "Open data", schema `publication` and mapping `dcat-dataset-to-publication`
- **THEN** an OpenRegister source with `application` `opencatalogi` exists
- **AND** the section shows a card for it with its source and "Never run"

#### Scenario: A schema outside the catalogue is refused
<!-- @e2e exclude Validation path; proven by HarvestFeedServiceTest::testASchemaOutsideTheCatalogueIsRefused. -->
- **GIVEN** a catalogue "Open data" whose only schema is `publication`
- **WHEN** the administrator saves a feed for it with schema `listing`
- **THEN** the save is refused with a message naming `listing`
- **AND** no source exists

#### Scenario: A schedule without an acting account is refused
<!-- @e2e exclude OpenRegister's 422 surfaced on the field; proven by HarvestFeedServiceTest::testARunAsRefusalIsShownOnTheField. -->
- **GIVEN** a feed form with a schedule and an empty "Run as"
- **WHEN** the administrator saves it
- **THEN** the save is refused and the "Run as" field says the harvest needs an account to act as

#### Scenario: A non-administrator cannot read feeds
<!-- @e2e exclude Authorization path; proven by HarvestFeedServiceTest::testANonAdminIsRefused. -->
- **GIVEN** a signed-in user who is not an administrator
- **WHEN** they request the feed list
- **THEN** the response is 403 and lists nothing

### Requirement: The settings page shows one card per feed (REQ-HFI-003)

The settings page SHALL hold a "Harvest feeds" section after the GitHub harvest card, with one card per OpenCatalogi source: its name, its source address or slug, "Last run" with date, time, status and the number of datasets found (from the source's last-sync fields and the latest run summary of its flow), or "Never run", and the actions Run now, Switch off (or Switch on) and "Open the source in OpenRegister". "New" SHALL open the feed modal. Run now SHALL call OpenRegister's `POST /api/sources/{id}/sync`, which starts the source flow's manual trigger; the app SHALL NOT run a harvest itself. A run that OpenRegister skips with reason `already-running` SHALL be shown with that reason.

#### Scenario: An administrator runs a feed by hand
- **GIVEN** an enabled feed that has never run
- **WHEN** the administrator clicks "Run now"
- **THEN** a run of the source's flow starts
- **AND** after it ends the card shows "Last run" with its time and the number of datasets found

#### Scenario: Switching a feed off stops its schedule
<!-- @e2e exclude Flow state is OpenRegister's; proven by HarvestFeedServiceTest::testSwitchOffSavesSyncDisabled. -->
- **GIVEN** an enabled feed
- **WHEN** the administrator clicks "Switch off"
- **THEN** the source is saved with `syncEnabled: false` and the card offers "Switch on"

### Requirement: Harvested datasets stay drafts (REQ-HFI-004)

Every OpenCatalogi source SHALL carry `protectedFields` `publicationDate`, `depublicationDate` and `status` (and `unlisted` once the publication schema has it), so OpenRegister never writes them from a harvest, on create, on update or on a conflict resolution. A harvested publication SHALL therefore not be public until an editor publishes it. The seeded mapping `dcat-dataset-to-publication` SHALL NOT map any protected field.

#### Scenario: Three datasets become three drafts
- **GIVEN** a feed on the fixture catalogue with three datasets
- **WHEN** the feed runs for the first time
- **THEN** three publications exist in the target schema, none with a `publicationDate`
- **AND** none of them is returned by the public publications API

#### Scenario: An update does not publish
<!-- @e2e exclude Fail-closed contract; proven by HarvestFeedServiceTest::testEveryFeedProtectsThePublicationFields and OpenRegister's REQ-HAF-008 test. -->
- **GIVEN** a harvested draft and a mapping an administrator edited to map `dct:issued` onto `publicationDate`
- **WHEN** the dataset changes upstream and the feed runs
- **THEN** the publication is updated and still has no `publicationDate`

### Requirement: A harvested publication names its source (REQ-HFI-005)

The publication schema SHALL have the properties `source` (the dataset's `@id`) and `derivedFrom` (the OpenRegister source uuid), added through a register fragment. Every OpenCatalogi source SHALL carry `provenance` `{"externalIdProperty": "source", "sourceProperty": "derivedFrom"}`, so OpenRegister sets both after mapping and a mapping cannot override them. The publication detail page SHALL show "Harvested from" with the feed's name when `derivedFrom` is set.

#### Scenario: The source is on the publication
- **GIVEN** a mapping that tries to write `source` from the dataset's title
- **WHEN** the feed runs
- **THEN** the publication's `source` is the dataset's `@id` and its `derivedFrom` is the source's uuid
- **AND** its detail page shows "Harvested from" with the feed's name
