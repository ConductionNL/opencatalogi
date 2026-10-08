# Tasks: woo-national-output-assurance

Read `openspec/woo-build-rules.md` first. Start once `diwoo-metadata-on-the-publication` is merged. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`; listener tests construct the REAL `ObjectUpdatedEvent` (`getNewObject()`, `getOldObject()`). Ask the authority for the current DiWoo version; do not take the version this app declares as proof of anything. Use a recorded authority response in tests.

## 1. Re-delivery

- [ ] 1.1 Extend `PlooiDeliveryListener::handle()` and `PlooiDeliveryService` for updates and withdrawals of delivered publications (REQ-WNO-001, REQ-WND-003). Verify: `tests/Unit/Listener/PlooiDeliveryListenerTest.php::testAChangedTitleOnADeliveredPublicationQueuesAnUpdate` (fails today), `::testAChangeOutsideDiwooQueuesNothing`, `::testDepublishingADeliveredPublicationQueuesAWithdrawal`; `tests/Unit/Service/Publication/PlooiDeliveryServiceTest.php::testADocumentNoLongerPublicIsWithdrawn`.

## 2. Validation at render time

- [ ] 2.1 Ship the XSD files in `lib/Settings/diwoo/` and validate each document in `SitemapService` (REQ-WNO-002). Verify: `tests/Unit/Service/SitemapServiceTest.php::testAnXsdInvalidDocumentIsLeftOutAndReported` (fails today) and `::testARenderedPageValidatesAgainstTheShippedXsd`.
- [ ] 2.2 Add `DiwooStandardCheck`, registered in `appinfo/info.xml`, and its readiness result (REQ-WNO-002). Verify: `tests/Unit/BackgroundJob/DiwooStandardCheckTest.php::testANewerVersionFailsReadiness`, `::testAnUnreachableAuthorityIsNotCurrent`, `::testTheJobIsRegistered`.

## 3. Reconciliation

- [ ] 3.1 Add `DiwooSitemapReconciliation`, registered in `appinfo/info.xml`, and show its result in the readiness report (REQ-WNO-003). Verify: `tests/Unit/BackgroundJob/DiwooSitemapReconciliationTest.php::testADocumentMissingFromTheSitemapIsReported` (fails today), `::testAnExtraEntryIsReported`, `::testAFailedReadIsIncomplete`, `::testTheJobIsRegistered`.

## 4. Incidents

- [ ] 4.1 Add the `wooPipelineIncident` schema with its notification rule and `PipelineIncidents::raise()` with coalescing (REQ-WNO-004). Verify: `tests/Unit/Service/Woo/PipelineIncidentsTest.php::testAnIdenticalOpenIncidentIsCoalesced`; gate-18 stays green (declarative, no imperative dispatch).
- [ ] 4.2 Call `raise()` from every failure path named in REQ-WNO-004 (REQ-WNO-004). Verify: `tests/Unit/Service/Woo/PipelineIncidentsWiringTest.php`, one case per path through its real caller (`WooReadinessService`, `WooService::publishBatch()`, `NationalIndexService::deliver()`, `deliverToPlooi()`, `PlooiDeliveryService::deliver()`, the reconciliation and the standard check).
- [ ] 4.3 Add the group setting and the open incidents list to the Woo settings, and the readiness failure when no group is set (REQ-WNO-004). Verify: `tests/e2e/woo-national-output-assurance.spec.ts` "a failed PLOOI delivery reaches the Woo team" with a failing PLOOI fake, carrying `@e2e` REQ-WNO-004; `WooReadinessServiceTest::testNoIncidentGroupFailsReadiness`.
- [ ] 4.4 Live: on the dev instance make a PLOOI delivery fail, and paste the notification and the e-mail log entry in the PR body (REQ-WNO-004). Verify: the pasted output.

## 5. Verification

- [ ] 5.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`.
- [ ] 5.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] 5.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 5.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Rows 5.19, 9.13, 9.20 and 13.9 become `production` only once a store release ships it.
