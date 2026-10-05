# Tasks: woo-value-lists-on-the-concept-register

Read `/home/rubenlinde/memcap-work/woo-build/LANE-RULES-BUILD.md` first. Start once `dossiq/woo-refusal-grounds-list`, `openregister/property-code-list-from-concept-scheme` and `diwoo-metadata-on-the-publication` are merged. Read on their `development` before mocking: dossiq `WooRefusalGrounds::list()`, `byCode()`, `WooRefusalGroundsUnavailable` and the snapshot file; OpenRegister `VocabularyImportService::importJsonLd(array $jsonLd): array`, the concept repository behind the vocabulary routes (`Service/Vocabulary/ConceptRepository`), and `x-openregister-concepts`. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`. Use recorded TOOI JSON-LD responses in tests; fetch the real source URLs from the TOOI publication, do not reconstruct them.

## 1. Read from the register

- [ ] 1.1 Switch `TooiVocabularyService` to the vocabulary register, remove `tooi_waardelijsten.json`, and keep every existing caller's test green (REQ-WVC-001, WOO-TOOI-004). Verify: `tests/Unit/Service/TooiVocabularyServiceTest.php::testTheCategoryResolvesFromTheVocabularyRegister` (fails today), `::testAnUnreadableRegisterThrows`, `::testADeprecatedConceptResolvesButIsRefusedForANewValue`; `SitemapServiceTest` stays green.
- [ ] 1.2 Declare `x-openregister-concepts` on `wooCategory`, `soortHandeling`, `documentsoort` and `language`, bumping versions (REQ-WVC-001). Verify: `tests/Unit/Settings/ConceptPropertyDeclarationTest.php::testListPropertiesDeclareTheirScheme`, and an import test asserting the annotation survives (an unknown `x-openregister-*` key is dropped silently on older OpenRegister).

## 2. Refresh

- [ ] 2.1 Add `TooiSchemeRefresh`, registered in `appinfo/info.xml`, with the source config and the mass-deprecation guard (REQ-WVC-002). Verify: `tests/Unit/BackgroundJob/TooiSchemeRefreshTest.php::testANewConceptFromTheSourceIsImported` (fails today), `::testAMassDeprecationIsRefused`, `::testAFailedDownloadKeepsTheScheme`, `::testTheJobIsRegistered`.
- [ ] 2.2 Live: run the job once on the dev instance and paste the per-scheme counts in the PR body (REQ-WVC-002). Verify: the pasted counts.

## 3. Refusal grounds (D3, D12)

- [ ] 3.1 Remove `WooService::WEIGERINGSGRONDEN`; add `RefusalGrounds::list()` with the dossiq path and the vendored snapshot; validate in `updateAssessment()` and offer the list in the assessment form (REQ-WVC-003). Verify: `tests/Unit/Service/Woo/RefusalGroundsTest.php::testWithDossiqTheListIsDossiqs`, `::testAnUnreadableDossiqRefusesTheSaveAndDoesNotUseTheSnapshot`, `::testWithoutDossiqTheSnapshotIsUsedForRedactionOnly`, `::testTheSnapshotIsNeverOfferedToARequestPath`; `git grep -n WEIGERINGSGRONDEN lib` returns nothing.
- [ ] 3.2 Vendor dossiq's snapshot into `lib/Settings/woo-refusal-grounds.snapshot.json` with its version, and add a test that fails when its version is older than the one dossiq ships, when dossiq is checked out beside the clone (REQ-WVC-003). Verify: `RefusalGroundsTest::testTheVendoredSnapshotMatchesDossiqsShape`.
- [ ] 3.3 Add the repair step mapping stored codes, registered post-migration (REQ-WVC-003). Verify: `tests/Unit/Repair/MapStoredRefusalGroundsTest.php::testAKnownCodeIsMapped`, `::testAnUnmappableCodeIsFlaggedAndListed`, `::testASecondRunChangesNothing`, `::testTheStepIsRegisteredPostMigration`.
- [ ] 3.4 Say in the Woo settings when the snapshot is in use (REQ-WVC-003). Verify: `tests/e2e/value-lists.spec.ts` "without dossiq, grounds can be picked for redaction and not edited", on a CI instance without dossiq, carrying `@e2e` REQ-WVC-003.

## 4. One page

- [ ] 4.1 Add `GET /api/value-lists` with CORS and the public page `/value-lists` (REQ-WVC-004). Verify: `tests/Unit/Controller/ValueListsControllerTest.php::testEveryUsedSchemeIsListedWithUris` (fails today) and `tests/e2e/value-lists.spec.ts` "an integrator copies a URI", carrying `@e2e` REQ-WVC-004; `OpenApiParityTest` stays green.

## 5. Docs

- [ ] 5.1 Document the lists, their sources and the refresh for administrators and integrators in `docs/`, and the strings in `l10n/` (en, nl). Verify: `npm run check:l10n` and a grep for U+2014 on the changed docs.

## 6. Verification

- [ ] 6.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`. The vocabulary feeds the sitemap: run the full unit suite once.
- [ ] 6.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] 6.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 6.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Rows 13.16 and 13.17 become `production` only once a store release ships it.
