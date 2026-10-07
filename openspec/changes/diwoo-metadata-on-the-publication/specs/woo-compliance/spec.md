---
status: proposed
---

# DiWoo metadata on the publication

## ADDED Requirements

### Requirement: The publication stores its DiWoo metadata (REQ-DWP-001)

The publication schema SHALL declare these optional properties, added in a new fragment `lib/Settings/register.d/diwoo-metadata-on-the-publication.json` under the `publication` schema with its `slug` and a bumped `version`:

- `documentsoort`: string, the resource URI of a member of the DiWoo documentsoorten list (REQ-DWP-002).
- `fileDocumentsoorten`: object, keyed by the id of a file attached to the publication, each value a documentsoorten URI. It overrides `documentsoort` for that one file.
- `language`: string, a code from the bundled language list, default `nl`.
- `creationDate`: string, `format: date`, the day the document was made.
- `validFrom`: string, `format: date`, the day the content takes legal effect.
- `validUntil`: string, `format: date`, the day the content loses legal effect.

`title` SHALL be the official title (`diwoo:officieleTitel`). `validFrom` and `validUntil` SHALL be independent of `publicationDate` and `depublicationDate`. A `validUntil` before `validFrom` SHALL be refused. The national announcement (`NationalAnnounceWidget.vue`, `NationalIndexService::composeNotice()`) SHALL take its effective date from the stored `validFrom` when the editor gives none.

#### Scenario: An editor records the legal effect apart from the publication date
<!-- @e2e exclude Schema contract; proven by PublicationDiwooFieldsTest::testValidityDatesAreStoredApartFromThePublicationDate, which validates the payload with the Opis validator against the merged shipped schema. -->

- **GIVEN** a publication with `publicationDate` 2026-11-02
- **WHEN** it is saved with `validFrom` 2027-01-01 and `validUntil` 2030-12-31
- **THEN** the stored publication holds both dates and its `publicationDate` is still 2026-11-02

#### Scenario: A validity that ends before it starts
<!-- @e2e exclude Server-side refusal on the save path; proven by DiwooCompletenessListenerTest::testAValidityThatEndsBeforeItStartsIsRefused. -->

- **GIVEN** a publication with `validFrom` 2027-01-01
- **WHEN** it is saved with `validUntil` 2026-12-31
- **THEN** the save is refused naming `validUntil`

#### Scenario: The language defaults to Dutch
<!-- @e2e exclude Schema default; proven by PublicationDiwooFieldsTest::testTheLanguageDefaultsToDutch. -->

- **GIVEN** a publication saved without `language`
- **WHEN** it is read back
- **THEN** its `language` is `nl`

#### Scenario: The announcement takes the stored effective date
<!-- @e2e exclude Server-side composition of the national notice; proven by NationalIndexServiceTest::testTheNoticeTakesTheStoredValidFromAsItsEffectiveDate. -->

- **GIVEN** a publication with `validFrom` 2027-01-01
- **WHEN** a notice is composed for it without an effective date
- **THEN** the notice's `effectiveDate` is 2027-01-01

### Requirement: A document type comes from the national documentsoorten list (REQ-DWP-002)

OpenCatalogi SHALL bundle the DiWoo documentsoorten list as it is enumerated in the DiWoo value-list XSD the instance declares (`StandardsVersionService::DIWOO_LISTS_XSD`), with each member's code, Dutch label and resource URI, the XSD version and the source URL recorded beside it. `TooiVocabularyService` SHALL expose `resolveDocumentsoort(?string $value): ?array` returning `{code, label, uri}` for a URI, code or exact label (case-insensitive), and `documentsoortList(): array`. The same SHALL hold for the language list (`resolveLanguage()`, `languageList()`), taken from the vocabulary the DiWoo XSD names for the language element.

A pre-save listener SHALL rewrite a `documentsoort` or `fileDocumentsoorten` value given as a code or label to its URI, and SHALL refuse a value that resolves to no member. `GET /api/woo/documentsoorten` SHALL list the members as `{code, label, uri}` with the same posture as `GET /api/woo/categories`.

#### Scenario: A known document type given as a label
<!-- @e2e exclude Pre-save normalisation; proven by DiwooCompletenessListenerTest::testALabelIsStoredAsItsUri, built on the real ObjectCreatingEvent. -->

- **GIVEN** a publication saved with `documentsoort` `besluit`
- **WHEN** the save completes
- **THEN** the stored `documentsoort` is the resource URI of the member labelled besluit

#### Scenario: An unknown document type
<!-- @e2e exclude Pre-save refusal; proven by DiwooCompletenessListenerTest::testAnUnknownDocumentsoortIsRefused. -->

- **GIVEN** a publication saved with `documentsoort` `memo van de wethouder`
- **WHEN** the save runs
- **THEN** the save is refused naming `documentsoort`
- **AND** nothing is stored

#### Scenario: One file of a publication is an annex
<!-- @e2e exclude Sitemap XML contract; proven by SitemapServiceTest::testAFileOverrideGivesThatDocumentItsOwnType. -->

