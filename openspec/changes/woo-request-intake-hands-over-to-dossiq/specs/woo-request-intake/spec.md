---
status: proposed
---

# Woo request intake

## ADDED Requirements

### Requirement: The wooRequest schema accepts dossiq's import stamp (REQ-WHD-001)

The `wooRequest` schema SHALL declare `migratedTo` (string, `format: uuid`, the dossiq case uuid) and `migratedAt` (string, `format: date-time`), with its version bumped, in the same release as REQ-WHD-002. A save that sets only these two keys on a stored request SHALL be accepted.

#### Scenario: dossiq's stamp is stored, not dropped
<!-- @e2e exclude Schema contract for a cross-app write; proven by WooRequestMigrationStampTest::testTheImportStampIsStoredOnTheRequest, which saves the exact stamp OpenCatalogiWooImport::run() writes through ObjectService and reads it back, and fails on today's schema because both keys are undeclared and dropped. -->

- **GIVEN** a stored `wooRequest` WOO-2026-A1B2C3
- **WHEN** it is saved with `migratedTo` a case uuid and `migratedAt` now, as dossiq's import does
- **THEN** reading it back returns both values

### Requirement: A new request is forwarded to dossiq and minted only there (REQ-WHD-002)

`OCA\OpenCatalogi\Service\Woo\DossiqWooForward::forward(array $answers, string $receivedAt, string $origin): array` SHALL, when the app `dossiq` is enabled and `OCA\Dossiq\Woo\WooRequestIntake` resolves from the container, call `receive(answers: $answers, receivedAt: $receivedAt, origin: $origin)` and answer dossiq's `outcome`, `requestId`, `reference`, `dueAt`, `message` and `caseUrl` unchanged. `PortalContributionProvider::receiveWooRequest()` SHALL call it with origin `portal-form` and answer the first five keys; `WooRequestController::receive()` SHALL call it with origin `opencatalogi`. Neither SHALL call `WooRequestIntake::receive()` or `StatutoryTerm::arm()` of OpenCatalogi while dossiq is installed. When the call throws, or the answer lacks any of the five keys or one is not a string, the forward SHALL answer `outcome` `unavailable`, empty ids and a message that the request was not received and can be sent again, and SHALL log the cause. Nothing SHALL be stored in OpenCatalogi in any case.

#### Scenario: A portal request is armed in dossiq
<!-- @e2e exclude Cross-app call; proven by DossiqWooForwardTest::testAPortalRequestIsForwardedWithPortalFormOrigin, which asserts the exact method, named arguments and origin on a double of OCA\Dossiq\Woo\WooRequestIntake shaped from dossiq's REQ-WTO-001, and by the live check in tasks 2.4. -->

- **GIVEN** dossiq installed
- **WHEN** portaliq calls `receiveWooRequest(['requestedInformation' => 'Alle adviezen over de Stationsweg 2025', 'requesterName' => 'J. de Vries', 'requesterEmail' => 'j@example.nl'], '2026-11-27T10:15:00+01:00')`
- **THEN** dossiq's `receive()` is called with those answers, that moment and origin `portal-form`
- **AND** the answer carries dossiq's `outcome` `armed`, its case reference and `dueAt` 2026-12-28
- **AND** no `wooRequest` object is created in OpenCatalogi

#### Scenario: The forward fails
<!-- @e2e exclude Fail-closed path; proven by DossiqWooForwardTest::testAThrowingForwardAnswersUnavailableAndStoresNothing and ::testAnAnswerMissingAKeyIsUnavailable. -->

- **GIVEN** dossiq installed and a `receive()` that throws
- **WHEN** a request is received
- **THEN** the answer is `unavailable` with a message to send it again
- **AND** nothing is stored in either app

### Requirement: Every stored request is migrated with its term, and OpenCatalogi's timer is stopped after (REQ-WHD-003)

A repair step `OCA\OpenCatalogi\Repair\HandWooRequestsToDossiq`, registered post-migration in `appinfo/info.xml`, SHALL, when dossiq is installed, call `OCA\Dossiq\Woo\OpenCatalogiWooImport::run(dryRun: false)`. For each entry of `migrated` it SHALL cancel OpenCatalogi's own term timer for that request through OpenRegister's `FlowTimerService::cancelForSubject()` with the request as subject and the reason "moved to dossiq case <caseId>". It SHALL store `unmigrated` and `failed` in app config (`woo_requests_unmigrated`, `woo_requests_failed`) with the moment, and the Woo readiness check and the Woo settings section SHALL show them. It SHALL be safe to run again. A request listed in `failed` keeps its OpenCatalogi timer.

