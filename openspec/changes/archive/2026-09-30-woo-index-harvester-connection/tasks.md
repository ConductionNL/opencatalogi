# Tasks: woo-index-harvester-connection

## 1. robots.txt content

- [x] 1.1 Emit `Sitemap:` lines only for catalogues with `hasWooSitemap` true, each on its own line, in `RobotsController::index()` (REQ-WIH-001). Verify: `tests/Unit/Controller/RobotsControllerTest.php` with one enabled and one disabled catalogue, asserting the exact text.
- [x] 1.2 Add `Allow:` lines for the app's API paths (REQ-WIH-001). Verify: same test class.

## 2. The root rule

- [x] 2.1 Render the Apache and nginx rules from the base URL in the Woo section of `src/views/settings/Settings.vue`, with a copy button each (REQ-WIH-002). Verify: `tests/e2e/woo-index-connection.spec.ts` opens settings and reads both rules.
- [x] 2.2 Link the `missing-sitemap-reference` and `robots-txt` failures of the readiness report to the rules (REQ-WIH-002). Verify: same e2e spec with a stubbed failing report.

## 3. Registration through the gateway

- [x] 3.1 Reshape `NationalIndexService::registerWithWooIndex()` to take the composed instance request (REQ-WIH-003). Verify: `tests/Unit/Service/Publication/NationalIndexServiceTest.php` with a fake `CallService`.
- [x] 3.2 Add `POST /api/woo/registration` (admin) that composes, delivers and stores status, URL, time and answer; on an unreachable gateway it returns the composed request and stores nothing as sent (REQ-WIH-003). Verify: `tests/Unit/Controller/WooRegistrationControllerTest.php`, both paths.
- [x] 3.3 Replace the hand-set status select with a Request registration button, the stored answer, and a Mark as registered action (REQ-WIH-003). Verify: e2e spec from 2.1.

## 4. A current verdict

- [x] 4.1 Add `lib/BackgroundJob/WooReadinessCheck.php` (daily) and register it in `appinfo/info.xml` (REQ-WIH-004). Verify: `tests/Unit/BackgroundJob/WooReadinessCheckTest.php` asserts it skips when no catalogue is Woo-enabled.
- [x] 4.2 Run the check once when a catalogue's `hasWooSitemap` turns true (REQ-WIH-004). Verify: `tests/Unit/Listener/WooReadinessTriggerListenerTest.php` built on a real `ObjectUpdatedEvent` with an old and a new catalogue, not a mocked event.

## 5. Docs and strings

- [x] 5.1 Document the root rule and the registration flow in `docs/`, and add the new strings to `l10n/` in English and Dutch. Verify: `npm run lint` and the l10n check pass.
