# Tasks: publication-schedule-guards

Read `/home/rubenlinde/memcap-work/woo-build/LANE-RULES-BUILD.md` first. Start only once `publication-lifecycle-on-or` is merged on `development`; the guard attaches to its transitions. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`. Real classes checked in openregister `development`: `ObjectUpdatingEvent` (`getNewObject()`, `getOldObject()`, `setErrors()`, `stopPropagation()`), `ScheduledFilterParser`, `ScheduledFilterEvaluator`, `AuditTrailMapper::createAuditTrail(?ObjectEntity $old, ?ObjectEntity $new, ?string $action, ?array $cascadeContext)`. `ObjectEntity` getters are magic.

## 1. Embargo guard

- [ ] 1.1 Add `EmbargoGuard::check(array $old, array $new, IUser $caller): ?array` and a listener on `ObjectUpdatingEvent` registered in `Application::register()` (REQ-PSG-001). Verify: `tests/Unit/Service/Publication/EmbargoGuardTest.php::testAnUnresolvableCallerIsRefused`, `::testClearingTheDateIsAllowed`; `tests/Unit/Listener/EmbargoGuardListenerTest.php` on the REAL event with `::testAnEarlierDateThroughTheObjectApiIsRefused` (fails today: nothing guards the write) and `::testALaterDateIsAllowed`; and an `ApplicationRegisterInvariantTest` case for the registration.
- [ ] 1.2 `PublicationStateController::publish()` checks the guard first and answers 423 `embargo-holds` (REQ-PSG-001). Verify: `tests/Unit/Controller/PublicationStateControllerTest.php::testPublishNowIsRefusedUnderEmbargo` (fails today: a scheduled publication is published at once).
- [ ] 1.3 Add the guard to the `publish` and `publishWithoutReview` transitions as a lifecycle guard (OpenRegister `LifecycleGuardRegistry`) or, if the registry does not accept an app guard, rely on the listener and say so in the PR body (REQ-PSG-001). Verify: `tests/Unit/Service/Publication/PublicationLifecycleTest.php::testThePublishTransitionIsRefusedUnderEmbargo`.

## 2. Lift embargo

- [ ] 2.1 Add `POST /api/publications/{id}/lift-embargo` (`#[NoAdminRequired]`, the group check in the method body), the `publication_embargo_lift_groups` setting and its admin field (REQ-PSG-002). Verify: `tests/Unit/Controller/EmbargoLiftControllerTest.php::testALiftByAUserOutsideTheGroupsIsRefused`, `::testALiftWithoutAReasonIsRefused`, `::testALiftPublishesAndWritesOneAuditEntry`, and a route-table test.
- [ ] 2.2 Add Lift embargo to the publication page in its own dialog under `src/dialogs/publication/`, shown only with the right; hide Publish now for a scheduled publication without it (REQ-PSG-002, REQ-PPW-002). Verify: `tests/e2e/publication-schedule-guards.spec.ts` "a lift is logged" and "a scheduled publication without the lift right", carrying `@e2e` references.

## 3. Automatic depublication

- [ ] 3.1 Show the marker and the line in `PublicationList.vue` and `PublicationVisibilityWidget.vue`, and return the `automatic-depublication` warning from the save path (REQ-PSG-003). Verify: `tests/e2e/publication-schedule-guards.spec.ts` "automatic depublication is shown" and "the officer is warned at save".
- [ ] 3.2 Declare `depublication-due-soon` in the publication schema's `x-openregister-notifications` and bump the schema version (REQ-PSG-003). Verify: `tests/Unit/Settings/DepublicationReminderDeclarationTest.php::testTheRuleParsesAndMatchesAPublicationDueInFiveDays` and `::testTheRuleDoesNotMatchAPublicationDueInTenDays`, running OpenRegister's parser and evaluator over the shipped JSON when the classes exist. gate-18 (notification dialect) must stay green: no imperative dispatch.
- [ ] 3.3 Live: on the dev instance, set a depublication date five days out, run OpenRegister's `ScheduledNotificationJob` twice with `occ background-job:execute`, and paste the one notification in the PR body (REQ-PSG-003). Verify: the pasted notification and the count of one.

## 4. No documents

- [ ] 4.1 Add `PublicationDocumentCount::count(string $id): int` (files from OpenRegister's `FileMapper::getFilesForObject()` plus `documentReference` objects pointing at the publication, 0 for references while that schema is absent) and the 409 `no-documents` refusal on the publish route (REQ-PSG-004). Verify: `tests/Unit/Service/Publication/PublicationDocumentCountTest.php::testAReferenceCountsAsADocument`, `tests/Unit/Controller/PublicationStateControllerTest.php::testPublishWithNoDocumentsIsRefusedWithoutConfirmation` (fails today) and `::testAnUnreadableDocumentCountCountsAsZero`.
- [ ] 4.2 Show the count, the warning and the confirmation tick in `PublishPublicationDialog.vue` (REQ-PSG-004). Verify: `tests/e2e/publication-schedule-guards.spec.ts` "publishing with no documents asks twice" and "a reference counts as a document".

## 5. Docs

- [ ] 5.1 Document the embargo, the lift right and the reminder in `docs/`, and the strings in `l10n/` (en, nl). Verify: `npm run check:l10n` and a grep for U+2014 on the changed docs.

## 6. Verification

- [ ] 6.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`. The listener sits on every publication write: run the full unit suite once.
- [ ] 6.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran. gate-19 wants the `@e2e` references of 2.2, 3.1 and 4.2; gate-18 checks the notification dialect.
- [ ] 6.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 6.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Rows 5.11, 5.13 and 5.21 become `production` only once a store release ships it.
