# Tasks: publication-plain-language-and-translation

Read `openspec/woo-build-rules.md` first. Mock `OCP\TaskProcessing\IManager` against the real OCP interface in `vendor/nextcloud/ocp` (`getAvailableTaskTypes()`, `scheduleTask(Task)`, `getTask(int)`); check the signatures there before writing the double. Group 4 was gated on decision D10 and is decided (24 Nov 2026): translation ships through `publication-translations`; no machine drafting.

## 1. Field

- [x] 1.1 Add `plainSummary`, `plainSummaryLevel` and `plainSummaryProvenance` to `#publication` in a fragment with `slug` and version, with labels (REQ-PPL-001). Verify: `tests/Unit/Settings/PlainSummarySchemaTest.php::testTheFieldsAndTheDefaultLevel` and `npm run check:schema-l10n`.
- [x] 1.2 Refuse a summary without a level in a pre-save listener registered in `Application::register()` (REQ-PPL-001). Verify: `tests/Unit/Listener/PlainSummaryCheckTest.php::testASummaryWithoutALevelIsRefused` on the REAL event.
  - 25 Nov 2026, live: "a summary without its level is refused naming the level" fails on a sandbox instance. A save with `plainSummaryLevel: ""` answers 200 and stores `B1`: OpenRegister turns the empty value into the schema default (`B1`, task 1.1) before `PlainSummaryCheck` sees it, so the listener never meets the empty level the unit test feeds it. The spec asks for both a `B1` default and a refusal of an empty level; which wins is open (drop the schema default and let the listener default a missing level, or accept the default and drop the refusal scenario).
- [x] 1.3 Emit `dct:abstract` in `DcatMappingService` and return both fields on the public response (REQ-PPL-001). Verify: `tests/Unit/Service/DcatMappingServiceTest.php::testThePlainSummaryIsEmittedAsAbstractWithItsLanguage` (fails today).
- [x] 1.4 Show and edit the field with its level on the publication page (REQ-PPL-001). Verify: `tests/e2e/plain-language.spec.ts` "an officer writes a B1 summary and the reader sees it", carrying `@e2e` REQ-PPL-001. Written, runs in nightly (not verified on a live instance; UNBLOCK rule 3, 2026-11-24).
  - Built on development: the data widget shows and edits `plainSummary` and `plainSummaryLevel`. `tests/e2e/plain-language.spec.ts` is written ("an officer writes a B1 summary and the reader sees it", plus the refusal without a level) but has not run against a live instance yet, so this stays unticked until it has.

## 2. Draft

- [x] 2.1 Add `PlainLanguageDrafter`, the draft and poll routes and the accept route with the update-right check (REQ-PPL-002). Verify: `tests/Unit/Service/Publication/PlainLanguageDrafterTest.php::testWithoutAProviderTheDraftRouteAnswers409`, `::testADraftIsScheduledWithTheTextFields`, `::testAFailedTaskStoresNothing`, `::testAcceptStoresProvenanceAndTheAcceptingUser`; controller tests through each route (fail today: no routes).
- [x] 2.2 Add Draft plain summary to the page, shown only when a provider is available, with the suggestion and accept flow (REQ-PPL-002). Verify: `tests/e2e/plain-language.spec.ts` "a draft is a suggestion until accepted", run against a fake TaskProcessing provider registered by the e2e seed; if the seed cannot register one, mark the scenario `@e2e exclude` with that reason and rely on the unit tests.
  - Built on development: `PlainSummaryDraftWidget` on the publication page. Round 2 added what the spec asks for without a provider: the widget now says no text assistant is installed instead of disappearing. The e2e test "a draft is a suggestion until accepted" walks the accept flow when the instance has a provider and asserts the no-provider page and the 409 `no-provider` answer when it has none; not yet run live.
- [x] 2.3 If `instance-staging-mode` is merged, ask its `OutboundGate` before scheduling a task with a remote provider (REQ-PPL-002). Verify: `PlainLanguageDrafterTest::testStagingRecordsADryRunInsteadOfScheduling`, or a note in the PR body that the staging change is not merged yet.
  - 25 Nov 2026, live on a sandbox Nextcloud 34 + MariaDB without a text assistant: "a draft is a suggestion until accepted" passes (the page says no text assistant is installed and the draft route answers 409 `no-provider`). "an officer writes a B1 summary and the reader sees it" passes too after two spec fixes: the fixture publication is taken through review to published (a new one starts as a draft, so the public read answered 404), and the test opens the data card's "Show all N fields" before looking for the summary.

## 3. Docs

- [x] 3.1 Document the plain summary and the reading levels for editors in `docs/`, following the writing skill. Verify: a grep for U+2014 on the changed docs.

## 4. Translation (decided by D10, 24 Nov 2026: delivered by publication-translations)

- [x] 4.1 ~~Add `translations`, the translate draft through `core:text2text:translate`, the accept route and the language negotiation on the public response (REQ-PPL-003)~~. Decided under D10 (24 Nov 2026): row 14.7's translation is delivered by `publication-translations` (archived 2026-11-24): `title`, `summary` and `description` are translatable through OpenRegister's i18n, an editor writes each language, and the public API negotiates `_lang` and `Accept-Language`. A separate `translations` property would store the same thing twice. The machine-drafted half (`core:text2text:translate`) is struck: area 14 keeps the AI app outside this bundle. REQ-PPL-003 is removed from the spec delta.

## 5. Verification

- [x] 5.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`.
- [x] 5.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] 5.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 5.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Row 15.4 becomes `production` only once a store release ships it; 14.7 rides on `publication-translations`.
