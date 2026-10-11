---
status: proposed
---

# Publications: withheld documents of a Woo decision

## ADDED Requirements

### Requirement: A withheld document is recorded apart from the publication, and is not public (REQ-WDW-001)

A fragment `lib/Settings/register.d/woo-decision-shows-what-was-withheld.json` SHALL add the schema `withheldDocument` to the publication register, with a `slug` and a `version`, and the properties `publication` (string, `format: uuid`, required), `position` (integer, minimum 1, required), `grounds` (array, at least one item, each `{code, article, label}` as strings, required), `source` (string, required) and `recordedAt` (string, `format: date-time`, required). Its `authorization` SHALL allow read, create, update and delete to `admin` only, as `wooAssessment` does. It SHALL have no `title` and no property that holds a file, a file id, a hash, a document reference or document text.

#### Scenario: An anonymous reader cannot read the records directly
<!-- @e2e exclude Authorization contract; proven by WithheldDocumentSchemaTest::testTheSchemaHasNoPublicReadRule, which reads the shipped fragment, and by a Newman request to OpenRegister's object API without credentials. -->

- **GIVEN** a stored `withheldDocument` for a public publication
- **WHEN** an anonymous client asks OpenRegister's object API for it
- **THEN** it is refused and nothing of the record is returned

#### Scenario: The schema cannot hold content
<!-- @e2e exclude Schema contract; proven by WithheldDocumentSchemaTest::testTheSchemaDeclaresNoContentProperty, which fails if a title, file, hash, reference or text property is ever added. -->

- **WHEN** the shipped `withheldDocument` schema is read
- **THEN** its properties are exactly `publication`, `position`, `grounds`, `source` and `recordedAt`

### Requirement: dossiq records the withheld documents through one named method (REQ-WDW-002)

`OCA\OpenCatalogi\Service\Woo\WithheldDocuments::record(string $publicationId, array $entries, string $source): array` SHALL replace the `withheldDocument` objects of that publication with one object per accepted entry, writing as the system. Each entry SHALL be `{position: int, grounds: list<string>}`; any other key, `title` included, SHALL be ignored and not stored. No title SHALL ever be recorded on this path. For each ground code it SHALL call `OCA\Dossiq\Woo\WooRefusalGrounds::byCode($code)` and store `code`, `article` and `label` as answered. It SHALL answer `{recorded: int, refused: list<{position: int, code: string, reason: string}>}`. An entry with a code `byCode()` answers null for SHALL be refused with reason `unknown-ground` and not stored. When dossiq is not installed, when `WooRefusalGrounds` does not resolve from the container, or when `byCode()` throws `WooRefusalGroundsUnavailable`, every entry SHALL be refused with reason `grounds-unavailable`, nothing SHALL be stored or removed, and the vendored snapshot SHALL NOT be read. When the publication does not exist, every entry SHALL be refused with reason `no-publication`. An entry without a position of at least 1 or without a ground code SHALL be refused with reason `invalid-entry` and not stored. A call with an empty `$entries` SHALL remove the publication's stored entries. The dossiq side SHALL call it after `WooPublicationService::publish()` has created or updated the publication, with one entry per `niet_openbaar` assessment holding exactly `position` and `grounds`; that call and its test are `dossiq/woo-decision-records-what-was-withheld`.

#### Scenario: dossiq records two withheld documents
<!-- @e2e exclude Cross-app PHP call; proven by WithheldDocumentsRecordTest::testTwoEntriesAreRecordedWithDossiqsLabels, built on a double of WooRefusalGrounds with REQ-WRG-007's exact keys, which fails on today's code because the class does not exist. -->

- **GIVEN** a published Woo decision and dossiq's list holding the ground 5.1.2.e
- **WHEN** `record()` is called with entries at positions 3 and 7, both citing 5.1.2.e, from source `dossiq`
- **THEN** it answers `recorded` 2 and no refusals
- **AND** two `withheldDocument` objects hold positions 3 and 7, each with code 5.1.2.e and the article and label dossiq's `byCode()` answered

#### Scenario: Content never gets in
<!-- @e2e exclude Fail-closed path; proven by WithheldDocumentsRecordTest::testExtraKeysAreNotStored. -->

- **GIVEN** an entry that also carries `fileId`, `sha256` and `documentRef`
- **WHEN** `record()` stores it
- **THEN** the stored object holds none of those keys

#### Scenario: A title is never recorded
<!-- @e2e exclude Fail-closed path; proven by WithheldDocumentsRecordTest::testATitleIsNeverStored, which fails on today's code because the class does not exist. -->

- **GIVEN** an entry `{position: 3, grounds: ["5.1.2.e"], title: "Advies over de locatiekeuze"}`
- **WHEN** `record()` stores it
- **THEN** the entry is recorded and the stored object has no `title`
- **AND** the public read of that publication carries no title for position 3

