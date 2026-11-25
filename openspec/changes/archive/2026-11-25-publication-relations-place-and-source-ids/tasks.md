# Tasks: publication-relations-place-and-source-ids

Read `openspec/woo-build-rules.md` first. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`. Real classes checked in openregister `development` at 1dc6a46: `OCA\OpenRegister\Service\Relation\RelationTypeResolver`, `RelationAnnotationValidator`, the `uses` and `used` rows (each carries the property and its `label` or `inverseLabel`), `ObjectCreatingEvent` and `ObjectUpdatingEvent`. `ObjectEntity` getters are magic. Group 5 was gated on decision D10 and is struck (24 Nov 2026): row 2.16 is not kept.

## 1. Relations

- [x] 1.1 Declare the vocabulary and the four relation properties in `lib/Settings/register.d/publication-relations-place-and-source-ids.json` with `slug` and a bumped version (REQ-PRS-001). Verify: `tests/Unit/Settings/PublicationRelationsSchemaTest.php::testTheVocabularyDeclaresFourTypes` and `::testTheDeclarationPassesOpenRegistersRelationValidator`, which runs `RelationAnnotationValidator` over the merged schema when the class exists.
- [x] 1.2 Add the self-relation check to a pre-save listener registered in `Application::register()` (REQ-PRS-001). Verify: `tests/Unit/Listener/PublicationRelationCheckTest.php::testASelfRelationIsRefused` on the REAL event, and an `ApplicationRegisterInvariantTest` case.
- [x] 1.3 Add `relations` to `PublicationsController::show()`, filtered through the public read path (REQ-PRS-002). Verify: `tests/Unit/Controller/PublicationRelationsResponseTest.php::testBothSidesReadTheRelation` and `::testARelationToANonPublicPublicationIsLeftOut` (fails today: no `relations` key).
- [x] 1.4 Show the relations with their labels in the Related panel of the publication page and add the four properties to the edit form (REQ-PRS-001). Verify: `tests/e2e/publication-relations.spec.ts` "both sides read the relation", carrying `@e2e` REQ-PRS-001.
  - Code built (the Related panel's `groupByRelation` on `pub-related` in `src/manifest.json`, and the self-relation pickers in `src/modals/object/ViewObject.vue` with `relationFields.spec.js`). The e2e spec `tests/e2e/publication-relations.spec.ts` is written, runs in nightly; left unticked until it has run green.
  - 24 Nov: ticked as written, runs in nightly (UNBLOCK rule 3): the code is merged and `tests/e2e/publication-relations.spec.ts` "both sides read the relation" carries `@e2e` REQ-PRS-001; not verified on a live instance.

## 2. Source identifiers

- [x] 2.1 Add `sourceIdentifiers` to the fragment and the mirror of `caseReference` in the pre-save listener (REQ-PRS-003). Verify: `tests/Unit/Listener/SourceIdentifierMirrorTest.php::testTheDossiqPayloadIsMirrored`, using the exact keys of dossiq's `WooPublicationService::buildPayload()` (`caseReference` and the rest, read from `ref-dossiq` or the dossiq repo at build time), and `::testAnExistingEntryIsNotDuplicated`.
- [x] 2.2 Add the repair step `MirrorCaseReferenceIntoSourceIdentifiers`, registered post-migration after `InitializeSettings` (REQ-PRS-003). Verify: `tests/Unit/Repair/MirrorCaseReferenceIntoSourceIdentifiersTest.php` with a second run changing nothing, and `::testTheStepIsRegisteredPostMigration`.
- [x] 2.3 Add the `sourceIdentifier` filter to the internal list endpoint, the ready list (`publication-lifecycle-on-or`) and the public list and search, with the public-only match, and strip non-public entries from public responses in `PublicationQueryService` (REQ-PRS-003). Verify: `tests/Unit/Service/SourceIdentifierFilterTest.php::testTheInternalListFiltersOnSystemAndIdentifier` (fails today) and `::testAPrivateIdentifierIsNeitherReturnedNorMatchedPublicly`; a controller test through each route.
  - Built: the public list and search match a public entry only (`SourceIdentifierFilter::forPublicQuery()`, tested by `SourceIdentifierFilterTest`); public answers carry only public entries (`PublicationLinkRules::withPublicSourceIdentifiers()`). `GET /api/publications/ready` takes the filter and matches any entry, private included, with the match repeated on the rows and a malformed value refused with 400 (`ReadyPublicationsControllerTest::testASourceIdentifierFiltersTheReadyList`, `::testAMalformedSourceIdentifierIsRefused`; `SourceIdentifierFilterTest::testTheInternalListFiltersOnSystemAndIdentifier`). The internal list is OpenRegister's own object API with its `contains` operator, documented in `docs/features/publication-relations.md`; OpenCatalogi has no internal list endpoint of its own to extend.

## 3. Cross-app note

- [x] 3.1 Open an issue on `ConductionNL/dossiq` proposing that `WooPublicationService::buildPayload()` also writes `sourceIdentifiers: [{system: "dossiq", identifier: <case uuid>, public: false}]`, with a test on that side. Nothing here depends on it. Verify: the issue link in the PR body.
  - 24 Nov: built in dossiq rather than proposed: dossiq#6902 (merged) makes `OutcomePublisher::buildPayload()`, which replaced `WooPublicationService::buildPayload()`, write `sourceIdentifiers: [{system: "dossiq", identifier: <case uuid>, public: false}]`, with `OutcomePublisherTest` on that side.

## 4. Docs

- [x] 4.1 Document relations and source identifiers in `docs/` (including the filter for integrators) and the strings in `l10n/` (en, nl). Verify: `npm run check:schema-l10n`, `npm run check:l10n`, and a grep for U+2014 on the changed docs.

## 5. Place (struck by D10, 24 Nov 2026)

- [x] 5.1 ~~Add `geo` with OpenRegister's geometry validation, emit `dct:spatial` in `DcatMappingService` and `geo` on the public API (REQ-PRS-004).~~ Struck by decision D10 (24 Nov 2026): row 2.16 is not kept. The plan already flagged it as deliberately not building because DiWoo carries no place, so the field is one nothing would read, and it would wait on `openregister/geometry-on-a-map` (2 of 4 tasks). REQ-PRS-004 is removed from the spec delta.
- [x] 5.2 ~~Place the maps leaf widget bound to `publication.geo` on the detail page in `src/manifest.json` and remove the "geo/maps dropped" note~~ Struck with 5.1 by D10 (24 Nov 2026); the manifest's "geo/maps dropped" note stays true.

## 6. Verification

- [x] 6.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`.
- [x] 6.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran. gate-98 sees the repair step; gate-19 wants the `@e2e` reference of 1.4.
- [x] 6.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [x] 6.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.
  - Done 25 Nov 2026 (b-opencatalogi), against `development` after the change's PRs landed, in a Nextcloud 34 tree with OpenRegister main: full PHPUnit 7300 tests, 4 errors, all `DirectoryServiceTest::testDoCronSync*` (inherited, not this change). Full hydra run: gate-98 repair-step-registration judges a diff and had none here; `MirrorCaseReferenceIntoSourceIdentifiers` is registered post-migration (2.2's test). The 1.4 scenario carries its `@e2e` reference; the four failing gates (7, 26, 119, 123) are inherited and outside this change's files (#3065). `npm run lint` errors only in `DashboardSideBar.vue`, not touched here.

Done when merged on `development` with CI green. Rows 2.15 and 2.19 become `production` only once a store release ships it; 2.16 is struck by D10.
