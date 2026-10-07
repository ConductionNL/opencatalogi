---
status: proposed
---

# Woo compliance

## ADDED Requirements

### Requirement: The TOOI lists are read from OpenRegister's vocabulary register (REQ-WVC-001)

`TooiVocabularyService` SHALL resolve informatiecategorieën, soort handeling, organisaties, documentsoorten and talen through OpenRegister's vocabulary register (the concept repository behind `vocabulary#resolveByUri`, `resolveByNotation` and `listConcepts`), keeping its public method names and return shapes. `lib/Settings/tooi_waardelijsten.json` SHALL be removed. When the register cannot be read, a resolve SHALL throw; callers SHALL treat it as unresolved (omit and report in DiWoo, refuse on save), never fall back to a bundled copy. A deprecated concept SHALL resolve for existing records and SHALL be refused for a new value. Publication properties that hold a list value (`wooCategory`, `soortHandeling`, `documentsoort`, `language`) SHALL declare `x-openregister-concepts` with their scheme and `store: uri`.

#### Scenario: The category comes from the register
<!-- @e2e exclude Service source; proven by TooiVocabularyServiceTest::testTheCategoryResolvesFromTheVocabularyRegister, which fails on today's code because the service reads the bundled JSON. -->

- **GIVEN** the vocabulary register seeded with the 17 informatiecategorieën
- **WHEN** `resolveInformatiecategorie('infocat012')` is called
- **THEN** it answers the URI and label from the register

#### Scenario: An unreadable register is not a stale list
<!-- @e2e exclude Fail-closed path; proven by TooiVocabularyServiceTest::testAnUnreadableRegisterThrows. -->

- **GIVEN** a vocabulary read that throws
- **WHEN** a category is resolved
- **THEN** the call throws and no bundled value is used

### Requirement: Each scheme is refreshed from its national source daily (REQ-WVC-002)

A daily `TimedJob` `TooiSchemeRefresh` SHALL, for each scheme OpenCatalogi uses, download the JSON-LD from the source URL recorded in app config `tooi_scheme_sources` (seeded with the TOOI value-list download URLs) and call OpenRegister's `VocabularyImportService::importJsonLd()`, storing per scheme `{lastRefreshAt, created, updated, deprecated, error}`. It SHALL NOT import when the download fails or does not parse, or when the import would deprecate more than a third of the scheme's active concepts; it SHALL then keep the scheme as it is and raise a pipeline incident (`woo-national-output-assurance`, or a log error when that change is not merged).

#### Scenario: A new TOOI entry is usable the next day without a release
<!-- @e2e exclude Background job against a recorded source; proven by TooiSchemeRefreshTest::testANewConceptFromTheSourceIsImported, which fails on today's code because no job exists. -->

- **GIVEN** the documentsoorten source gains an entry "Adviesaanvraag"
- **WHEN** the job runs
- **THEN** the register holds the new concept and a publication can be saved with it, with no new release

#### Scenario: A broken download empties nothing
<!-- @e2e exclude Fail-closed path; proven by TooiSchemeRefreshTest::testAMassDeprecationIsRefused and ::testAFailedDownloadKeepsTheScheme. -->

- **GIVEN** a source answer holding 2 of 40 documentsoorten
- **WHEN** the job runs
- **THEN** nothing is imported, the 40 concepts stay active and a failure is raised

### Requirement: Refusal grounds come from dossiq, with a read-only snapshot for redaction only (REQ-WVC-003)

`WooService::WEIGERINGSGRONDEN` SHALL be removed. `OCA\OpenCatalogi\Service\Woo\RefusalGrounds::list(): array` SHALL, when dossiq is installed, answer `OCA\Dossiq\Woo\WooRefusalGrounds::list()` (keys `id, code, article, paragraph, letter, label, description, parent, status, legalSource`), and SHALL let `WooRefusalGroundsUnavailable` propagate. When dossiq is not installed, it SHALL answer the entries of `lib/Settings/woo-refusal-grounds.snapshot.json`, a vendored copy of dossiq's REQ-WRG-008 snapshot with its version, marked `source: snapshot`. `WooService::updateAssessment()` SHALL accept a ground only when its `code` is in that list, and the assessment form SHALL offer the list. The snapshot SHALL serve only the redaction assessment of publication batches (row 4.8); it SHALL NOT be offered to any Woo request path (D12). The Woo settings SHALL say, when the snapshot is in use, that the grounds come from a fixed copy, can be picked but not edited, and are maintained in dossiq. A repair step SHALL map stored `weigeringsgronden` codes on `wooAssessment` to dossiq's codes with the mapping dossiq's REQ-WRG-006 uses, leave an unmappable code as it is, flag it, and list it.

#### Scenario: With dossiq, its list is the list
<!-- @e2e exclude Cross-app call; proven by RefusalGroundsTest::testWithDossiqTheListIsDossiqs, built on a double of WooRefusalGrounds with REQ-WRG-007's exact keys, and its matching test on the dossiq side. -->

- **GIVEN** dossiq installed with a ground 5.1.2.e and a retired ground
- **WHEN** an officer cites grounds on an assessment
- **THEN** 5.1.2.e is offered and accepted, and the retired one is not offered

#### Scenario: dossiq is installed but unreadable
<!-- @e2e exclude Fail-closed path; proven by RefusalGroundsTest::testAnUnreadableDossiqRefusesTheSaveAndDoesNotUseTheSnapshot. -->

- **GIVEN** dossiq installed and `list()` throwing `WooRefusalGroundsUnavailable`
- **WHEN** an officer saves an assessment with a ground
- **THEN** the save is refused with the reason and the snapshot is not used

