# Tasks: woo-access-boundary-hardening

Read `openspec/woo-build-rules.md` first. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`. Real classes checked in openregister `development` at 1dc6a46: `OCA\OpenRegister\Event\PermissionsDeclaringEvent::declareVerb(verb:, app:, description:, levels:)`, `OCA\OpenRegister\Event\CustomScopeEvaluatingEvent` (constructed with `schema`, `action`, `userId`, `userGroups`, `object`; `allow()`, `deny()`), `OCA\OpenRegister\Service\Object\PermissionHandler::hasPermission(Schema $schema, string $action, ?string $userId = null, ?string $objectOwner = null, bool $_rbac = true, ?ObjectEntity $object = null): bool`, `ProcessingLogService::logRead(ObjectEntity $object, string $action = 'read', ?string $channel = null, ?string $actor = null)`. Listener tests construct the REAL event classes. This change touches authorization: hydra gates no-admin-idor, semantic-auth and route-auth must stay green.

## 1. Read logging

- [ ] 1.1 Declare `x-openregister-processing` on the five schemas and seed the processing activity, bumping versions (REQ-WAB-001). Verify: `tests/Unit/Settings/WooReadLoggingDeclarationTest.php::testTheFiveSchemasOptIn` and `tests/Unit/Service/WooReadLoggingTest.php::testAReadOfAnAssessmentWritesAnAttributedEntry` (fails today: no opt-in).
- [ ] 1.2 Report the fallback-attributed count in `WooReadinessService` (REQ-WAB-001). Verify: `tests/Unit/Service/WooReadinessServiceTest.php::testFallbackAttributedReadsAreReported`.
- [ ] 1.3 Live: read an assessment on the dev instance and paste the processing log entry from `GET /apps/openregister/api/avg/verwerkingen` in the PR body (REQ-WAB-001). Verify: the pasted entry. (live pass, decision 139)

## 2. Boundary suite

- [ ] 2.1 Extend `tests/e2e/ci-seed.sh` and the PHPUnit fixture with one object of every non-public kind (REQ-WAB-002). Verify: the seed run in CI lists them.
- [ ] 2.2 Add `tests/Unit/Boundary/PublicBoundaryTest.php` reading the route table (REQ-WAB-002). Verify: `::testNoPublicRouteReturnsANonPublicRecord`, `::testEveryNonPublicRouteRefusesAnonymous`, `::testTheRouteListIsReadFromTheRouteTable`. Before relying on it, break one route on purpose locally (return a draft) and confirm the test fails; say so in the PR body.
- [ ] 2.3 Add `tests/e2e/public-boundary.spec.ts` running the same anonymously over HTTP (REQ-WAB-002). Verify: the spec in CI.

## 3. Search indexes

- [ ] 3.1 Extend `RobotsController::index()` and add `NoIndexMiddleware` (registered with `registerMiddleware()`) and the `search_indexing` setting (REQ-WAB-003). Verify: `tests/Unit/Controller/RobotsControllerTest.php::testOfficerPathsAreDisallowed` (fails today), `::testBlockedIndexingDisallowsEverything`, `tests/Unit/Middleware/NoIndexMiddlewareTest.php::testANonPublicResponseCarriesNoindex`, `::testBlockedIndexingMarksEveryResponse`, `::testAPublicResponseIsUntouchedByDefault`.

## 4. Rights per group

- [ ] 4.1 Add the declaring listener and the deciding listener, registered in `Application::register()` (REQ-WAB-004). Verify: `tests/Unit/Listener/CapabilityDeclarationListenerTest.php::testTheEightVerbsAreDeclared` and `tests/Unit/Listener/CapabilityScopeListenerTest.php::testAGrantedGroupIsAllowed`, `::testAnUngrantedUserIsDenied`, `::testAnAdministratorIsAllowed`, `::testAnUnreadableGrantListDenies`, on the REAL events; an `ApplicationRegisterInvariantTest` case for both registrations.
- [ ] 4.2 Add `CapabilityRights::may()` and switch the listed controller methods from `#[AuthorizedAdminSetting]` to `#[NoAdminRequired]` with the check first (REQ-WAB-004). Verify: `tests/Unit/Controller/CapabilityRightsWiringTest.php::testEveryListedMethodChecksItsVerbAndHasNoAdminAttribute` (fails today) and `tests/Unit/Controller/InspectionControllerTest.php::testAUserWithOnlyOpenInspectionMayOpen` and `::testAUserWithoutTheRightGets403`.
- [ ] 4.3 Add the rights table to the admin settings, writing the `catalog` schema's authorization block through OpenRegister (REQ-WAB-004). Verify: `tests/e2e/capability-rights.spec.ts` "a group may open inspection periods and nothing else", carrying `@e2e` REQ-WAB-004.
- [ ] 4.4 Upgrade path: a repair step grants all eight verbs to every group that today has delegated access to the OpenCatalogi admin section, so nobody loses a capability on upgrade. Verify: `tests/Unit/Repair/GrantCapabilitiesToDelegatedAdminsTest.php` and its registration in `appinfo/info.xml`.

## 5. Docs

- [ ] 5.1 Document the rights, the read log and the indexing switch for administrators in `docs/` and the strings in `l10n/` (en, nl). Verify: `npm run check:l10n` and a grep for U+2014 on the changed docs.

## 6. Verification

- [ ] 6.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`. Authorization is central: run the full unit suite once.
- [ ] 6.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran; route-auth, semantic-auth and no-admin-idor must run and pass.
- [ ] 6.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 6.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Rows 12.5, 12.9, 12.27 and 12.32 become `production` only once a store release ships it.