#### Scenario: A running request moves and only one term runs
<!-- @e2e exclude Repair step across two apps; proven by HandWooRequestsToDossiqTest::testMigratedRequestsHaveTheirOwnTimerCancelledAndFailedOnesKeepIt, which drives the step with a double of OpenCatalogiWooImport answering REQ-WTO-004's exact keys. -->

- **GIVEN** two stored requests, of which dossiq's import migrates one and fails the other
- **WHEN** the repair step runs
- **THEN** OpenCatalogi's timer of the migrated request is cancelled and the failed one's still runs
- **AND** `woo_requests_unmigrated` is 1

#### Scenario: The import answers in a shape we do not expect
<!-- @e2e exclude Fail-closed path; proven by HandWooRequestsToDossiqTest::testAnUnexpectedAnswerCancelsNothing. -->

- **GIVEN** an import answer without `migrated`
- **WHEN** the step runs
- **THEN** no timer is cancelled and the step reports the answer as unreadable

### Requirement: With nothing left to migrate, the request schema is read-only (REQ-WHD-004)

When `woo_requests_unmigrated` is 0 after a successful run, the step SHALL set `woo_requests_read_only` to true. While it is true, a listener on `ObjectCreatingEvent` and `ObjectUpdatingEvent` for the `wooRequest` schema SHALL refuse every create and every update except one that changes only `migratedTo` and `migratedAt`. The request write routes (`receive` without dossiq, `extend`, `pause`, `resume`, `attachBatch`) SHALL answer 410 naming dossiq.

#### Scenario: A read-only request cannot be changed
<!-- @e2e exclude Pre-save refusal; proven by WooRequestReadOnlyListenerTest::testAnUpdateIsRefusedButTheStampIsAccepted, built on the real events. -->

- **GIVEN** `woo_requests_read_only` true
- **WHEN** a caller changes a request's status, and the import writes a stamp
- **THEN** the change is refused and the stamp is stored

### Requirement: Without dossiq, OpenCatalogi takes no Woo requests (REQ-WHD-005)

When dossiq is not installed, the seven routes under `/api/woo/requests` SHALL answer 404, `PortalContributionProvider::receiveWooRequest()` SHALL answer `outcome` `unavailable` with the message "Woo requests are handled by dossiq, which is not installed.", and OpenCatalogi's Woo settings section SHALL say that Woo requests are handled by dossiq, which is not installed, with a link to install it from the app store. Publishing decisions and documents SHALL work as before.

#### Scenario: An installation without dossiq

- **GIVEN** an instance with OpenCatalogi and without dossiq
- **WHEN** an administrator opens the Woo settings
- **THEN** the page says Woo requests are handled by dossiq, which is not installed, and links to install it
- **AND** `POST /apps/opencatalogi/api/woo/requests` answers 404

#### Scenario: Publishing still works without dossiq
<!-- @e2e exclude Regression contract; proven by the existing WooServiceTest::testPublishBatch* cases running with dossiq absent. -->

- **GIVEN** no dossiq
- **WHEN** an officer publishes a Woo batch
- **THEN** the publication is public as before

### Requirement: The release after removes OpenCatalogi's intake (REQ-WHD-006)

In the release after the one that ships REQ-WHD-001 to REQ-WHD-005, OpenCatalogi SHALL remove `WooRequestController`, its routes, `WooRequestIntake`, `StatutoryTerm`, `WooRequestService::receive()` and the term arming, and SHALL keep the `wooRequest` schema read-only for the record. The removal PR SHALL be opened only when the readiness data shows `woo_requests_unmigrated` 0 on the instances the organisation runs, stated in the PR body.

#### Scenario: The routes are gone
<!-- @e2e exclude Route table contract; proven by RouteRemovalTest::testNoWooRequestRouteRemains in the removal PR. -->

- **WHEN** `appinfo/routes.php` is read after the removal
- **THEN** no route name starts with `wooRequest#`