- **GIVEN** a publication with `documentsoort` for a decision and `fileDocumentsoorten` naming one file as an annex
- **WHEN** its DiWoo sitemap page is rendered
- **THEN** that file's `diwoo:Document` carries the annex type and the other files carry the decision type

### Requirement: A Woo publication without the metadata the Woo-index requires does not go public (REQ-DWP-003)

The mandatory DiWoo fields SHALL be the elements the DiWoo metadata XSD at `StandardsVersionService::DIWOO_METADATA_XSD` requires under `diwoo:DiWoo`, recorded in a fixture read from that XSD. At the time of writing these are expected to include the official title, the publisher with a TOOI organisation URI, the informatiecategorie, the creation date and the documenthandeling; where the XSD differs, the XSD wins.

A listener on OpenRegister's `ObjectCreatingEvent` and `ObjectUpdatingEvent`, `OCA\OpenCatalogi\Listener\DiwooCompletenessListener`, SHALL apply to a publication the DiWoo sitemaps would list: one with a `wooCategory`, or one in a schema a category declares (REQ-WIC-003). When the save leaves `publicationDate` set (public now or scheduled), and a mandatory field is missing or does not resolve through `TooiVocabularyService` or the category registry, the listener SHALL stop the event with errors naming every missing field, so OpenRegister refuses the save with `HookStoppedException`. A save without `publicationDate` SHALL be accepted: a draft may be incomplete. `WooService::publishBatch()` SHALL report the refusal with the fields named and SHALL NOT mark the batch published.

#### Scenario: A Woo publication without a category is not made public
<!-- @e2e exclude Fail-closed contract on OpenRegister's pre-save event; proven by DiwooCompletenessListenerTest::testAWooPublicationMissingAMandatoryFieldIsNotMadePublic, which fails on today's code because no listener exists and the save succeeds. -->

- **GIVEN** a publication in a schema the Woo category registry declares, with a title and a TOOI-resolvable organisation, and no `wooCategory`
- **WHEN** an editor saves it with `publicationDate` set to now
- **THEN** the save is refused naming `wooCategory`
- **AND** the publication is not public and does not appear in any DiWoo sitemap

#### Scenario: A scheduled publication is checked when it is scheduled, not when the date passes
<!-- @e2e exclude Pre-save refusal; proven by DiwooCompletenessListenerTest::testAScheduledWooPublicationIsCheckedWhenScheduled. -->

- **GIVEN** a Woo publication whose organisation has no TOOI identifier
- **WHEN** an editor sets its `publicationDate` to next week
- **THEN** the save is refused naming the publisher

#### Scenario: A draft may be incomplete
<!-- @e2e exclude Pre-save path; proven by DiwooCompletenessListenerTest::testADraftWithoutPublicationDateIsAccepted. -->

- **GIVEN** a Woo publication without `wooCategory` and without `publicationDate`
- **WHEN** it is saved
- **THEN** it is stored

#### Scenario: A complete Woo publication goes public
<!-- @e2e exclude Pre-save path; proven by DiwooCompletenessListenerTest::testACompleteWooPublicationIsAccepted. -->

- **GIVEN** a Woo publication with every mandatory field resolvable
- **WHEN** it is saved with `publicationDate` now
- **THEN** it is stored and public

#### Scenario: A publication outside the Woo is not held to DiWoo
<!-- @e2e exclude Pre-save path; proven by DiwooCompletenessListenerTest::testAPublicationNoWooCategoryListsIsNotChecked. -->

- **GIVEN** a publication in a schema no Woo category declares and without `wooCategory`
- **WHEN** it is saved with `publicationDate` now
- **THEN** it is stored and public

### Requirement: The sitemap emits the stored DiWoo values (REQ-DWP-004)

`SitemapService::mapDiwooDocument()` SHALL emit, from the stored fields: `diwoo:officieleTitel` from `title`, the document type from `fileDocumentsoorten[fileId]` or else `documentsoort`, the language from `language`, `diwoo:creatiedatum` from `creationDate`, the geldigheid start and end from `validFrom` and `validUntil`, and `diwoo:verantwoordelijke` with the same TOOI organisation URI as the publisher until a separate responsible organisation is stored. Element names and nesting SHALL follow the XSD fixture.

For a record created before the moment stored in app config `diwoo_fields_introduced_at` (written once by the repair step), a missing stored field SHALL fall back to today's derivation: `@self.created` for the creation date, `nl` for the language. A record created after that moment SHALL get no fallback. A document that, after the fallback, still lacks a mandatory field SHALL be omitted from the page and listed by `collectDiwooViolations()` with its location, the field and the reason. The page SHALL still be served (WOO-TOOI-004).

#### Scenario: The sitemap emits what the publication stores
<!-- @e2e exclude Sitemap XML contract on a public endpoint; proven by SitemapServiceTest::testTheDocumentCarriesTheStoredDiwooValues, which fails on today's code because no officieleTitel, document type, language or geldigheid is emitted. -->

