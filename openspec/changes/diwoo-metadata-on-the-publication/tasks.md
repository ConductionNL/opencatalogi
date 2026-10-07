# Tasks: diwoo-metadata-on-the-publication

Read `openspec/woo-build-rules.md` before the first command. For every OpenRegister double use the `environmentAwareDouble()` pattern from `tests/Unit/Service/SitemapServiceTest.php` (it is a private method per test class, not a shared helper: copy it). Look up the real signature first: `OCA\OpenRegister\Event\ObjectCreatingEvent` and `ObjectUpdatingEvent` (`getObject()`, `setErrors()`, `stopPropagation()`, `setModifiedData()`) and `OCA\OpenRegister\Service\ObjectService::findAll()` / `saveObject()` on `ConductionNL/openregister` branch `development` or in `vendor`.

## 1. Fixture from the authority

- [ ] 1.1 Fetch the DiWoo metadata XSD at `StandardsVersionService::DIWOO_METADATA_XSD` and the lists XSD at `DIWOO_LISTS_XSD`. Record the mandatory elements under `diwoo:DiWoo` in `tests/fixtures/diwoo/0.9.8-mandatory-elements.json`, and bundle the documentsoorten and the language members in `lib/Settings/tooi_waardelijsten.json` with code, label, URI, XSD version and source URL (REQ-DWP-002, REQ-DWP-003). Verify: `tests/Unit/Service/TooiVocabularyServiceTest.php::testTheBundledDocumentsoortenMatchTheDeclaredXsd`, which reads the fixture and fails on today's code because no documentsoorten list is bundled. If the XSD cannot be fetched, stop and say so; do not invent the list.

## 2. Schema

- [ ] 2.1 Add `lib/Settings/register.d/diwoo-metadata-on-the-publication.json` with the six properties of REQ-DWP-001 under the `publication` schema, its `slug` and a bumped `version` (REQ-DWP-001). Verify: `tests/Unit/Settings/PublicationDiwooFieldsTest.php::testValidityDatesAreStoredApartFromThePublicationDate` and `::testTheLanguageDefaultsToDutch`, which validate the payload with the Opis validator against the merged shipped schema (as `RegisterFragmentMergeTest` merges it). A fragment without `slug` is rejected by OpenRegister and the import still reports success: assert the slug in the test.
- [ ] 2.2 Add the Dutch and English labels and descriptions for the new properties so `npm run check:schema-l10n` passes (REQ-DWP-001). Verify: `npm run check:schema-l10n` exits 0.

## 3. Vocabulary seam

- [ ] 3.1 Add `TooiVocabularyService::resolveDocumentsoort()`, `documentsoortList()`, `resolveLanguage()` and `languageList()` (REQ-DWP-002). Verify: `TooiVocabularyServiceTest::testADocumentsoortResolvesFromUriCodeOrLabel` and `::testAnUnknownDocumentsoortResolvesToNull`.
- [ ] 3.2 Add route `woo#documentsoorten` at `GET /api/woo/documentsoorten` beside `woo#categories`, with the same annotations as `WooController::categories()` (REQ-DWP-002). Verify: `tests/Unit/Controller/WooControllerTest.php::testDocumentsoortenListsTheBundledMembers`, plus a test reading `appinfo/routes.php` for the route name, so the method has a caller.

## 4. Fail closed before a Woo publication goes public

- [ ] 4.1 Add `OCA\OpenCatalogi\Listener\DiwooCompletenessListener`, registered in `lib/AppInfo/Application.php` for `ObjectCreatingEvent` and `ObjectUpdatingEvent` beside `CatalogSchemaEventListener`. It normalises a documentsoort to its URI, refuses an unknown value, refuses `validUntil` before `validFrom`, and stops the event naming every missing mandatory field when `publicationDate` is set (REQ-DWP-001, REQ-DWP-002, REQ-DWP-003). Verify: `tests/Unit/Listener/DiwooCompletenessListenerTest.php`, built on the REAL event classes, with `::testAWooPublicationMissingAMandatoryFieldIsNotMadePublic` (fails today: no listener exists, the save succeeds), `::testAScheduledWooPublicationIsCheckedWhenScheduled`, `::testADraftWithoutPublicationDateIsAccepted`, `::testACompleteWooPublicationIsAccepted`, `::testAPublicationNoWooCategoryListsIsNotChecked`, `::testALabelIsStoredAsItsUri`, `::testAnUnknownDocumentsoortIsRefused`, `::testAValidityThatEndsBeforeItStartsIsRefused`.
- [ ] 4.2 Assert the wiring: a test reads `Application::register()` through a recording `IRegistrationContext` and finds `DiwooCompletenessListener` on both events (REQ-DWP-003). Verify: `tests/Unit/AppInfo/ApplicationRegisterInvariantTest.php::testTheDiwooCompletenessListenerIsRegisteredOnBothPreSaveEvents`.
- [ ] 4.3 `WooService::publishBatch()` catches the `HookStoppedException` from the publish save, reports the named fields per publication and leaves the batch unpublished (REQ-DWP-003). Verify: `tests/Unit/Service/WooServiceTest.php::testABatchWithAnIncompleteWooPublicationIsNotMarkedPublished`.
- [ ] 4.4 The editor shows the refusal's field names instead of a generic error (REQ-DWP-003). Verify: `tests/e2e/diwoo-metadata.spec.ts` saves a Woo publication without a category with a publication date and sees `wooCategory` named; the spec carries `@e2e` references to REQ-DWP-003.

