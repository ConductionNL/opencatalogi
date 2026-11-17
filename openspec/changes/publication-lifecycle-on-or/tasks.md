# Tasks: publication-lifecycle-on-or

Read `openspec/woo-build-rules.md` first. For OpenRegister doubles copy the `environmentAwareDouble()` pattern from `tests/Unit/Service/SitemapServiceTest.php`. Real signatures to mock against, checked in openregister `development`: `OCA\OpenRegister\Service\Lifecycle\TransitionEngine::transition(string $objectId, string $action, array $data = []): ObjectEntity` (throws `RuntimeException` for an action not allowed from the current state, `NotAuthorizedException`, `InvalidTransitionInputException`, `HookStoppedException`), `availableActions(string $objectId): array`, `ObjectService::deleteObject(..., bool $permanent = false)`, `AuditTrailMapper::createAuditTrail(?ObjectEntity $old, ?ObjectEntity $new, ?string $action, ?array $cascadeContext)`. `ObjectEntity` getters are magic.

## 1. Lifecycle declaration

- [x] 1.1 Rewrite `x-openregister-lifecycle` and the `status` enum of `#publication` in `lib/Settings/publication_register.json`, add `firstReleasedAt` (immutable) and `unlisted`, and bump the schema `version` (REQ-PLC-001, REQ-PLC-006). Verify: `tests/Unit/Settings/PublicationLifecycleDeclarationTest.php::testTheLifecycleDeclaresTheSevenTransitions` (fails today: only `archive` is declared) and `::testTheDeclarationPassesOpenRegistersGraphValidator`, which runs OpenRegister's `LifecycleGraphValidator` and `LifecycleTransitionsValidator` over the shipped declaration when the class exists and is skipped with a message otherwise.
  - Done: seven transitions, initial `draft`, `firstReleasedAt` (immutable, stamped by `set-fields` `@now` on both publish moves while empty) and `unlisted`. The validator test runs OpenRegister's `LifecycleAnnotationValidator`, the entry point for a schema annotation (it hands graph mode to `LifecycleGraphValidator`; `LifecycleTransitionsValidator` is for a lifecycle kept as data). The register fragments overwrite the base `version`, so the bump to 0.0.9 is also made in the last fragment that sets it (`woo-publication-translations.json`).
- [x] 1.2 Add the `publication_review_required` app config (default `true`), a guard on `publishWithoutReview` reading it, and the admin toggle in the publications settings section (REQ-PLC-001). Verify: `tests/Unit/Service/Publication/PublicationLifecycleTest.php::testPublishWithoutReviewIsRefusedWhenReviewIsRequired` and `::testPublishWithoutReviewIsAllowedWhenReviewIsOff`.
  - Done: `PublicationReviewPolicy` (true unless the stored text is `false`), `Lifecycle\ReviewNotRequiredGuard` as the transition's `requires`, the option in the publishing options (default true) and a toggle in the publishing options section, strings in all 37 locales.
- [x] 1.3 Add `GET /api/publications/lifecycle` returning the transition table read from the shipped schema, not a copy (REQ-PLC-001). Verify: `tests/Unit/Controller/PublicationLifecycleControllerTest.php::testTheTransitionTableIsPublished`, and a route-table test asserting the route exists.
  - Done: `PublicationLifecycleController::index()` over `PublicationLifecycleTable`, which reads the merged shipped schema through `SettingsService::shippedSchema()`; the route is declared before the catalogue wildcard, and `testTheRouteIsDeclaredBeforeTheCatalogueWildcard` pins it.
- [ ] 1.4 Through OpenRegister's transition route on the dev instance, run an illegal move and a list-form skip, and paste the two answers in the PR body (REQ-PLC-001). Verify: `PublicationLifecycleTest::testAnIllegalMoveIsRefusedByName`, `::testAListFormEditThatSkipsReviewIsRefused`, `::testAvailableActionsFollowTheState`, plus the pasted live answers. (live pass, decision 139)

## 2. One state

- [x] 2.1 Extend `PublicationStateService::stateOf()` with the stored states and add a `state` key per row to the publications list endpoint (REQ-PLC-002, REQ-PPW-001). Verify: `tests/Unit/Service/Publication/PublicationStateServiceTest.php::testAStoredDraftWithAPastPublicationDateIsADraft` (fails today: `stateOf` reads only the dates) and `::testEveryStoredStateMapsToOneVisibleState`.
  - Done: `stateOf()` answers draft, in review and approved as stored, reads the dates only for a stored `published`, and answers draft for an empty or unknown status (fail closed). `PublicationsController` adds `state` to every row for a signed-in caller; an anonymous page is unchanged. Test fixtures that stood for published publications now carry `status: published`, as do the three dated demo publications in `opencatalogi_mock_register.json`; three goldens re-recorded (the stored status in a saved record, and the per-row state).
- [ ] 2.2 Show the state as a column and a facet on the publications list and in the detail header, from the server value (REQ-PLC-002). Verify: `tests/e2e/publication-lifecycle.spec.ts` "one state on the list and the page", carrying `@e2e` REQ-PLC-002.

## 3. Public only when published; every publish path moves the lifecycle

