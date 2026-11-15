# Tasks: instance-staging-mode

Read `openspec/woo-build-rules.md` first. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`. Use a recording Guzzle `MockHandler` and a recording `IEventDispatcher` to prove nothing leaves; a mock that is never asked proves nothing.

## 1. Mode

- [x] 1.1 Add `instance_mode` to the admin settings with confirmation and an audit entry (REQ-STG-001). Verify: `tests/Unit/Controller/SettingsControllerTest.php::testChangingTheInstanceModeIsAudited`.
  - Built: `InstanceModeSwitch` (writes `instance_mode` only with `confirmed: true`, dispatches a `CriticalActionPerformedEvent` with the old value, the new value and the user for Nextcloud's audit log; switching back replays nothing), `GET`/`POST /api/settings/instance-mode` on `SettingsController` behind `#[AuthorizedAdminSetting]`, and the Instance mode section in the admin settings with `InstanceModeConfirmDialog`. Unit: `SettingsControllerTest::testChangingTheInstanceModeIsAudited`, `::testTheInstanceModeEndpointsNeedTheSwitch`; jest `InstanceModeRequests.spec.js`.
- [ ] 1.2 Show the staging banner on every page: import the `nextcloud-vue` environment banner when the installed version exports it, otherwise an OpenCatalogi notice; list enabled OpenRegister webhooks and integriq synchronisations in the settings (REQ-STG-001). Verify: `tests/e2e/instance-staging-mode.spec.ts` "an administrator turns staging on", carrying `@e2e` REQ-STG-001.
  - Built, code part: `StagingBanner` on every page (`App.vue`), fed by the `instance_mode` initial state the SPA listener provides (anything but production reads as staging); `OutsideReach` names the enabled OpenRegister webhooks and the integriq synchronisations that are not disabled, listed in the Instance mode section. The banner is an OpenCatalogi notice: the installed `@conduction/nextcloud-vue` does not export the environment banner yet. Unit: `OutsideReachTest`, `ProvideManifestConfigStateListenerTest::testProvidesTheInstanceMode`, jest `StagingBanner.spec.js`. Open: the e2e spec "an administrator turns staging on" (needs a browser).

## 2. Outbound gate

- [x] 2.1 Add `OutboundGate` and the `dryRunDelivery` schema fragment with `slug` and version (REQ-STG-002). Verify: `tests/Unit/Service/OutboundGateTest.php::testStagingDoesNotAllow`, `::testAnUnreadableModeDoesNotAllow`, `::testProductionAllows`.
- [x] 2.2 Put the gate first in the six named methods; a dry run stores the record and sets no delivery field (REQ-STG-002). Verify: `tests/Unit/Service/OutboundGateWiringTest.php::testEveryChannelRecordsADryRunInStaging` (fails today: each sends), `::testADryRunSetsNoDeliveryField`.
  - Built: `DryRunRecorder::hold()` (asks `OutboundGate`, stores the `dryRunDelivery` with channel, payload, at, user and reason, answers the dry run), asked first in `BroadcastService::sendBroadcastRequest()`, `NationalIndexService::deliver()`, `::deliverToPlooi()`, `::registerWithWooIndex()` and `::withdraw()`, `PlooiDeliveryService::deliver()` (before anything is saved, so no `plooiStatus` or `plooiDeliveredAt`), `WooRegistrationService::request()` (nothing stored) and the announcement in `DirectoryService::syncDirectory()` (`broadcastToDirectory()`). A held hand-over answers `dryRun: true` with a null delivered, registered or acknowledged moment. `OutboundGateWiringTest` drives all of them with a recording HTTP client, container and event dispatcher in staging, production and an unreadable mode.
- [x] 2.3 Add a test that greps `lib/` for `new Client(`, `IClientService` and the gateway delivery event names, and fails on a site outside the six gated methods and the inbound readers it lists by name (REQ-STG-002). Verify: `tests/Unit/Service/OutboundGateCoverageTest.php::testNoUngatedOutboundSiteExists`.
  - Built: `OutboundGateCoverageTest` reads `lib/` for `new Client(`, `IClientService` and `GatewayDeliveryRequestedEvent`; every file found is gated (`BroadcastService`, `NationalIndexService`, the announcement in `DirectoryService`, each hold asserted) or a named reader (`DirectoryService` fetches, `StandardsVersionService`, `WooReadinessService`), and a new file fails.
- [ ] 2.4 Add a Dry runs page listing `dryRunDelivery` records (REQ-STG-002). Verify: `tests/e2e/instance-staging-mode.spec.ts` "the officer reviews what would have gone out".
  - Built, code part: the Dry runs page and its menu entry as manifest configuration (`src/manifest.d/instance-staging-mode.json`): an index over `dryRunDelivery` in the publication register, newest first, with channel, time, user and reason, read only, a row opens the payload. `check:manifest` passes. Open: the e2e spec "the officer reviews what would have gone out" (needs a browser).

## 3. Public surface

- [x] 3.1 Add `StagingMiddleware`, registered with `registerMiddleware()`, which also answers robots.txt `Disallow: /` in staging (REQ-STG-003). Verify: `tests/Unit/Middleware/StagingMiddlewareTest.php::testEveryPublicRouteAnswers503ToAnonymousInStaging` (fails today), `::testASignedInUserSeesThePublicView`, `::testProductionIsUntouched`; `::testStagingRobotsDisallowsEverything`.
- [ ] 3.2 Live: on the dev instance turn staging on, `curl` the sitemap index, `/api/dcat` and robots.txt anonymously, run one publish with announce, and paste the answers and the dry-run count in the PR body; turn staging off afterwards (REQ-STG-002, REQ-STG-003). Verify: the pasted output.

## 4. Docs

- [x] 4.1 Document staging for administrators in `docs/`, including what it does not stop, and the strings in `l10n/` (en, nl). Verify: `npm run check:l10n` and a grep for U+2014 on the changed docs.
  - Built: `docs/Administrator/staging.md` (Dutch, like the other administrator pages): turning it on, what it holds back, what it does not stop. The new strings are in `l10n/en.json` and `l10n/nl.json`, rebuilt into the `.js` catalogues; `check:l10n` reports nothing missing or unused, and the doc has no U+2014.

## 5. Verification

- [ ] 5.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`. The middleware sits on every public route: run the full unit suite once.
- [ ] 5.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] 5.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 5.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Row 13.5 becomes `production` only once a store release ships it.
