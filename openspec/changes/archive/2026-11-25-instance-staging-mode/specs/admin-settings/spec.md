---
status: proposed
---

# Admin settings

## ADDED Requirements

### Requirement: An administrator puts the instance in staging (REQ-STG-001)

The OpenCatalogi admin settings SHALL offer Instance mode with the values production and staging, stored as app config `instance_mode` (default `production`). Changing it SHALL ask for confirmation and write an audit entry with the user, the old and the new value. While it is `staging`, every OpenCatalogi page SHALL show a banner saying the instance is in staging and nothing leaves it, using the `nextcloud-vue` environment banner when the installed version exports it and an OpenCatalogi notice otherwise. The settings SHALL list the OpenRegister webhooks and integriq synchronisations that are enabled, as things OpenCatalogi does not stop.

#### Scenario: An administrator turns staging on

- **GIVEN** an administrator in the OpenCatalogi admin settings
- **WHEN** they set Instance mode to staging and confirm
- **THEN** every OpenCatalogi page shows the staging banner
- **AND** the settings list the enabled OpenRegister webhooks by name

### Requirement: Nothing leaves the instance in staging (REQ-STG-002)

`OCA\OpenCatalogi\Service\OutboundGate::allow(string $channel): bool` SHALL answer `false` when `instance_mode` is `staging` or cannot be read. These calls SHALL ask it first, and on `false` SHALL store a `dryRunDelivery` object (`channel`, `payload`, `at`, `user`, `reason`) instead of sending, and SHALL return a result marked `dryRun: true`: `NationalIndexService::deliver()`, `NationalIndexService::deliverToPlooi()`, `PlooiDeliveryService::deliver()`, `WooRegistrationService::request()`, `BroadcastService::sendBroadcastRequest()` and `DirectoryService::syncDirectory()` when it would announce. A dry run SHALL NOT set `plooiStatus`, `plooiDeliveredAt`, a withdrawal `acknowledgedAt` or the registration answer. A `grep` test SHALL assert that no other class in `lib/` constructs an HTTP client or dispatches the gateway delivery event.

#### Scenario: An officer rehearses the whole flow and nothing leaves
<!-- @e2e exclude Outbound calls are server-side; proven by OutboundGateWiringTest::testEveryChannelRecordsADryRunInStaging, which drives each named method with a recording HTTP client and event dispatcher and fails on today's code because each one sends. -->

- **GIVEN** an instance in staging with integriq installed and PLOOI delivery on for the catalogue
- **WHEN** an officer publishes a Woo publication, announces it to the national index and requests the Woo-index registration
- **THEN** no HTTP request leaves and no gateway delivery event is dispatched
- **AND** three `dryRunDelivery` records hold what would have been sent
- **AND** the publication's `plooiStatus` is empty

#### Scenario: The mode cannot be read
<!-- @e2e exclude Fail-closed path; proven by OutboundGateTest::testAnUnreadableModeDoesNotAllow. -->

- **GIVEN** an app config read that throws
- **WHEN** a channel asks the gate
- **THEN** the gate answers no and the dry run names the reason

#### Scenario: The officer reviews what would have gone out

- **GIVEN** an instance in staging after a rehearsal
- **WHEN** the officer opens Dry runs in OpenCatalogi
- **THEN** each would-be delivery is listed with its channel, time and payload

### Requirement: The public surface is closed to anonymous callers in staging (REQ-STG-003)

A middleware, `OCA\OpenCatalogi\Middleware\StagingMiddleware`, registered in `Application::register()`, SHALL answer every `#[PublicPage]` route of OpenCatalogi with 503, `X-Robots-Tag: noindex, nofollow`, and a JSON body `{error: "staging"}` when `instance_mode` is `staging` (or unreadable) and the caller is not signed in. robots.txt SHALL answer `Disallow: /` in staging. A signed-in user SHALL see the public routes as an anonymous reader would in production, so the officer can check the result.

#### Scenario: A harvester meets a staging instance
<!-- @e2e exclude Public endpoint status codes; proven by StagingMiddlewareTest::testEveryPublicRouteAnswers503ToAnonymousInStaging, which reads every PublicPage route from appinfo/routes.php and the controllers' attributes and fails on today's code because they answer 200. -->

- **GIVEN** an instance in staging
- **WHEN** an anonymous client requests the sitemap index, a DiWoo sitemap page, `/api/dcat` and robots.txt
- **THEN** the first three answer 503 with `noindex`
- **AND** robots.txt disallows everything

#### Scenario: The officer checks the public view

- **GIVEN** an instance in staging and a signed-in officer
- **WHEN** the officer opens the public publications API for a publication they published
- **THEN** they see it as a reader would
