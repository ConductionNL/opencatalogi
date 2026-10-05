# Tasks: publication-plain-language-and-translation

Read `/home/rubenlinde/memcap-work/woo-build/LANE-RULES-BUILD.md` first. Mock `OCP\TaskProcessing\IManager` against the real OCP interface in `vendor/nextcloud/ocp` (`getAvailableTaskTypes()`, `scheduleTask(Task)`, `getTask(int)`); check the signatures there before writing the double. Group 4 is gated on decision D10: skip it unless the PR author has Ruben's written keep of row 14.7, and say in the PR body that it was skipped.

## 1. Field

- [ ] 1.1 Add `plainSummary`, `plainSummaryLevel` and `plainSummaryProvenance` to `#publication` in a fragment with `slug` and version, with labels (REQ-PPL-001). Verify: `tests/Unit/Settings/PlainSummarySchemaTest.php::testTheFieldsAndTheDefaultLevel` and `npm run check:schema-l10n`.
- [ ] 1.2 Refuse a summary without a level in a pre-save listener registered in `Application::register()` (REQ-PPL-001). Verify: `tests/Unit/Listener/PlainSummaryCheckTest.php::testASummaryWithoutALevelIsRefused` on the REAL event.
- [ ] 1.3 Emit `dct:abstract` in `DcatMappingService` and return both fields on the public response (REQ-PPL-001). Verify: `tests/Unit/Service/DcatMappingServiceTest.php::testThePlainSummaryIsEmittedAsAbstractWithItsLanguage` (fails today).
- [ ] 1.4 Show and edit the field with its level on the publication page (REQ-PPL-001). Verify: `tests/e2e/plain-language.spec.ts` "an officer writes a B1 summary and the reader sees it", carrying `@e2e` REQ-PPL-001.

## 2. Draft

- [ ] 2.1 Add `PlainLanguageDrafter`, the draft and poll routes and the accept route with the update-right check (REQ-PPL-002). Verify: `tests/Unit/Service/Publication/PlainLanguageDrafterTest.php::testWithoutAProviderTheDraftRouteAnswers409`, `::testADraftIsScheduledWithTheTextFields`, `::testAFailedTaskStoresNothing`, `::testAcceptStoresProvenanceAndTheAcceptingUser`; controller tests through each route (fail today: no routes).
- [ ] 2.2 Add Draft plain summary to the page, shown only when a provider is available, with the suggestion and accept flow (REQ-PPL-002). Verify: `tests/e2e/plain-language.spec.ts` "a draft is a suggestion until accepted", run against a fake TaskProcessing provider registered by the e2e seed; if the seed cannot register one, mark the scenario `@e2e exclude` with that reason and rely on the unit tests.
- [ ] 2.3 If `instance-staging-mode` is merged, ask its `OutboundGate` before scheduling a task with a remote provider (REQ-PPL-002). Verify: `PlainLanguageDrafterTest::testStagingRecordsADryRunInsteadOfScheduling`, or a note in the PR body that the staging change is not merged yet.

## 3. Docs

- [ ] 3.1 Document the plain summary and the reading levels for editors in `docs/`, following the writing skill. Verify: a grep for U+2014 on the changed docs.

## 4. Translation (gated on D10; skip unless row 14.7 is kept)

- [ ] 4.1 Add `translations`, the translate draft through `core:text2text:translate`, the accept route and the language negotiation on the public response (REQ-PPL-003). Verify: `tests/Unit/Controller/PublicationTranslationTest.php::testAReaderAsksForEnglish` and `::testAnUnknownLanguageAnswersTheOriginal`.

## 5. Verification

- [ ] 5.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`.
- [ ] 5.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] 5.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 5.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Row 15.4 becomes `production` only once a store release ships it; 14.7 only if D10 keeps it and group 4 ships.
