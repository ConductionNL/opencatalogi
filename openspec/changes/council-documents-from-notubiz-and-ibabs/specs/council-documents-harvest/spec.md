---
status: proposed
---

# Council documents harvest

## ADDED Requirements

### Requirement: An administrator sets up the council documents harvest (REQ-CDH-001)

OpenCatalogi SHALL declare a flow "Council documents harvest" on the publication schema in `lib/Settings/register.d/council-documents-harvest.json`, shipped `enabled: false`, with a nightly schedule and a manual trigger. The admin settings SHALL show a "Council documents" section with Source, Publish on arrival, Last run, Set up, Run now, Switch off and Open the source in integriq. `setUp()` MUST refuse with a message naming the missing piece when integriq is not installed or the chosen source has no credentials it needs.

#### Scenario: Setting up from a Notubiz source
<!-- @e2e exclude Settings section not yet on the canvas board; proven by tests/e2e/council-documents-harvest.spec.ts once task 3.1 lands, and by CouncilHarvestServiceTest::testSetUpWritesTheSynchronizationAndRuleForNotubiz. -->

- **GIVEN** integriq with the source `notubiz-ris-v1` filled in for the organisation
- **WHEN** an administrator picks it in the Council documents section and presses Set up
- **THEN** the synchronization and the file rule exist and the section offers Run now and Switch off

#### Scenario: iBabs without a key is refused
<!-- @e2e exclude Fail-closed path; proven by CouncilHarvestServiceTest::testSetUpRefusesAnIbabsSourceWithoutAKey. -->

- **GIVEN** the source `ibabs-ris-v1` with an empty key
- **WHEN** an administrator presses Set up
- **THEN** set up is refused with "Fill in the iBabs key in integriq first"

### Requirement: Each public council document becomes a publication with its file (REQ-CDH-002)

A run SHALL map every document the source marks public into a publication in the "Raadsinformatie" catalogue with `wooCategory` `infocat008`, the meeting body and date in its summary, and the source identifier, and SHALL attach the document file through integriq's `openconnector.fetch-file`. A harvested publication MUST arrive as a concept unless Publish on arrival is on.

#### Scenario: A meeting's papers arrive as concepts
<!-- @e2e exclude Needs a live council source; proven by CouncilHarvestFlowTest::testAPublicDocumentBecomesAConceptPublicationWithItsFile and the live check of task 4.2. -->

- **GIVEN** a Notubiz meeting of 15 October with 3 public documents
- **WHEN** the harvest runs
- **THEN** the Raadsinformatie catalogue holds 3 concept publications with Woo category `infocat008`, each with its file attached

#### Scenario: A document not marked public stays out
<!-- @e2e exclude Fail-closed path; proven by CouncilHarvestFlowTest::testADocumentNotMarkedPublicIsNotWritten. -->

- **GIVEN** a meeting with one document the source marks confidential
- **WHEN** the harvest runs
- **THEN** no publication is written for that document

### Requirement: A document seen before is updated, not duplicated (REQ-CDH-003)

The write MUST upsert on the source identifier (`caseReference` `notubiz:{id}` or `ibabs:{id}`, later `sourceIdentifiers`). A second run over the same documents MUST NOT create new publications.

#### Scenario: Running twice creates nothing new
<!-- @e2e exclude Flow rule; proven by CouncilHarvestFlowTest::testASecondRunUpdatesAndCreatesNothing. -->

- **GIVEN** a harvest that wrote 3 publications
- **WHEN** it runs again and one document's title changed
- **THEN** the catalogue still holds 3 publications and the changed one carries the new title
