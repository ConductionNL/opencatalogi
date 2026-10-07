# Tasks: instance-staging-mode

Read `openspec/woo-build-rules.md` first. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`. Use a recording Guzzle `MockHandler` and a recording `IEventDispatcher` to prove nothing leaves; a mock that is never asked proves nothing.

## 1. Mode

- [ ] 1.1 Add `instance_mode` to the admin settings with confirmation and an audit entry (REQ-STG-001). Verify: `tests/Unit/Controller/SettingsControllerTest.php::testChangingTheInstanceModeIsAudited`.
- [ ] 1.2 Show the staging banner on every page: import the `nextcloud-vue` environment banner when the installed version exports it, otherwise an OpenCatalogi notice; list enabled OpenRegister webhooks and integriq synchronisations in the settings (REQ-STG-001). Verify: `tests/e2e/instance-staging-mode.spec.ts` "an administrator turns staging on", carrying `@e2e` REQ-STG-001.

## 2. Outbound gate

- [ ] 2.1 Add `OutboundGate` and the `dryRunDelivery` schema fragment with `slug` and version (REQ-STG-002). Verify: `tests/Unit/Service/OutboundGateTest.php::testStagingDoesNotAllow`, `::testAnUnreadableModeDoesNotAllow`, `::testProductionAllows`.
- [ ] 2.2 Put the gate first in the six named methods; a dry run stores the record and sets no delivery field (REQ-STG-002). Verify: `tests/Unit/Service/OutboundGateWiringTest.php::testEveryChannelRecordsADryRunInStaging` (fails today: each sends), `::testADryRunSetsNoDeliveryField`.
- [ ] 2.3 Add a test that greps `lib/` for `new Client(`, `IClientService` and the gateway delivery event names, and fails on a site outside the six gated methods and the inbound readers it lists by name (REQ-STG-002). Verify: `tests/Unit/Service/OutboundGateCoverageTest.php::testNoUngatedOutboundSiteExists`.
- [ ] 2.4 Add a Dry runs page listing `dryRunDelivery` records (REQ-STG-002). Verify: `tests/e2e/instance-staging-mode.spec.ts` "the officer reviews what would have gone out".

## 3. Public surface

- [ ] 3.1 Add `StagingMiddleware`, registered with `registerMiddleware()`, and the staging branch in `RobotsController::index()` (REQ-STG-003). Verify: `tests/Unit/Middleware/StagingMiddlewareTest.php::testEveryPublicRouteAnswers503ToAnonymousInStaging` (fails today), `::testASignedInUserSeesThePublicView`, `::testProductionIsUntouched`; `tests/Unit/Controller/RobotsControllerTest.php::testStagingDisallowsEverything`.
- [ ] 3.2 Live: on the dev instance turn staging on, `curl` the sitemap index, `/api/dcat` and robots.txt anonymously, run one publish with announce, and paste the answers and the dry-run count in the PR body; turn staging off afterwards (REQ-STG-002, REQ-STG-003). Verify: the pasted output.

## 4. Docs

- [ ] 4.1 Document staging for administrators in `docs/`, including what it does not stop, and the strings in `l10n/` (en, nl). Verify: `npm run check:l10n` and a grep for U+2014 on the changed docs.

## 5. Verification

- [ ] 5.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`. The middleware sits on every public route: run the full unit suite once.
- [ ] 5.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] 5.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 5.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Row 13.5 becomes `production` only once a store release ships it.
