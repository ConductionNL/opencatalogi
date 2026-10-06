# Tasks: api-says-what-it-filtered

Read `openspec/woo-build-rules.md` first. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`. Do not loosen `evaluateAsAnonymous()` or `CallerScope::strip()` while instrumenting them; the WOO-551 and WOO-581 comments explain why they exist. Group 4 is gated on decision D10: skip it unless the PR author has Ruben's written keep of row 13.26, and say in the PR body that it was skipped.

## 1. Collector

- [ ] 1.1 Add `AppliedFilters` as a request-scoped service registered in `Application::register()` (REQ-ASF-002). Verify: `tests/Unit/Service/AppliedFiltersTest.php::testRecordedFiltersAreReturnedOnce` and `::testANameOutsideTheListIsRefused`.
- [ ] 1.2 Record at each filter site: catalogue scope, the anonymous evaluation, the RET-006 drop in `PublicationQueryService`, and `CallerScope::strip()` returning the removed keys (REQ-ASF-001, REQ-ASF-002). Verify: `tests/Unit/Service/CallerScopeTest.php::testStripReportsTheRemovedKeys` and `PublicationQueryServiceTest::testTheArchivedDropIsRecorded`.

## 2. Responses

- [ ] 2.1 Add `appliedFilters` to every list response of `PublicationsController`, `SearchController`, `ThemesController` and `FederationController` (REQ-ASF-001). Verify: `tests/Unit/Controller/AppliedFiltersResponseTest.php::testTheCatalogueListReportsItsScope` (fails today), `::testArchivedRowsDroppedAreReported`, `::testAFilterThatDidNotApplyIsNotListed`, `::testAStrippedCallerScopeIsReported`, `::testAFailingCollectorReportsUnknown`, each driven through the controller method the route names.
- [ ] 2.2 List every list route in `appinfo/routes.php` in a test and assert each one's controller method's response carries the key, so a new list route cannot skip it (REQ-ASF-001). Verify: `AppliedFiltersResponseTest::testEveryPublicListRouteCarriesAppliedFilters`.

## 3. OpenAPI

- [ ] 3.1 Add the `AppliedFilters` component and reference it from every list response in `openapi.json` (REQ-ASF-004). Verify: `tests/Unit/OpenApiParityTest.php::testEveryListResponseDocumentsAppliedFilters` (fails today).
- [ ] 3.2 Document the names and what each means in `docs/` for integrators. Verify: a grep for U+2014 on the doc returns nothing.

## 4. Integrator-only marker (gated on D10; skip unless row 13.26 is kept)

- [ ] 4.1 Mark integrator-only parameters in `openapi.json` and add the drift test (REQ-ASF-003). Verify: `tests/Unit/OpenApiIntegratorOnlyDriftTest.php::testAnUnmarkedIntegratorOnlyParameterFails`.

## 5. Verification

- [ ] 5.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`. Public search is central: run the full unit suite once.
- [ ] 5.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] 5.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 5.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Row 13.27 becomes `production` only once a store release ships it; 13.26 only if D10 keeps it and group 4 ships.
