---
status: proposed
---

# Publication plain language and translation

## ADDED Requirements

### Requirement: A publication carries a plain language summary with its level (REQ-PPL-001)

The publication schema SHALL carry `plainSummary` (string, at most 1,500 characters), `plainSummaryLevel` (enum `A2`, `B1`, `B2`, default `B1`) and `plainSummaryProvenance` (object, read-only to callers: `generatedBy` `person` or `ai`, `provider`, `at`, `acceptedBy`). A save with `plainSummary` and an empty level SHALL be refused naming `plainSummaryLevel`. The public publication response SHALL carry `plainSummary` and `plainSummaryLevel`. `DcatMappingService` SHALL emit `plainSummary` as `dct:abstract` with the publication's `language` (default `nl`) as its language tag. The publication page SHALL show the field with its level beside the summary.

#### Scenario: An officer writes a B1 summary and the reader sees it

- **GIVEN** a publication "Besluit op bezwaar parkeervergunning"
- **WHEN** an officer writes the plain summary "U kreeg geen parkeervergunning. U maakte bezwaar. De gemeente blijft bij het besluit." at level B1 and saves
- **THEN** the publication page shows it with "B1"
- **AND** the public API answers it with level B1

#### Scenario: The DCAT feed carries it
<!-- @e2e exclude Feed contract; proven by DcatMappingServiceTest::testThePlainSummaryIsEmittedAsAbstractWithItsLanguage, which fails on today's code because no abstract is emitted. -->

- **GIVEN** a public publication with a plain summary and language nl
- **WHEN** the DCAT feed is rendered
- **THEN** its dataset carries `dct:abstract` with that text tagged `nl`

### Requirement: A drafted plain summary is a suggestion an officer accepts (REQ-PPL-002)

`OCA\OpenCatalogi\Service\Publication\PlainLanguageDrafter::draft(string $publicationId): array` SHALL, when `OCP\TaskProcessing\IManager::getAvailableTaskTypes()` lists `core:text2text:simplification`, schedule that task with the publication's title, summary and description, and return `{taskId}`; `GET /api/publications/{id}/plain-summary-draft/{taskId}` SHALL return `{status, text?, error?}`. The caller SHALL need the update right on the publication. `POST /api/publications/{id}/plain-summary` with `{text, level, fromTaskId?}` SHALL store the text and level with provenance `ai` and the provider when `fromTaskId` is given and the text came from that task, else `person`, with `acceptedBy` the caller. When no provider is available the draft route SHALL answer 409 `no-provider`, and the page SHALL hide Draft and say no text assistant is installed. A failed or timed-out task SHALL store nothing.

#### Scenario: A draft is a suggestion until accepted

- **GIVEN** a TaskProcessing provider for simplification and a publication without a plain summary
- **WHEN** the officer chooses Draft plain summary
- **THEN** the page shows the draft as a suggestion and the stored `plainSummary` is still empty
- **AND** after the officer edits and accepts it, it is stored with provenance `ai`, the provider and the officer as `acceptedBy`

#### Scenario: Without a provider
<!-- @e2e exclude Absent provider path; proven by PlainLanguageDrafterTest::testWithoutAProviderTheDraftRouteAnswers409. -->

- **GIVEN** no provider for `core:text2text:simplification`
- **WHEN** the draft route is called
- **THEN** the answer is 409 `no-provider` and nothing is scheduled

#### Scenario: A failed task stores nothing
<!-- @e2e exclude Failure path; proven by PlainLanguageDrafterTest::testAFailedTaskStoresNothing. -->

- **GIVEN** a scheduled draft task that fails
- **WHEN** the officer polls it
- **THEN** the answer carries the error and the publication is unchanged

### Requirement: A publication can be translated, each translation accepted by an officer (REQ-PPL-003)

Gated on decision D10: build only once Ruben keeps row 14.7.

The publication schema SHALL carry `translations`, an object keyed by a language code from the bundled language list, each `{title, summary, plainSummary, provenance}`. A draft SHALL go through `core:text2text:translate` with the same suggestion-then-accept rule as REQ-PPL-002. The public publication response SHALL answer the translated fields when `?lang=<code>` or `Accept-Language` names a stored translation, with `language` set to it and `translatedFrom` set to the original language, and the original otherwise.

#### Scenario: A reader asks for English

- **GIVEN** a publication with an accepted English translation
- **WHEN** a reader asks the public API with `?lang=en`
- **THEN** the title and summaries are the English ones, with `language` en and `translatedFrom` nl