#### Scenario: Without dossiq, grounds can be picked for redaction and not edited

- **GIVEN** no dossiq
- **WHEN** an administrator opens the Woo settings, and an officer redacts a document for publication
- **THEN** the settings say the grounds are a fixed copy maintained in dossiq
- **AND** the officer can cite 5.1.2.e from the snapshot on a redacted passage

### Requirement: Every list entry is on one page for integrators (REQ-WVC-004)

`GET /api/value-lists` (public, CORS) SHALL answer every entry of every list OpenCatalogi uses (the TOOI schemes of REQ-WVC-001, the DCAT lists in `dcat_waardelijsten.json`, and the refusal grounds of REQ-WVC-003) as `{scheme, schemeUri, code, label, uri, status, lastRefreshAt}`, filterable by `scheme`. A public page `/apps/opencatalogi/value-lists` SHALL render the same, grouped per scheme, with a copy button per URI.

#### Scenario: An integrator copies a URI

- **GIVEN** the published value-lists page
- **WHEN** an integrator opens it and filters on documentsoorten
- **THEN** each entry shows its code, label, URI and status, and the URI can be copied with one button

#### Scenario: The JSON lists what the page shows
<!-- @e2e exclude Public API contract; proven by ValueListsControllerTest::testEveryUsedSchemeIsListedWithUris, which fails on today's code because the route does not exist. -->

- **WHEN** `GET /api/value-lists` is requested anonymously
- **THEN** it answers entries for every scheme OpenCatalogi uses, each with a URI

### Requirement: The Woo information categories are audited data, not a constant (REQ-WVC-005)

`WooCategory::ALL` SHALL no longer be the source of the Woo information categories. `Woo\WooCategoryRegistry` SHALL read the categories (code, Dutch and English label, URI, status) from the informatiecategorieën scheme in OpenRegister's vocabulary register, and SHALL merge the categories an operator added as data as it does today. The English label SHALL come from the concept's English label when the scheme carries one, and otherwise from the app's translation of the Dutch label in `l10n/`; it SHALL NOT come from a PHP constant. `BatchPublicationWriter` and `SavedSearchService` SHALL test membership through `WooCategoryRegistry::find()` instead of `WooCategory::ALL`. `WooCategory::WOO_REQUEST` MAY stay as a code. Every create, update and deprecation of an informatiecategorie concept, whether written by `TooiSchemeRefresh` or by an administrator, and every create, update and delete of an operator-added category, SHALL leave an OpenRegister audit trail row with who (the administrator, or `system` for the refresh job), when, and the values before and after. When audit trails are switched off for the vocabulary register, `TooiSchemeRefresh` SHALL NOT import the informatiecategorieën scheme; it SHALL keep the scheme as it is and raise the failure, because a category change nobody can trace is what the row forbids. This is the OpenCatalogi half of row 12.30; the organisation half is `openregister/history-schema-and-settings-edits-audited`.

#### Scenario: A category label changed at the source is on the audit trail
<!-- @e2e exclude Background job against a recorded source; proven by TooiSchemeRefreshTest::testACategoryChangeIsOnTheAuditTrailWithBeforeAndAfter, which fails on today's code because the categories are a PHP constant and no job exists. -->

- **GIVEN** the informatiecategorieën source renames infocat012
- **WHEN** the refresh job runs
- **THEN** the register holds the new label
- **AND** the audit trail of that concept holds one row with actor `system`, the time, the old label and the new label

#### Scenario: The categories no longer come from the constant
<!-- @e2e exclude Source of a registry; proven by WooCategoryRegistryTest::testTheCategoriesComeFromTheRegisterNotTheConstant, which fails on today's code because the registry merges WooCategory::ALL. -->

- **GIVEN** the vocabulary register holds 17 art. 3.3 categories and one deprecated category
- **WHEN** the registry lists the categories for a new publication
- **THEN** it answers the 17 from the register plus the operator's own, and not the deprecated one

#### Scenario: No audit trail, no refresh of the categories
<!-- @e2e exclude Fail-closed path; proven by TooiSchemeRefreshTest::testWithoutAuditTrailsTheCategoriesAreNotRefreshed. -->

- **GIVEN** audit trails switched off for the vocabulary register
- **WHEN** the refresh job runs with a changed category at the source
- **THEN** the informatiecategorieën scheme is not imported and a failure is raised

## MODIFIED Requirements

### Requirement: Bundled TOOI/DiWoo value lists and a DIWOO validator (WOO-TOOI-004)

The TOOI/DiWoo value lists (informatiecategorieën, organisatie-identificatoren,
soortHandeling, documentsoorten, talen) MUST be read from OpenRegister's
vocabulary register and refreshed from their national source (REQ-WVC-001,
REQ-WVC-002); OpenCatalogi MUST NOT ship a bundled copy. Admin/publisher
settings MUST provide a "Validate DIWOO output" action that runs the sitemap
mapping in a dry-run mode and reports, per document, any axis that could not
resolve to an official value-list URI. The validator MUST be advisory: it MUST
NOT prevent the sitemap from being served.

#### Scenario: value lists resolvable at render time

- **GIVEN** OpenRegister's vocabulary register holds the TOOI schemes
- **WHEN** a DIWOO sitemap is generated
- **THEN** the informatiecategorie, organisatie, and soortHandeling value lists
  MUST be resolvable for binding

#### Scenario: validator reports an unresolved axis

- **GIVEN** a catalog with one publication whose organisation lacks a `tooiIdentifier`
- **WHEN** the publisher runs "Validate DIWOO output"
- **THEN** the report MUST list that document with the unresolved `publisher` axis
- **AND** the sitemap endpoint MUST still serve XML for the catalog
