# Tasks: publication-relations-place-and-source-ids

Read `/home/rubenlinde/memcap-work/woo-build/LANE-RULES-BUILD.md` first. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`. Real classes checked in openregister `development` at 1dc6a46: `OCA\OpenRegister\Service\Relation\RelationTypeResolver`, `RelationAnnotationValidator`, the `uses` and `used` rows (each carries the property and its `label` or `inverseLabel`), `ObjectCreatingEvent` and `ObjectUpdatingEvent`. `ObjectEntity` getters are magic. Group 5 is gated on decision D10: skip it unless the PR author has Ruben's written keep of row 2.16, and say in the PR body that it was skipped.

## 1. Relations

- [ ] 1.1 Declare the vocabulary and the four relation properties in `lib/Settings/register.d/publication-relations-place-and-source-ids.json` with `slug` and a bumped version (REQ-PRS-001). Verify: `tests/Unit/Settings/PublicationRelationsSchemaTest.php::testTheVocabularyDeclaresFourTypes` and `::testTheDeclarationPassesOpenRegistersRelationValidator`, which runs `RelationAnnotationValidator` over the merged schema when the class exists.
- [ ] 1.2 Add the self-relation check to a pre-save listener registered in `Application::register()` (REQ-PRS-001). Verify: `tests/Unit/Listener/PublicationRelationCheckTest.php::testASelfRelationIsRefused` on the REAL event, and an `ApplicationRegisterInvariantTest` case.
- [ ] 1.3 Add `relations` to `PublicationsController::show()`, filtered through the public read path (REQ-PRS-002). Verify: `tests/Unit/Controller/PublicationRelationsResponseTest.php::testBothSidesReadTheRelation` and `::testARelationToANonPublicPublicationIsLeftOut` (fails today: no `relations` key).
- [ ] 1.4 Show the relations with their labels in the Related panel of the publication page and add the four properties to the edit form (REQ-PRS-001). Verify: `tests/e2e/publication-relations.spec.ts` "both sides read the relation", carrying `@e2e` REQ-PRS-001.

## 2. Source identifiers

- [ ] 2.1 Add `sourceIdentifiers` to the fragment and the mirror of `caseReference` in the pre-save listener (REQ-PRS-003). Verify: `tests/Unit/Listener/SourceIdentifierMirrorTest.php::testTheDossiqPayloadIsMirrored`, using the exact keys of dossiq's `WooPublicationService::buildPayload()` (`caseReference` and the rest, read from `ref-dossiq` or the dossiq repo at build time), and `::testAnExistingEntryIsNotDuplicated`.
- [ ] 2.2 Add the repair step `MirrorCaseReferenceIntoSourceIdentifiers`, registered post-migration after `InitializeSettings` (REQ-PRS-003). Verify: `tests/Unit/Repair/MirrorCaseReferenceIntoSourceIdentifiersTest.php` with a second run changing nothing, and `::testTheStepIsRegisteredPostMigration`.
- [ ] 2.3 Add the `sourceIdentifier` filter to the internal list endpoint, the ready list (`publication-lifecycle-on-or`) and the public list and search, with the public-only match, and strip non-public entries from public responses in `PublicationQueryService` (REQ-PRS-003). Verify: `tests/Unit/Service/SourceIdentifierFilterTest.php::testTheInternalListFiltersOnSystemAndIdentifier` (fails today) and `::testAPrivateIdentifierIsNeitherReturnedNorMatchedPublicly`; a controller test through each route.

## 3. Cross-app note

- [ ] 3.1 Open an issue on `ConductionNL/dossiq` proposing that `WooPublicationService::buildPayload()` also writes `sourceIdentifiers: [{system: "dossiq", identifier: <case uuid>, public: false}]`, with a test on that side. Nothing here depends on it. Verify: the issue link in the PR body.

## 4. Docs

- [ ] 4.1 Document relations and source identifiers in `docs/` (including the filter for integrators) and the strings in `l10n/` (en, nl). Verify: `npm run check:schema-l10n`, `npm run check:l10n`, and a grep for U+2014 on the changed docs.

## 5. Place (gated on D10; skip unless row 2.16 is kept)

- [ ] 5.1 Add `geo` with OpenRegister's geometry validation, emit `dct:spatial` in `DcatMappingService` and `geo` on the public API (REQ-PRS-004). Verify: `tests/Unit/Service/DcatMappingServiceTest.php::testAPublicationWithGeoEmitsDctSpatial`.
- [ ] 5.2 Place the maps leaf widget bound to `publication.geo` on the detail page in `src/manifest.json` and remove the "geo/maps dropped" note (REQ-PRS-004, PUB-MAP-001). Verify: `tests/e2e/publication-relations.spec.ts` "a publication with a place" and `npm run check:manifest`.

## 6. Verification

- [ ] 6.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`.
- [ ] 6.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran. gate-98 sees the repair step; gate-19 wants the `@e2e` reference of 1.4.
- [ ] 6.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 6.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Rows 2.15 and 2.19 become `production` only once a store release ships it; 2.16 only if D10 keeps it and group 5 ships.
