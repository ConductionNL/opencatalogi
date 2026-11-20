# Tasks: publication-withdrawal-aftercare

Read `openspec/woo-build-rules.md` first. Start once `publication-lifecycle-on-or` is merged; check that the OpenRegister amendment of `object-archive-state` (file writes honour the frozen marker) is merged before task 3.3. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`. Real signatures checked in openregister `development`: `ArchiveHandler::freeze(string $identifier, ?string $reason = null, ?string $state = null, ?string $register = null, ?string $schema = null): array` (throws `ArchiveNotOfferedException` without `x-openregister-archive.enabled`), `ObjectStateWriteException::frozen(ObjectEntity): self`, `ObjectEntity::isFrozen()` and `getFrozen()`, `FileService::copyFile(ObjectEntity $sourceObject, int $fileId, ObjectEntity $targetObject): File`, `ObjectUpdatingEvent::getOldObject()`. `ObjectEntity` getters are magic.

## 1. Earlier versions

- [ ] 1.1 Add the `publicationVersion` schema fragment with `slug`, bumped `version`, and no public read rule (REQ-PWA-001). Verify: `tests/Unit/Settings/PublicationVersionSchemaTest.php::testTheFragmentHasASlugAndNoPublicReadRule`.
- [ ] 1.2 Add `PublicationVersionService::snapshot()` and the `ObjectUpdatingEvent` listener, registered in `Application::register()` (REQ-PWA-001). Verify: `tests/Unit/Service/Publication/PublicationVersionServiceTest.php::testTheSnapshotHoldsOnlyTheFieldsThePublicReadReturned`, `::testANonPublicPublicationIsNotSnapshotted`; `tests/Unit/Listener/PublicationVersionListenerTest.php` on the REAL event with `::testAFailedSnapshotRefusesTheUpdate`; and an `ApplicationRegisterInvariantTest` case.
- [ ] 1.3 Add the public `versions` and `versions/{version}` routes with the public-read check (REQ-PWA-001). Verify: `tests/Unit/Controller/PublicationVersionControllerTest.php::testACorrectionKeepsTheEarlierVersionAtItsOwnUrl` (fails today: no route), `::testAVersionOfAPublicationThatIsNoLongerPublicIs404`, and a route-table test. Add the CORS preflight routes the other public routes carry.

## 2. Tombstone

- [ ] 2.1 Add `publicReason` and `tombstoneShowsTitle` to `#depublication` with a bumped version, and ask for them in `WithdrawPublicationDialog.vue` (REQ-PWA-002). Verify: `tests/e2e/publication-withdrawal-aftercare.spec.ts` "the officer chooses what the tombstone says", carrying `@e2e` REQ-PWA-002.
- [ ] 2.2 In `PublicationsController::show()`, on a miss, answer 410 with the tombstone when a depublication or an archived, once-released publication exists (REQ-PWA-002). Verify: `tests/Unit/Controller/PublicationTombstoneTest.php::testAWithdrawnPublicationAnswers410WithItsTombstone` (fails today: 404), `::testADraftThatWasNeverPublicStays404`, `::testTheTombstoneCarriesNoTitleUnlessChosen`.
- [ ] 2.3 Check the public surfaces still exclude it: `tests/Unit/Service/SitemapServiceTest.php::testAWithdrawnPublicationIsNotInTheSitemap` stays green, and add one federation case if none exists (RET-006). Verify: those tests.

## 3. Freeze

- [ ] 3.1 Declare `x-openregister-archive: {enabled: true}` on the publication schema and bump its version (REQ-PWA-003). Verify: `tests/Unit/Settings/PublicationArchiveDeclarationTest.php::testThePublicationSchemaOffersArchiving`.
- [ ] 3.2 `DepublicationService::depublish()` freezes with the internal reason and reports `frozen` (REQ-PWA-003). Verify: `tests/Unit/Service/Publication/DepublicationServiceTest.php::testWithdrawingFreezesThePublicationWithTheReason` (fails today: no freeze call) and `::testAFailedFreezeKeepsTheWithdrawalAndSaysSo`; plus a controller test through `POST /api/publications/{id}/withdraw` asserting the freeze ran, so the call has a caller.
- [ ] 3.3 Check the frozen marker in every OpenCatalogi document write path; list them with `git grep -n "createShareLink\|addFile\|saveFile\|withdrawFile" lib` and name each in the PR body (REQ-PWA-003). Verify: `tests/Unit/Service/Publication/FrozenPublicationDocumentWriteTest.php::testAnUploadToAFrozenPublicationIsRefused` and one case per path found.
- [ ] 3.4 Show the frozen marker on the publication page and hide edit, upload and file actions while frozen (REQ-PWA-003). Verify: `tests/e2e/publication-withdrawal-aftercare.spec.ts` "the page says why".
- [ ] 3.5 Live: withdraw a publication on the dev instance, try a title edit and an upload through OpenRegister's file endpoint, and paste both refusals in the PR body (REQ-PWA-003). Verify: the pasted refusals. (live pass, decision 139)

## 4. Docs

- [ ] 4.1 Document versions, the tombstone and the freeze for editors in `docs/` and the strings in `l10n/` (en, nl). Verify: `npm run check:l10n`, `npm run check:schema-l10n`, and a grep for U+2014 on the changed docs.

## 5. Verification

- [ ] 5.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`. The version listener sits on every publication write: run the full unit suite once.
- [ ] 5.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran. gate-19 wants the `@e2e` references of 2.1 and 3.4.
- [ ] 5.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 5.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Rows 5.4, 5.5 and 5.18 become `production` only once a store release ships it.
