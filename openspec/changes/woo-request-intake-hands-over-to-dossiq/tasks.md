# Tasks: woo-request-intake-hands-over-to-dossiq

Read `/home/rubenlinde/memcap-work/woo-build/LANE-RULES-BUILD.md` first. Start only once `dossiq/woo-request-takes-over-from-opencatalogi` (dossiq PR #3285) is merged on dossiq `development`. Before writing a double, read on dossiq `development`: `OCA\Dossiq\Woo\WooRequestIntake::receive(array $answers, string $receivedAt = '', string $origin = 'portal-form'): array` and its answer keys `outcome, requestId, reference, dueAt, message, caseUrl`; `OCA\Dossiq\Woo\OpenCatalogiWooImport::run(bool $dryRun = false): array` and its keys `imported, alreadyImported, failed, unmigrated, migrated[{requestId, reference, caseId, termTimer}]`. If either changed, follow dossiq and say so in the PR body. OpenRegister: `FlowTimerService::cancelForSubject(string $subjectType, string $subjectUuid, string $reason, ?string $actor, ?DateTimeInterface $now = null): int`. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`. Two PRs: the first carries groups 1 to 5; the second, in the release after, carries group 6.

## 1. The stamp (same release as the forward)

- [ ] 1.1 Declare `migratedTo` and `migratedAt` on `#wooRequest` and bump its version (REQ-WHD-001). Verify: `tests/Unit/Settings/WooRequestMigrationStampTest.php::testTheImportStampIsStoredOnTheRequest` (fails today: the keys are dropped), saving the exact stamp shape from dossiq's REQ-WTO-004 step 5.

## 2. Forward

- [ ] 2.1 Add `DossiqWooForward` (REQ-WHD-002). Verify: `tests/Unit/Service/Woo/DossiqWooForwardTest.php::testAPortalRequestIsForwardedWithPortalFormOrigin`, `::testAnOfficerRequestIsForwardedWithOpencatalogiOrigin`, `::testAThrowingForwardAnswersUnavailableAndStoresNothing`, `::testAnAnswerMissingAKeyIsUnavailable`, `::testTheAnswerIsPassedThroughUnchanged`; the double is built on the real dossiq class when it is autoloadable and on its documented signature otherwise.
- [ ] 2.2 Route `PortalContributionProvider::receiveWooRequest()` and `WooRequestController::receive()` through it while dossiq is installed, and assert OpenCatalogi's own intake and `StatutoryTerm::arm()` are not called (REQ-WHD-002). Verify: `tests/Unit/Portal/PortalContributionProviderTest.php::testReceiveWooRequestForwardsToDossiq` and `tests/Unit/Controller/WooRequestControllerTest.php::testReceiveForwardsToDossiqAndMintsNothing`.
- [ ] 2.3 Contract test on this side: a test that fails when the five keys of OpenCatalogi's `receiveWooRequest()` docblock and dossiq's REQ-WTO-001 answer differ (REQ-WHD-002). Verify: `DossiqWooForwardTest::testTheFiveKeysMatchDossiqsContract`.
- [ ] 2.4 Live: with dossiq installed on the dev instance, send a portal-shaped request through `receiveWooRequest()` and paste the answer and the dossiq case in the PR body (REQ-WHD-002). Verify: the pasted answer and the case read back from dossiq.

## 3. Migration

- [ ] 3.1 Add `HandWooRequestsToDossiq`, registered post-migration after `InitializeSettings` (REQ-WHD-003). Verify: `tests/Unit/Repair/HandWooRequestsToDossiqTest.php::testMigratedRequestsHaveTheirOwnTimerCancelledAndFailedOnesKeepIt`, `::testAnUnexpectedAnswerCancelsNothing`, `::testWithoutDossiqTheStepDoesNothing`, `::testTheStepIsRegisteredPostMigration`.
- [ ] 3.2 Show `woo_requests_unmigrated` and `woo_requests_failed` in `WooReadinessService` and the Woo settings section (REQ-WHD-003). Verify: `tests/Unit/Service/WooReadinessServiceTest.php::testUnmigratedRequestsAreReported`.
- [ ] 3.3 Live: run the step on the dev instance over seeded requests (one running, one extended and paused) and paste the counts and both dossiq cases' deadlines in the PR body (REQ-WHD-003). Set `appstoreenabled=false` before any `occ upgrade` on a mounted clone. Verify: the pasted output.

## 4. Read-only

- [ ] 4.1 Add the read-only flag, the listener on the REAL events and the 410 on the write routes (REQ-WHD-004). Verify: `tests/Unit/Listener/WooRequestReadOnlyListenerTest.php::testAnUpdateIsRefusedButTheStampIsAccepted`, `::testACreateIsRefused`; `WooRequestControllerTest::testWriteRoutesAnswer410WhenReadOnly`; an `ApplicationRegisterInvariantTest` case.

## 5. Without dossiq

- [ ] 5.1 Answer 404 on the request routes and `unavailable` from `receiveWooRequest()` without dossiq (REQ-WHD-005). Verify: `WooRequestControllerTest::testEveryRequestRouteIs404WithoutDossiq` (fails today: they answer) and `PortalContributionProviderTest::testWithoutDossiqTheIntakeIsUnavailable`.
- [ ] 5.2 Add the dossiq notice with the install link to the Woo settings section (REQ-WHD-005). Verify: `tests/e2e/woo-request-handover.spec.ts` "an installation without dossiq", carrying `@e2e` REQ-WHD-005, on a CI instance without dossiq.
- [ ] 5.3 Write the release notes of the proposal in `CHANGELOG.md` and `docs/`, following the writing skill. Verify: a grep for U+2014 on the changed files.
- [ ] 5.4 Verification for the first PR: `TMPDIR` a sibling directory outside the clone; PHPUnit by the `Tests:` line or `--no-coverage`, full suite once (the intake is central); `run-hydra-gates.sh --base origin/development` counting the gates that ran; once before push `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`; one PR with `--base development`, merge development in, never rebase, no `Co-Authored-By`.

## 6. Removal (second PR, the release after)

- [ ] 6.1 Remove `WooRequestController`, its routes, `WooRequestIntake`, `StatutoryTerm`, `WooRequestService::receive()` and the term arming; keep the schema read-only; keep `DossiqWooForward` for `receiveWooRequest()` (REQ-WHD-006). Verify: `tests/Unit/AppInfo/RouteRemovalTest.php::testNoWooRequestRouteRemains`, the route-reachability gate, and the full unit suite.
- [ ] 6.2 State in the PR body the `woo_requests_unmigrated` value read on the organisation's instances; do not open the PR while any is above zero (REQ-WHD-006). Verify: the stated values.
- [ ] 6.3 Verification as in 5.4.

Done when both PRs are merged on `development` with CI green. Rows 7.1 to 7.6 and 10.8 stay yes in dossiq; they are `production` in dossiq only once a store release ships the takeover.