## 5. Sitemap reads the record

- [ ] 5.1 `SitemapService::mapDiwooDocument()` emits officieleTitel, documentsoort (file override first), language, creatiedatum, geldigheid and verantwoordelijke from the stored fields, with the fallback only for records created before `diwoo_fields_introduced_at` (REQ-DWP-004, WOO-006). Verify: `tests/Unit/Service/SitemapServiceTest.php::testTheDocumentCarriesTheStoredDiwooValues` (fails today: none of these elements is emitted), `::testALegacyRecordFallsBackToTheDerivation`, `::testARecordCreatedAfterTheMigrationGetsNoFallback`, `::testAFileOverrideGivesThatDocumentItsOwnType`.
- [ ] 5.2 A document still missing a mandatory field is omitted from the page and listed by `collectDiwooViolations()`; the page is still served (REQ-DWP-004, WOO-TOOI-004). Verify: `SitemapServiceTest::testADocumentMissingAMandatoryFieldIsOmittedAndReported`, and a controller test through the `validate` route.
- [ ] 5.3 Validate one rendered page against the fetched XSD in a test, so element names and nesting follow the authority and not this spec's prose (REQ-DWP-004). Verify: `SitemapServiceTest::testARenderedPageValidatesAgainstTheDeclaredXsd`, using `DOMDocument::schemaValidate()` on the fixture copy of the XSD.

## 6. National announcement

- [ ] 6.1 `NationalIndexService::composeNotice()` takes `validFrom` as the effective date when none is given, and `NationalAnnounceWidget.vue` prefills it (REQ-DWP-001). Verify: `tests/Unit/Service/Publication/NationalIndexServiceTest.php::testTheNoticeTakesTheStoredValidFromAsItsEffectiveDate`.

## 7. Migration (decision D8)

- [ ] 7.1 Add `OCA\OpenCatalogi\Repair\MoveDocumentsoortOutOfSummary` and register it in `appinfo/info.xml` under `<post-migration>` after `InitializeSettings` (REQ-DWP-005). Verify: `tests/Unit/Repair/MoveDocumentsoortOutOfSummaryTest.php` with `::testASummaryThatIsADocumentTypeIsMoved` (fails today: the class does not exist), `::testARealSummaryIsLeftAlone`, `::testASecondRunMovesNothing`, `::testTheStepIsRegisteredPostMigration` (reads `appinfo/info.xml`). gate-98 checks the registration too.
- [ ] 7.2 Run the step once on the dev instance against a publication carrying `summary` `besluit`, and record in the PR body the moved count and the object read back with `documentsoort` set (REQ-DWP-005). Verify: the read-back in the PR body. Set `appstoreenabled=false` before any `occ upgrade` on a mounted clone.

## 9. Docs

- [ ] 9.1 Document the DiWoo fields, the refusal and the release note of the proposal in `docs/`, following the writing skill (no em-dashes, sentence case). Verify: `npm run lint` and a grep for U+2014 on the changed docs returns nothing.

## 10. Verification

- [ ] 10.1 `TMPDIR` set to a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or run with `--no-coverage`; a green suite exits 1 without a coverage driver.
- [ ] 10.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran; NOT APPLICABLE is not a pass. Expect gate-19 to want the `@e2e` reference from 4.4 and gate-98 to see the repair step.
- [ ] 10.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. CI runs the gates on the full tree and the coverage guard needs a test for every added statement.
- [ ] 10.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Rows 2.3, 2.13, 2.23 and 2.25 become `production` only once a store release ships it.