- [x] 3.1 Add `status: "published"` to the public read rule of `#publication` (REQ-PLC-003, RET-001). Verify: `tests/Unit/Settings/PublicationLifecycleReadRuleTest.php::testADraftWithAPastPublicationDateIsNotPublic`, which reads the shipped rule and evaluates it with OpenRegister's condition matcher; it fails today because the rule matches dates only.
  - Done: both public read rules require `status: published`; the test evaluates the shipped rule with OpenRegister's `ConditionMatcher` and fails on development's rule. `WOO536RepairReadRules` still upgrades only the old single-rule shape (also for documents); the backfill step of 7.1 writes the publication rule with the status clause on installs where the import is version-gated.
- [ ] 3.2 Move `PublicationStateController::publish()`, `EventService::publishObject()`, `BatchPublicationWriter` and `MassPublishObjects.vue` onto `TransitionEngine` (the Vue modal through OpenRegister's transition route); a refused transition writes no date (REQ-PLC-003). Verify: `tests/Unit/Controller/PublicationStateControllerTest.php::testPublishRunsTheTransition`, `::testPublishRefusesAnUnreviewedDraftWhenReviewIsRequired`, `tests/Unit/Service/EventServiceTest.php::testPublishObjectRunsTheTransition`, `tests/Unit/Service/Woo/BatchPublicationWriterTest.php::testABatchCreatesAnApprovedPublicationAndPublishesIt`. Then `git grep -n "'publicationDate'" lib src` and list every remaining writer in the PR body with why it is not a publish path.
- [ ] 3.3 Live: publish an approved publication from its page and see Public. Verify: `tests/e2e/publication-lifecycle.spec.ts` "publish now moves the lifecycle". (live pass, decision 139)

## 5. Ready list

- [x] 5.1 Add `GET /api/publications/ready` (`#[NoAdminRequired]`, no `#[PublicPage]`), declared before the `{id}` routes, reading through `ObjectService` so the caller's read rights apply (REQ-PLC-005). Verify: `tests/Unit/Controller/ReadyPublicationsControllerTest.php::testOnlyApprovedPublicationsAreListed` (fails today: no route), `::testSinceFiltersOnTheMomentOfApproval`, `::testAnAnonymousCallerIsRefused`; and `tests/Unit/AppInfo/RouteNameUniquenessTest.php` stays green.
  - Done: `ReadyPublicationsController` over `ReadyPublicationsService`, which reads `status: approved` through the object service with RBAC on and checks every row again. The `approve` transition now stamps `approvedAt` (`set-fields` `@now`), the moment `since` filters on. 401 without a session, 400 for an unreadable `since`.

## 6. Unlisted

- [x] 6.1 Read `unlisted` in the query builders of `PublicationQueryService`, `SitemapService`, `DcatService` and `FederationController::publications()`, and refuse it in `PlooiDeliveryService::deliver()` (REQ-PLC-006). Verify: `tests/Unit/Service/UnlistedPublicationTest.php::testAnUnlistedPublicationIsAbsentFromSitemapDcatAndFederation`, `::testAnUnlistedPublicationIsStillReadableById`, `tests/Unit/Service/Publication/PlooiDeliveryServiceTest.php::testAnUnlistedPublicationIsNotDelivered`. Each fails today.
  - Done: `UnlistedPublications` is read where each surface gathers its rows: the search row loop (beside the RET-006 archived drop), the anonymous catalogue list, the sitemap's `publicationsWithFiles()`, DCAT's `searchCatalogPage()` and the federation's local page; in PHP, not SQL, so a legacy row with a NULL flag stays listed. PLOOI delivery refuses an unlisted publication and records `plooiReason: unlisted`. The by-id read is untouched (`PublicationsControllerTest::testShowStillAnswersAnUnlistedPublication`).
- [ ] 6.2 Add Unlist and List again to the detail page (REQ-PLC-006). Verify: `tests/e2e/publication-lifecycle.spec.ts` "unlisted, still reachable" and "put back".

## 7. Upgrade

- [ ] 7.1 Add `BackfillPublicationLifecycleState`, registered post-migration after `InitializeSettings` (REQ-PLC-007). Verify: `tests/Unit/Repair/BackfillPublicationLifecycleStateTest.php::testALegacyPublicationWithADateBecomesPublished`, `::testALegacyPublicationWithoutADateBecomesADraft`, `::testASecondRunChangesNothing`, `::testTheStepIsRegisteredPostMigration` (reads `appinfo/info.xml`).
- [ ] 7.2 Upgrade the dev instance with `appstoreenabled=false` set first, then count public publications before and after on the public API and paste both numbers in the PR body; they must match (REQ-PLC-007). Verify: the two counts. (live pass, decision 139)

## 8. Coordination

- [ ] 8.1 The open change `publications-unpublished-overview` (0 of 7 tasks) defines "not yet published" by dates. Add a note to its proposal that it reads `stateOf()` once this change merges. Verify: the note in the diff.

## 9. Verification

- [ ] 9.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`. This change touches central publish paths: run the full unit suite once, not a filter.
- [ ] 9.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran. Expect gate-19 to want the `tests/e2e/publication-lifecycle.spec.ts` references and gate-98 to see the repair step.
- [ ] 9.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 9.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Rows 5.9, 5.14, 5.16, 5.17 and 9.21 become `production` only once a store release ships it.
