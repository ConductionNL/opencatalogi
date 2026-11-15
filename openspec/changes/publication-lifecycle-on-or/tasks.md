# Tasks: publication-lifecycle-on-or

Read `openspec/woo-build-rules.md` first. For OpenRegister doubles copy the `environmentAwareDouble()` pattern from `tests/Unit/Service/SitemapServiceTest.php`. Real signatures to mock against, checked in openregister `development`: `OCA\OpenRegister\Service\Lifecycle\TransitionEngine::transition(string $objectId, string $action, array $data = []): ObjectEntity` (throws `RuntimeException` for an action not allowed from the current state, `NotAuthorizedException`, `InvalidTransitionInputException`, `HookStoppedException`), `availableActions(string $objectId): array`, `ObjectService::deleteObject(..., bool $permanent = false)`, `AuditTrailMapper::createAuditTrail(?ObjectEntity $old, ?ObjectEntity $new, ?string $action, ?array $cascadeContext)`. `ObjectEntity` getters are magic.

## 1. Lifecycle declaration

- [ ] 1.1 Rewrite `x-openregister-lifecycle` and the `status` enum of `#publication` in `lib/Settings/publication_register.json`, add `firstReleasedAt` (immutable) and `unlisted`, and bump the schema `version` (REQ-PLC-001, REQ-PLC-006). Verify: `tests/Unit/Settings/PublicationLifecycleDeclarationTest.php::testTheLifecycleDeclaresTheSevenTransitions` (fails today: only `archive` is declared) and `::testTheDeclarationPassesOpenRegistersGraphValidator`, which runs OpenRegister's `LifecycleGraphValidator` and `LifecycleTransitionsValidator` over the shipped declaration when the class exists and is skipped with a message otherwise.
- [ ] 1.2 Add the `publication_review_required` app config (default `true`), a guard on `publishWithoutReview` reading it, and the admin toggle in the publications settings section (REQ-PLC-001). Verify: `tests/Unit/Service/Publication/PublicationLifecycleTest.php::testPublishWithoutReviewIsRefusedWhenReviewIsRequired` and `::testPublishWithoutReviewIsAllowedWhenReviewIsOff`.
- [ ] 1.3 Add `GET /api/publications/lifecycle` returning the transition table read from the shipped schema, not a copy (REQ-PLC-001). Verify: `tests/Unit/Controller/PublicationLifecycleControllerTest.php::testTheTransitionTableIsPublished`, and a route-table test asserting the route exists.
- [ ] 1.4 Through OpenRegister's transition route on the dev instance, run an illegal move and a list-form skip, and paste the two answers in the PR body (REQ-PLC-001). Verify: `PublicationLifecycleTest::testAnIllegalMoveIsRefusedByName`, `::testAListFormEditThatSkipsReviewIsRefused`, `::testAvailableActionsFollowTheState`, plus the pasted live answers. (live pass, decision 139)

## 2. One state

- [ ] 2.1 Extend `PublicationStateService::stateOf()` with the stored states and add a `state` key per row to the publications list endpoint (REQ-PLC-002, REQ-PPW-001). Verify: `tests/Unit/Service/Publication/PublicationStateServiceTest.php::testAStoredDraftWithAPastPublicationDateIsADraft` (fails today: `stateOf` reads only the dates) and `::testEveryStoredStateMapsToOneVisibleState`.
- [ ] 2.2 Show the state as a column and a facet on the publications list and in the detail header, from the server value (REQ-PLC-002). Verify: `tests/e2e/publication-lifecycle.spec.ts` "one state on the list and the page", carrying `@e2e` REQ-PLC-002.

## 3. Public only when published; every publish path moves the lifecycle

- [ ] 3.1 Add `status: "published"` to the public read rule of `#publication` (REQ-PLC-003, RET-001). Verify: `tests/Unit/Settings/PublicationLifecycleReadRuleTest.php::testADraftWithAPastPublicationDateIsNotPublic`, which reads the shipped rule and evaluates it with OpenRegister's condition matcher; it fails today because the rule matches dates only.
- [ ] 3.2 Move `PublicationStateController::publish()`, `EventService::publishObject()`, `BatchPublicationWriter` and `MassPublishObjects.vue` onto `TransitionEngine` (the Vue modal through OpenRegister's transition route); a refused transition writes no date (REQ-PLC-003). Verify: `tests/Unit/Controller/PublicationStateControllerTest.php::testPublishRunsTheTransition`, `::testPublishRefusesAnUnreviewedDraftWhenReviewIsRequired`, `tests/Unit/Service/EventServiceTest.php::testPublishObjectRunsTheTransition`, `tests/Unit/Service/Woo/BatchPublicationWriterTest.php::testABatchCreatesAnApprovedPublicationAndPublishesIt`. Then `git grep -n "'publicationDate'" lib src` and list every remaining writer in the PR body with why it is not a publish path.
- [ ] 3.3 Live: publish an approved publication from its page and see Public. Verify: `tests/e2e/publication-lifecycle.spec.ts` "publish now moves the lifecycle". (live pass, decision 139)

## 5. Ready list

- [ ] 5.1 Add `GET /api/publications/ready` (`#[NoAdminRequired]`, no `#[PublicPage]`), declared before the `{id}` routes, reading through `ObjectService` so the caller's read rights apply (REQ-PLC-005). Verify: `tests/Unit/Controller/ReadyPublicationsControllerTest.php::testOnlyApprovedPublicationsAreListed` (fails today: no route), `::testSinceFiltersOnTheMomentOfApproval`, `::testAnAnonymousCallerIsRefused`; and `tests/Unit/AppInfo/RouteNameUniquenessTest.php` stays green.

## 6. Unlisted

- [ ] 6.1 Read `unlisted` in the query builders of `PublicationQueryService`, `SitemapService`, `DcatService` and `FederationController::publications()`, and refuse it in `PlooiDeliveryService::deliver()` (REQ-PLC-006). Verify: `tests/Unit/Service/UnlistedPublicationTest.php::testAnUnlistedPublicationIsAbsentFromSitemapDcatAndFederation`, `::testAnUnlistedPublicationIsStillReadableById`, `tests/Unit/Service/Publication/PlooiDeliveryServiceTest.php::testAnUnlistedPublicationIsNotDelivered`. Each fails today.
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
