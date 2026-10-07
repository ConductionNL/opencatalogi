# Tasks: woo-metadata-suggestions

## 1. Schema

- [ ] 1.1 Add `metadataSuggestion` with its lifecycle and pending-count aggregation in `lib/Settings/register.d/woo-metadata-suggestions.json`, plus the seed suggestion (REQ-WMS-001). Verify: a clean `occ app:enable` imports the schema; `tests/Unit/Settings/RegisterFragmentTest.php` reads the fragment.

## 2. Rules floor

- [ ] 2.1 Add `WooMetadataSuggestionService::suggestFromRules()` for category, organisation and handling type, checked against `TooiVocabularyService` (REQ-WMS-001). Verify: `tests/Unit/Service/WooMetadataSuggestionServiceTest.php`, one case per field and one for an unresolvable value.
- [ ] 2.2 Queue the rules floor when a publication is created (REQ-WMS-001). Verify: listener test on a real `ObjectCreatedEvent`.

## 3. Hermiq

- [ ] 3.1 Add `HermiqMetadataClient` and the `source=hermiq` path of `POST /api/publications/{id}/metadata-suggestions`, answering 409 without Hermiq (REQ-WMS-002). Verify: controller test with and without the client.

## 4. A person decides

- [ ] 4.1 Add accept and reject endpoints with the publication's update right checked (REQ-WMS-003). Verify: `tests/Unit/Controller/MetadataSuggestionControllerTest.php`, including a user without update rights.
- [ ] 4.2 Add the `metadata-suggestions` widget and place it on `PublicationDetail` in `src/manifest.json` (REQ-WMS-003). Verify: `tests/e2e/woo-metadata-suggestions.spec.ts` accepts one suggestion and sees the field filled.

## 5. Docs and strings

- [ ] 5.1 Document the suggestions for editors in `docs/` and add the strings to `l10n/` in English and Dutch. Verify: `npm run lint` and the l10n check.

## 6. Amendment 2026-10-05: one lawful value fills itself (row 13.19), and two gated rows

Groups 1 to 5 above are unchanged. Read `openspec/woo-build-rules.md` first; for OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php` and check OpenRegister's organisation membership lookup (`OrganisationService`) before mocking it. Items 6.4 and 6.5 are gated on decision D10: skip them unless the PR author has Ruben's written keep of rows 14.1 and 14.2, and say so in the PR body.

- [ ] 6.1 Add `SingleLawfulValue::fill()` and call it from a pre-save listener on `ObjectCreatingEvent` registered in `Application::register()`; add `filledByRule` to `#publication` (REQ-WMS-004). Verify: `tests/Unit/Service/Woo/SingleLawfulValueTest.php::testOneOrganisationAndOneCategoryAreFilled` (fails today), `::testTwoLawfulValuesFillNothing`, `::testAnUncomputableSetFillsNothing`, `::testAHermiqSuggestionIsNeverAutoFilled`, `::testAFieldAlreadySetIsLeftAlone`; a listener test on the REAL event and an `ApplicationRegisterInvariantTest` case.
- [ ] 6.2 Label a field filled by rule on the publication page (REQ-WMS-004). Verify: `tests/e2e/woo-metadata-suggestions.spec.ts` "an officer of one organisation does not pick it", carrying `@e2e` REQ-WMS-004.
- [ ] 6.3 Verification: `TMPDIR` a sibling directory outside the clone; PHPUnit by the `Tests:` line or `--no-coverage`; `run-hydra-gates.sh --base origin/development` counting the gates that ran; once before push `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`; one PR with `--base development`, merge development in, never rebase, no `Co-Authored-By`.
- [ ] 6.4 Gated (14.1): queue the Hermiq path on attach, once per file (REQ-WMS-005). Verify: `tests/Unit/Service/WooMetadataSuggestionServiceTest.php::testAnUploadQueuesHermiqOncePerFile`.
- [ ] 6.5 Gated (14.2): the B1 summary suggestion with its AI label (REQ-WMS-006). Verify: `WooMetadataSuggestionServiceTest::testTheSummarySuggestionIsLabelledAndNotStoredUntilAccepted`.

Done when merged on `development` with CI green. Row 13.19 becomes `production` only once a store release ships it; 14.1 and 14.2 only if D10 keeps them and 6.4 and 6.5 ship.
