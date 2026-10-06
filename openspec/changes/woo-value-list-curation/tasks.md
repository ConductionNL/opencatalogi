# Tasks: woo-value-list-curation

Read `openspec/woo-build-rules.md` first. Start once `woo-value-lists-on-the-concept-register` is merged. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`; listener tests construct the REAL `ObjectCreatingEvent` and `ObjectUpdatingEvent`. Check how `openregister/local-changes-to-app-shipped-configuration` (REQ-LCA-001 to 003) keeps an administrator's change to shipped data before shipping the applicability table; if it is not merged, keep the administrator's confirmations in `valueListChoice` only (never in the shipped file) so an upgrade cannot overwrite them.

## 1. Overlay

- [ ] 1.1 Add the `valueListChoice` schema with `slug`, version and admin-only writes, and `ValueListView::entries()` (REQ-WVL-001). Verify: `tests/Unit/Service/Woo/ValueListViewTest.php::testARefreshChangesTheSourceLabelAndKeepsTheLocalOne` (fails today), `::testUnreadableChoicesFallBackToSourceOrderAndSaySo`; `tests/Unit/Listener/ValueListChoiceListenerTest.php::testANormativeFieldIsRefused`, on the REAL event; an `ApplicationRegisterInvariantTest` case.
- [ ] 1.2 Assert `TooiSchemeRefresh` never touches `valueListChoice` (REQ-WVL-001). Verify: `tests/Unit/BackgroundJob/TooiSchemeRefreshTest.php::testTheRefreshWritesNoChoice`.

## 2. Order

- [ ] 2.1 Read the order in `WooCategoryRegistry`, `GET /api/woo/categories` and the publication form pickers; add Move up and Move down to the Woo settings lists (REQ-WVL-002). Verify: `tests/Unit/Service/Woo/WooCategoryRegistryTest.php::testTheAdministratorsOrderWins` and `tests/e2e/value-list-curation.spec.ts` "an administrator puts the most used category first", carrying `@e2e` REQ-WVL-002.

## 3. Explanation

- [ ] 3.1 Add the explanation field to the Woo settings and `GET /api/woo/categories/public` with CORS (REQ-WVL-003). Verify: `tests/Unit/Controller/PublicCategoriesTest.php::testTheExplanationIsServedAndHiddenCategoriesAreNot` (fails today); a route-table and `OpenApiParityTest` case. Link the response shape from the portaliq filter's issue.

## 4. Hide by organisation type

- [ ] 4.1 Write `lib/Settings/woo-category-applicability.json` with, per entry, the article of the Woo that makes the category not apply to that type; leave out any entry you cannot cite (REQ-WVL-004). Verify: `tests/Unit/Settings/WooCategoryApplicabilityTest.php::testEveryEntryCitesItsLegalBasis`. List the cited articles in the PR body for review.
- [ ] 4.2 Add the organisation type setting with the confirm-or-override proposal, the in-use refusal, and the empty valid sitemap index for a hidden category (REQ-WVL-004, REQ-WIC-001). Verify: `tests/Unit/Service/SitemapServiceTest.php::testAHiddenCategoryServesAnEmptyValidIndex` (fails today), `WooCategoryRegistryTest::testAHiddenCategoryKeepsItsSitemapIndexAndRobotsLine`, `ValueListChoiceListenerTest::testHidingACategoryInUseIsRefused`, and `tests/e2e/value-list-curation.spec.ts` "a water board hides the categories it never publishes".

## 5. Activated organisations

- [ ] 5.1 Add activation from the TOOI organisation scheme, linking or creating the OpenRegister organisation, and limit the publisher and responsible-organisation pickers (REQ-WVL-005). Verify: `tests/Unit/Service/Woo/OrganisationActivationTest.php::testActivatingLinksAnExistingOrganisation`, `::testActivatingCreatesOneWithTheTooiIdentifier`, and `tests/e2e/value-list-curation.spec.ts` "only activated organisations are offered".
- [ ] 5.2 Emit only activated publishers in `SitemapService` (REQ-WVL-005). Verify: `SitemapServiceTest::testAPublisherNotActivatedIsOmittedAndReported`.

## 6. Docs

- [ ] 6.1 Document curation for administrators in `docs/` and the strings in `l10n/` (en, nl). Verify: `npm run check:l10n` and a grep for U+2014 on the changed docs.

## 7. Verification

- [ ] 7.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`. The sitemap is central: run the full unit suite once.
- [ ] 7.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] 7.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 7.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Rows 13.12, 13.13, 13.14 and 13.32 become `production` only once a store release ships it; 13.15 once the portal also shows the explanation.
