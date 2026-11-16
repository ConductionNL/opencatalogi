# Tasks: woo-metadata-suggestions

## 1. Schema

- [x] 1.1 Add `metadataSuggestion` with its lifecycle and pending-count aggregation in `lib/Settings/register.d/woo-metadata-suggestions.json`, plus the seed suggestion (REQ-WMS-001). Verify: a clean `occ app:enable` imports the schema; `tests/Unit/Settings/RegisterFragmentTest.php` reads the fragment.

## 2. Rules floor

- [x] 2.1 Add `WooMetadataSuggestionService::suggestFromRules()` for category, organisation and handling type, checked against `TooiVocabularyService` (REQ-WMS-001). Verify: `tests/Unit/Service/WooMetadataSuggestionServiceTest.php`, one case per field and one for an unresolvable value.
- [x] 2.2 Queue the rules floor when a publication is created (REQ-WMS-001). Verify: listener test on a real `ObjectCreatedEvent`.
  > 15 Nov: `MetadataSuggestionListener` (on OpenRegister's `ObjectCreatedEvent`, registered in `ListenerRegistrar`) queues `SuggestPublicationMetadata` for a new object in the publication register, skipping suggestions and catalogues. The job reads the publication, its schema slug, the organisation of the catalogue listing its schema and the suggestions it already has, and stores each proposal as a pending `metadataSuggestion` through `MetadataSuggestionStore` (`metadata_suggestion_schema`, set by the settings import). OpenRegister records no origin on an object, so the handling-type rule has no input from this path yet. Tests: `MetadataSuggestionListenerTest` (3, real event), `SuggestPublicationMetadataTest` (2), `MetadataSuggestionStoreTest` (2).

## 3. Hermiq

- [x] 3.1 Add `HermiqMetadataClient` and the `source=hermiq` path of `POST /api/publications/{id}/metadata-suggestions`, answering 409 without Hermiq (REQ-WMS-002). Verify: controller test with and without the client.
  > 16 Nov: `HermiqMetadataClient` asks Hermiq's `FormFillEngineInterface` (resolved by name through the container, so Hermiq stays optional) for the value-list fields the publication lacks (`WooMetadataSuggestionService::hermiqFields()`: category and handling type, with their allowed values as options), from the text OpenRegister extracted from the publication's files (`PublicationDocumentText`, over OpenRegister's object file reader and text chunks). `fromHermiq()` checks every value against the same value lists, drops fields already set and values already suggested, and stores the rest as pending `hermiq` suggestions; nothing existing is changed. `POST .../metadata-suggestions` with `source=hermiq` needs the update right on the publication and answers 409 `hermiq-unavailable` without Hermiq; `GET` on the same path lists the suggestions with `hermiq` and `canDecide` for the widget. Free-text fields (summary, title) are not asked for: the B1 summary is gated row 14.2 (6.5). Tests: `MetadataSuggestionControllerTest`, `HermiqMetadataClientTest`, `PublicationDocumentTextTest`, `WooMetadataSuggestionServiceTest` (Hermiq cases).

## 4. A person decides

- [x] 4.1 Add accept and reject endpoints with the publication's update right checked (REQ-WMS-003). Verify: `tests/Unit/Controller/MetadataSuggestionControllerTest.php`, including a user without update rights.
  > 16 Nov: `POST /api/metadata-suggestions/{id}/accept` and `/reject` (`MetadataSuggestionController` over `MetadataSuggestionDecisions`). The publication is read with RBAC on (unreadable answers 404) and the update right is asked of OpenRegister through `PublicationRights::mayUpdate()` (fail closed, 403) before anything is written; a decided suggestion answers 409. Accept saves the value on the publication with RBAC on, then marks the suggestion `accepted` with `decidedBy` and `decidedAt`; a refused write leaves both unchanged. Reject only marks the suggestion. `MetadataSuggestionStore` gained `find()` and `decide()` and now saves through OpenRegister main's `ObjectSaveOptions`.
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