- **GIVEN** a public Woo publication with title "Besluit parkeerregulering", `documentsoort` for a decision, `language` nl, `creationDate` 2026-09-30, `validFrom` 2027-01-01 and one public file
- **WHEN** its category sitemap page is requested
- **THEN** the file's `diwoo:Document` carries that official title, that document type, that language, creatiedatum 2026-09-30 and a geldigheid starting 2027-01-01
- **AND** its creatiedatum is not the moment the object was saved

#### Scenario: A legacy record falls back to the derivation
<!-- @e2e exclude Sitemap XML contract; proven by SitemapServiceTest::testALegacyRecordFallsBackToTheDerivation. -->

- **GIVEN** a public Woo publication created before `diwoo_fields_introduced_at`, without `creationDate` or `language`
- **WHEN** its category sitemap page is requested
- **THEN** its creatiedatum is the date of `@self.created` and its language is nl

#### Scenario: A legacy record the Woo-index would refuse is held back and reported
<!-- @e2e exclude Fail-closed sitemap contract; proven by SitemapServiceTest::testADocumentMissingAMandatoryFieldIsOmittedAndReported. -->

- **GIVEN** a legacy public Woo publication whose organisation has no TOOI identifier, and another complete one in the same category
- **WHEN** the category sitemap page is requested
- **THEN** the page lists only the complete one's documents
- **AND** `GET /api/{catalogSlug}/sitemaps/{categoryCode}/validate` lists the held-back document with the publisher field and the reason

#### Scenario: A new record gets no fallback
<!-- @e2e exclude Sitemap XML contract; proven by SitemapServiceTest::testARecordCreatedAfterTheMigrationGetsNoFallback. -->

- **GIVEN** a Woo publication created after `diwoo_fields_introduced_at` whose stored `creationDate` was removed directly in the database
- **WHEN** its category sitemap page is requested
- **THEN** its document is omitted and reported, not dated from `@self.created`

### Requirement: A documentsoort written into the summary is moved to its field (REQ-DWP-005)

The repair step `OCA\OpenCatalogi\Repair\MoveDocumentsoortOutOfSummary` SHALL be registered under `<post-migration>` in `appinfo/info.xml`, after `InitializeSettings`. It SHALL walk every publication object in every register through OpenRegister's `ObjectService`, the way `RenameDutchPublicationColumns` reaches every shard. For a publication whose trimmed `summary` resolves through `resolveDocumentsoort()` and whose `documentsoort` is empty, it SHALL set `documentsoort` to the member's URI and empty `summary`. It SHALL leave every other summary, and every publication that already has a `documentsoort`, as it is. It SHALL write `diwoo_fields_introduced_at` once, the first time it runs, and never move it. It SHALL report how many publications it moved and how many summaries it left. A second run SHALL move nothing.

#### Scenario: A summary that is a document type
<!-- @e2e exclude Repair step, no browser surface; proven by MoveDocumentsoortOutOfSummaryTest::testASummaryThatIsADocumentTypeIsMoved, which fails on today's code because the class does not exist. -->

- **GIVEN** a publication handed off by filinq with `summary` `besluit` and no `documentsoort`
- **WHEN** the repair step runs
- **THEN** its `documentsoort` is the URI of the besluit member and its `summary` is empty

#### Scenario: A real summary is left alone
<!-- @e2e exclude Repair step; proven by MoveDocumentsoortOutOfSummaryTest::testARealSummaryIsLeftAlone. -->

- **GIVEN** a publication with `summary` "Het college besluit over parkeren in de binnenstad"
- **WHEN** the repair step runs
- **THEN** its summary and `documentsoort` are unchanged

#### Scenario: The step runs twice
<!-- @e2e exclude Repair step idempotence; proven by MoveDocumentsoortOutOfSummaryTest::testASecondRunMovesNothing. -->

- **GIVEN** the repair step has run once
- **WHEN** it runs again
- **THEN** it reports zero moved and `diwoo_fields_introduced_at` is unchanged

#### Scenario: The step is registered
<!-- @e2e exclude Registration contract; proven by MoveDocumentsoortOutOfSummaryTest::testTheStepIsRegisteredPostMigration, which reads appinfo/info.xml. -->

- **WHEN** `appinfo/info.xml` is read
- **THEN** `OCA\OpenCatalogi\Repair\MoveDocumentsoortOutOfSummary` is a post-migration repair step after `InitializeSettings`

## MODIFIED Requirements

### Requirement: Map publication + file metadata to DIWOO Document XML structure (WOO-006)

The system MUST map publication and file metadata to the DIWOO Document XML structure, reading the DiWoo fields stored on the publication (REQ-DWP-001) and falling back to a derivation only for a record created before the DiWoo fields were introduced (REQ-DWP-004).

**Priority:** Must **Status:** Implemented, extended by diwoo-metadata-on-the-publication

#### Scenario: publication and file metadata mapped to DIWOO
- GIVEN a publication with attached files
- WHEN the DIWOO sitemap is generated
- THEN each file MUST map to a `diwoo:Document` with loc, lastmod, officieleTitel, creatiedatum, publisher, verantwoordelijke, format, informatiecategorie, documentsoort when stored, language, geldigheid when stored, soortHandeling and atTime
- AND a document lacking a mandatory field after the fallback MUST be omitted from the page and reported by the DIWOO validator
