# Tasks: woo-decision-shows-what-was-withheld

Read `openspec/woo-build-rules.md` first. Start once `publication-detail-for-the-portal`, `woo-value-lists-on-the-concept-register` and `dossiq/woo-refusal-grounds-list` are merged on their `development`. Before writing a double, read on dossiq `development`: `OCA\Dossiq\Woo\WooRefusalGrounds::byCode(string $code): ?array`, its ten keys and `WooRefusalGroundsUnavailable`, and build the double on the real class when it is autoloadable and on that documented signature otherwise. Read `RefusalGrounds` and the `withheld` code of REQ-PDP-004 on opencatalogi `development` at that moment. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`; mappers throw `DoesNotExistException` instead of returning null. Resolve dossiq through the container only when the app `dossiq` is enabled, as `DossiqWooForward` does in `woo-request-intake-hands-over-to-dossiq`.

## 1. Schema

- [ ] 1.1 Add `lib/Settings/register.d/woo-decision-shows-what-was-withheld.json` with the `withheldDocument` schema, its `slug`, `version`, admin-only `authorization`, and attach it to the publication register (REQ-WDW-001). Verify: `tests/Unit/Settings/WithheldDocumentSchemaTest.php::testTheSchemaHasNoPublicReadRule` and `::testTheSchemaDeclaresNoContentProperty` (both fail today: no schema), and an import test asserting the schema arrives with its `slug`.
- [ ] 1.2 Add the Dutch and English labels and descriptions of the new schema and properties (REQ-WDW-001). Verify: `npm run check:schema-l10n` exits 0.

## 2. The record call

- [ ] 2.1 Add `WithheldDocuments::record()` with the replace semantics, the `byCode()` resolution, the stored `{code, article, label}`, and every refusal reason (REQ-WDW-002). Verify: `tests/Unit/Service/Woo/WithheldDocumentsRecordTest.php::testTwoEntriesAreRecordedWithDossiqsLabels` (fails today), `::testExtraKeysAreNotStored`, `::testAnUnknownGroundRefusesOnlyItsEntry`, `::testWithoutDossiqEveryEntryIsRefusedAndTheSnapshotIsNotRead`, `::testAnUnavailableListRefusesEverything`, `::testAMissingPublicationRefusesEverything`, `::testAnEmptyCallRemovesTheStoredEntries`.
- [ ] 2.2 Contract test on this side: a test that fails when the keys `record()` reads from `byCode()` (`code`, `article`, `label`) are not among the ten keys of dossiq's REQ-WRG-007, and when `record()`'s answer keys differ from `{recorded, refused}` with `refused` items `{position, code, reason}` (REQ-WDW-002). Verify: `WithheldDocumentsRecordTest::testTheContractKeysMatchBothSides`.
- [ ] 2.3 Open an issue on `ConductionNL/dossiq` stating the contract of REQ-WDW-002: dossiq calls `record()` after `WooPublicationService::publish()` creates or updates the publication, with one entry per `niet_openbaar` assessment, a `title` only when its assessment marks the title as public, source `dossiq`, and reports a refusal to the handler; dossiq needs its own test that the call is made with those keys. Link it in the PR body and in this change's issue. Verify: the issue link.

## 3. The public read

- [ ] 3.1 Add the stored entries to `withheld` on `publications#show` and `federation#publication`, merged with the REQ-PDP-004 batch entries and ordered by position, behind `showWithheld` (REQ-WDW-003). Verify: `tests/Unit/Service/WithheldOnThePublicReadTest.php::testAnOptedInCatalogueShowsTheWithheldDocumentsOfADossiqDecision` (fails today), `::testWithoutOptInThereIsNoWithheldKeyEvenWithStoredEntries`, `::testTheFederationEndpointCarriesTheSameEntries`, `::testANonPublicPublicationShowsNothing`, each through the controller method, not the service alone.
- [ ] 3.2 Add `groundDetails` to batch entries through `RefusalGrounds`, with the unresolved code answered without a label (REQ-WDW-003). Verify: `WithheldOnThePublicReadTest::testBatchEntriesGainGroundDetailsAndUnknownCodesAreNotGuessed`; the REQ-PDP-004 tests in `tests/Unit/Service/WithheldDocumentsTest.php` stay green.
- [ ] 3.3 Document `withheld` with `groundDetails` in `openapi.json` and in `docs/`, and link that page from the issue of `portaliq/publication-error-reports-and-withheld-notices` (https://github.com/ConductionNL/portaliq/issues/1221), stating that the portal must render `groundDetails` labels and test against these keys (REQ-WDW-003). Verify: `tests/Unit/OpenApiParityTest.php` stays green and a grep for U+2014 on the changed docs returns nothing.
- [ ] 3.4 Live: with dossiq installed on the dev instance and a catalogue with `showWithheld` on, call `record()` for a published Woo decision with two entries, then fetch the publication anonymously and paste the `withheld` answer in the PR body; switch the opt-in off and paste the answer without the key (REQ-WDW-003). Verify: the two pasted answers. Set `appstoreenabled=false` before any `occ upgrade` on a mounted clone.

## 4. Verification

- [ ] 4.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`; a green suite exits 1 without a coverage driver.
- [ ] 4.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran; NOT APPLICABLE is not a pass.
- [ ] 4.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 4.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green and the dossiq issue of task 2.3 is open. Row 6.16 closes in portaliq, and reads `production` only once a store release ships both halves.