#### Scenario: No dossiq, nothing recorded
<!-- @e2e exclude Fail-closed path; proven by WithheldDocumentsRecordTest::testWithoutDossiqEveryEntryIsRefusedAndTheSnapshotIsNotRead and ::testAnUnavailableListRefusesEverything. -->

- **GIVEN** dossiq is not installed and a vendored grounds snapshot is present
- **WHEN** `record()` is called with one entry
- **THEN** the entry is refused with `grounds-unavailable`, nothing is stored and the snapshot is not read

#### Scenario: An unknown ground is refused, the rest is kept
<!-- @e2e exclude Validation path; proven by WithheldDocumentsRecordTest::testAnUnknownGroundRefusesOnlyItsEntry. -->

- **GIVEN** dossiq's list does not hold the code 5.2.5
- **WHEN** `record()` is called with one entry citing 5.1.2.e and one citing 5.2.5
- **THEN** one entry is recorded and the other is refused with `unknown-ground`

### Requirement: The public read of a Woo decision names what was withheld and why (REQ-WDW-003)

When the publication's catalogue has `showWithheld` true (REQ-PDP-004), the responses of `publications#show` and `federation#publication` SHALL carry in `withheld` one entry per stored `withheldDocument` of that publication, merged with the entries REQ-PDP-004 derives from a `wooBatch`, ordered by `position`. Every entry SHALL carry `position`, `grounds` (the codes, as REQ-PDP-004 defines) and `groundDetails` (list of `{code, article, label}`). An entry from a stored `withheldDocument` SHALL carry no title. A batch entry keeps the keys REQ-PDP-004 gives it. For a stored entry `groundDetails` SHALL be the stored values, with no lookup at read time. For a batch entry `groundDetails` SHALL be resolved through `OCA\OpenCatalogi\Service\Woo\RefusalGrounds`; a code it cannot resolve SHALL be answered with its code and empty `article` and `label`, never a guessed label. Nothing else of a `withheldDocument` SHALL be returned. When `showWithheld` is false, when the catalogue cannot be resolved, or when the publication is not public, `withheld` SHALL be absent, as REQ-PDP-004 requires. On `federation#publication`, which has no catalogue in its path, the catalogue is resolved from the publication's register and schema: `withheld` SHALL be present only when at least one catalogue holds them and every catalogue that does has `showWithheld` true. When the list cannot be read, `withheld` SHALL be absent, never empty. This list is generic (decision 182): it serves any publication a catalogue holds and any app that writes `withheldDocument` records; the Woo decision from dossiq is its first user, and the classes are named for the capability (`Service\Withheld\WithheldFromPublication`), not for Woo.

#### Scenario: A citizen sees which documents of a Woo decision were withheld and why
<!-- @e2e exclude Public API contract for the portal; proven by WithheldOnThePublicReadTest::testAnOptedInCatalogueShowsTheWithheldDocumentsOfADossiqDecision, which fails on today's code because no withheld list exists for a dossiq publication, and by the live check of task 3.4. -->

- **GIVEN** a catalogue with `showWithheld` true and a Woo decision published from dossiq with five documents, two of them withheld at positions 3 and 7 on ground 5.1.2.e and recorded through `record()`
- **WHEN** an anonymous reader fetches the publication through `publications#show`
- **THEN** `withheld` holds two entries, at positions 3 and 7, each with `grounds` ["5.1.2.e"] and `groundDetails` holding the article and label dossiq's list gave for 5.1.2.e
- **AND** no entry carries a title, a file, a hash or a document reference

#### Scenario: Without the opt-in nothing is said
<!-- @e2e exclude Fail-closed default; proven by WithheldOnThePublicReadTest::testWithoutOptInThereIsNoWithheldKeyEvenWithStoredEntries. -->

- **GIVEN** the same publication in a catalogue that did not opt in
- **WHEN** an anonymous reader fetches it
- **THEN** the response has no `withheld` key

#### Scenario: The federation endpoint says the same
<!-- @e2e exclude Public API contract; proven by WithheldOnThePublicReadTest::testTheFederationEndpointCarriesTheSameEntries. -->

- **GIVEN** the opted-in publication above
- **WHEN** a client fetches it through `federation#publication`
- **THEN** `withheld` equals the entries `publications#show` answers

#### Scenario: A batch entry gets its ground label too
<!-- @e2e exclude Merge of two sources; proven by WithheldOnThePublicReadTest::testBatchEntriesGainGroundDetailsAndUnknownCodesAreNotGuessed. -->

- **GIVEN** an opted-in publication created from a `wooBatch` with one document withheld on 5.1.2.e and one on a code `RefusalGrounds` cannot resolve
- **WHEN** a reader fetches it
- **THEN** the first entry's `groundDetails` holds the article and label from `RefusalGrounds`, and the second holds its code with empty `article` and `label`
