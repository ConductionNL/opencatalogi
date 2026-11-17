# Tasks: woo-national-output-assurance

Read `openspec/woo-build-rules.md` first. Start once `diwoo-metadata-on-the-publication` is merged. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`; listener tests construct the REAL `ObjectUpdatedEvent` (`getNewObject()`, `getOldObject()`). Ask the authority for the current DiWoo version; do not take the version this app declares as proof of anything. Use a recorded authority response in tests.

## 1. Re-delivery

- [x] 1.1 Extend `PlooiDeliveryListener::handle()` and `PlooiDeliveryService` for updates and withdrawals of delivered publications (REQ-WNO-001, REQ-WND-003). Verify: `tests/Unit/Listener/PlooiDeliveryListenerTest.php::testAChangedTitleOnADeliveredPublicationQueuesAnUpdate` (fails today), `::testAChangeOutsideDiwooQueuesNothing`, `::testDepublishingADeliveredPublicationQueuesAWithdrawal`; `tests/Unit/Service/Publication/PlooiDeliveryServiceTest.php::testADocumentNoLongerPublicIsWithdrawn`.

## 2. Validation at render time

- [x] 2.1 Ship the XSD files in `lib/Settings/diwoo/` and validate each document in `SitemapService` (REQ-WNO-002). Verify: `tests/Unit/Service/SitemapServiceTest.php::testAnXsdInvalidDocumentIsLeftOutAndReported` (fails today) and `::testARenderedPageValidatesAgainstTheShippedXsd`.
  - Done: the three authority files (`diwoo-metadata.xsd`, `diwoo-metadata-lijsten.xsd`, `MDTO-XML1.0.1.xsd`) are copied byte for byte from `tests/Unit/Service/fixtures/diwoo-0.9.8/` into `lib/Settings/diwoo/`. `OCA\OpenCatalogi\Service\DiwooXsdValidator` renders a url entry as the page does, takes out its `diwoo:Document` and runs `schemaValidate()`; the MDTO import maps to the shipped copy and any other location outside the directory is refused, so it never reaches the network, and an unloadable schema is an error, not a pass. `SitemapService::buildSitemap()` leaves an invalid document out and logs its XSD errors; `collectDiwooViolations()` lists each complete document the XSD refuses with reason `xsd-invalid` and the messages. Tests: the two named, plus `tests/Unit/Service/DiwooXsdValidatorTest.php`. Raising an incident for it belongs to 4.2.
- [x] 2.2 Add `DiwooStandardCheck`, registered in `appinfo/info.xml`, and its readiness result (REQ-WNO-002). Verify: `tests/Unit/BackgroundJob/DiwooStandardCheckTest.php::testANewerVersionFailsReadiness`, `::testAnUnreachableAuthorityIsNotCurrent`, `::testTheJobIsRegistered`.
  - Done: `lib/BackgroundJob/DiwooStandardCheck.php`, daily, registered in `appinfo/info.xml`. It reads the version index through `StandardsVersionService` and stores `{declared, published, status, ready, message, checkedAt}` in app config `diwoo_standard_check`: "DiWoo <new> is current; this instance emits <ours>" when behind, "could not verify" when the authority cannot be read. Tests over a recorded index (`tests/fixtures/diwoo-version-index-0.9.9.html`). Raising an incident from it waits for 4.1 and 4.2.

## 3. Reconciliation

- [ ] 3.1 Add `DiwooSitemapReconciliation`, registered in `appinfo/info.xml`, and show its result in the readiness report (REQ-WNO-003). Verify: `tests/Unit/BackgroundJob/DiwooSitemapReconciliationTest.php::testADocumentMissingFromTheSitemapIsReported` (fails today), `::testAnExtraEntryIsReported`, `::testAFailedReadIsIncomplete`, `::testTheJobIsRegistered`.

## 4. Incidents

- [x] 4.1 Add the `wooPipelineIncident` schema with its notification rule and `PipelineIncidents::raise()` with coalescing (REQ-WNO-004). Verify: `tests/Unit/Service/Woo/PipelineIncidentsTest.php::testAnIdenticalOpenIncidentIsCoalesced`; gate-18 stays green (declarative, no imperative dispatch).
  - Done: `lib/Settings/register.d/woo-national-output-assurance.json` declares `wooPipelineIncident` (`kind`, `subject`, `message`, `at`, `resolvedAt`) with a declarative `created` rule on `nc-notification` and `email` to the role `woo-incident-receiver`, so no imperative dispatch. The role is empty as shipped; 4.3 assigns it the group in `woo_incident_group`. `OCA\OpenCatalogi\Service\Woo\PipelineIncidents::raise()` stores one and coalesces an identical open incident raised in the last 24 hours; storing never throws into the caller. Settings key `woo_pipeline_incident_schema`, three demo rows. Tests: `tests/Unit/Service/Woo/PipelineIncidentsTest.php`.
- [ ] 4.2 Call `raise()` from every failure path named in REQ-WNO-004 (REQ-WNO-004). Verify: `tests/Unit/Service/Woo/PipelineIncidentsWiringTest.php`, one case per path through its real caller (`WooReadinessService`, `WooService::publishBatch()`, `NationalIndexService::deliver()`, `deliverToPlooi()`, `PlooiDeliveryService::deliver()`, the reconciliation and the standard check).
  - Started: `DiwooStandardCheck` raises `diwoo-version` on a superseded version (`DiwooStandardCheckTest::testANewerVersionFailsReadiness`). Left: the readiness, sitemap, batch and national hand-over paths, and the reconciliation (3.1).
- [ ] 4.3 Add the group setting and the open incidents list to the Woo settings, and the readiness failure when no group is set (REQ-WNO-004). Verify: `tests/e2e/woo-national-output-assurance.spec.ts` "a failed PLOOI delivery reaches the Woo team" with a failing PLOOI fake, carrying `@e2e` REQ-WNO-004; `WooReadinessServiceTest::testNoIncidentGroupFailsReadiness`.
- [ ] 4.4 Live: on the dev instance make a PLOOI delivery fail, and paste the notification and the e-mail log entry in the PR body (REQ-WNO-004). Verify: the pasted output. (live pass, decision 139)

## 5. Verification

- [ ] 5.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`.
- [ ] 5.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] 5.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 5.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Rows 5.19, 9.13, 9.20 and 13.9 become `production` only once a store release ships it.
