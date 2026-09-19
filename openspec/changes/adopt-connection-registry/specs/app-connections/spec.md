# app-connections Specification Delta

**Status**: proposed
**Scope**: opencatalogi
**OpenSpec changes**:
- [adopt-connection-registry](../../)

## Purpose

Admins see the outside connections of OpenCatalogi on one page, with a status the app can back.

## ADDED Requirements

### Requirement: REQ-OC-CONN-001 OpenCatalogi declares its outside connections in one static file

OpenCatalogi SHALL declare `directory`, `broadcast` and `woo-index` in `lib/Settings/connections.json` in the shape of hydra connection-registry design D2 (hydra REQ-CONN-001). All three entries SHALL be `reportedOnly`, because only OpenCatalogi can see whether a directory answered, a peer accepted a broadcast or the harvester can read this instance. No entry SHALL carry `requiredConfig` or `adapter`. Every `settingsUrl` SHALL point at a section id that the admin settings page renders.

#### Scenario: The declaration names this app and passes integriq's schema
@e2e exclude A static file with no browser surface; tests/Unit/Settings/ConnectionsDeclarationTest.php validates it against integriq's schema and checks the app id, unique keys and the anchors.

- **GIVEN** `lib/Settings/connections.json`
- **WHEN** it is validated against integriq's `connections.schema.json`
- **THEN** it SHALL validate
- **AND** its `app` SHALL equal the id in `appinfo/info.xml`
- **AND** every key SHALL be unique
- **AND** every `#section-…` anchor SHALL be an id in `src/views/settings/Settings.vue`

#### Scenario: A default directory address is not read as a missing setting
@e2e exclude A static-file property; tests/Unit/Settings/ConnectionsDeclarationTest.php asserts the directory row carries no requiredConfig.

- **GIVEN** `default_directory_url` is not set, so OpenCatalogi syncs with directory.opencatalogi.nl
- **WHEN** integriq resolves the `directory` row
- **THEN** no settings rule SHALL apply
- **AND** the row SHALL read Not checked yet until OpenCatalogi reports a sync

### Requirement: REQ-OC-CONN-002 A save asks integriq to look again

When a save writes a setting that changes what a connection row judges, OpenCatalogi SHALL send `ConnectionRefreshRequestedEvent` with app `opencatalogi` and that key (hydra REQ-CONN-004, hydra#674). A Woo-index registration save SHALL refresh `woo-index`. A setup save that writes `default_directory_url` SHALL refresh `directory`. A save that writes neither SHALL send nothing. The refresh SHALL go before any report for that key. The event SHALL be named by string and sent only when the class exists, and it SHALL NOT change the response of the save.

#### Scenario: Saving the registration status refreshes the Woo-index row
@e2e exclude The event is not observable from a browser; tests/Unit/Controller/ConnectionReportCallersTest.php asserts the refresh and the unchanged response.

- **GIVEN** integriq is installed
- **WHEN** an admin saves the Woo-index registration status
- **THEN** OpenCatalogi SHALL send a refresh for `woo-index`
- **AND** the save SHALL answer as it did before this change

#### Scenario: A save that touches no connection sends nothing
@e2e exclude The event is not observable from a browser; tests/Unit/Service/Connection/ConnectionReporterTest.php asserts no event for unrelated keys.

- **GIVEN** integriq is installed
- **WHEN** an admin saves only the catalog register and schema
- **THEN** OpenCatalogi SHALL send no refresh

### Requirement: REQ-OC-CONN-003 OpenCatalogi reports what a sync, a broadcast and a readiness check met

A directory sync SHALL report `directory` as `configured` when every directory answered, `limited` when some did and `error` when none did. A broadcast SHALL report `broadcast` as `configured` when every peer accepted it, `limited` when some did and `error` when none did, and `unconfigured` when the instance advertises a local address or knows no peer. A broadcast report SHALL be throttled: the same status at most once an hour, a different status at most once every five minutes. A readiness check SHALL report `woo-index` as `configured` when every check passed and the registration matches, `limited` when some checks failed or the instance is not registered, `error` when no check passed or the check stopped, and `unconfigured` when no catalog has Woo sitemaps switched on. A message SHALL name a directory or peer by host only. Both events SHALL be named by string and sent only when the class exists. No report SHALL change the result of the sync, broadcast or check that sent it. No page request SHALL send an event.

#### Scenario: A sync where some directories fail reads limited
@e2e exclude A sync needs reachable peer directories, which the CI instance does not have; tests/Unit/Service/Connection/ConnectionObservationsTest.php drives the outcomes.

- **GIVEN** OpenCatalogi knows two directories
- **WHEN** a sync reaches one and not the other
- **THEN** OpenCatalogi SHALL report `directory` as `limited`
- **AND** the message SHALL name the failing directory's host and not its path

#### Scenario: A broadcast from a local address reads not configured
@e2e exclude The CI instance advertises localhost, and the event is not observable from a browser; tests/Unit/Controller/ConnectionReportCallersTest.php asserts the report.

- **GIVEN** this instance advertises `http://localhost`
- **WHEN** a broadcast runs
- **THEN** OpenCatalogi SHALL report `broadcast` as `unconfigured` with a message naming `overwrite.cli.url`

#### Scenario: Repeated broadcasts report once an hour
@e2e exclude Time-bound throttling; tests/Unit/Service/Connection/ConnectionReporterTest.php drives the clock.

- **GIVEN** a broadcast reported `configured` ten minutes ago
- **WHEN** another broadcast is accepted by every peer
- **THEN** OpenCatalogi SHALL NOT send a report
- **AND** after an hour the same outcome SHALL be reported again

#### Scenario: A readiness check without a Woo catalog reads not configured
@e2e exclude The event is not observable from a browser; tests/Unit/Controller/ConnectionReportCallersTest.php asserts the report and the unchanged 409.

- **GIVEN** no catalog has `hasWooSitemap` switched on
- **WHEN** an admin runs the readiness check
- **THEN** the response SHALL stay 409 `not-configured`
- **AND** OpenCatalogi SHALL report `woo-index` as `unconfigured`

#### Scenario: Without integriq nothing is sent
@e2e exclude The CI instance installs integriq; tests/Unit/Service/Connection/ConnectionReporterTest.php asserts nothing is sent, stored or logged when the class is absent.

- **GIVEN** integriq is not installed
- **WHEN** a sync, a broadcast or a readiness check runs, or an admin saves
- **THEN** no event SHALL be sent and nothing SHALL be logged
- **AND** each SHALL answer as it did before this change

### Requirement: REQ-OC-CONN-004 An admin reads the connections on an Integrations page

OpenCatalogi SHALL render an `index` page at `/settings/integrations` over `integriq/app_connection`, reached from the settings gear and preset to `app` equal to `opencatalogi` through its menu entry's `query` (hydra REQ-CONN-006). The page and its menu entry SHALL be admin only. The page SHALL require Integriq, and the menu entry SHALL only render when integriq is installed. The status column SHALL name all six statuses, `limited` included. The page SHALL NOT offer a generic Add button. Its Add integration action SHALL open `/apps/integriq/connections?app=opencatalogi&link=1`.

#### Scenario: The page lists only the rows of OpenCatalogi
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** OpenCatalogi and integriq are installed and integriq has synced the declaration
- **WHEN** an admin opens the Integrations page
- **THEN** the page SHALL list the three declared connections
- **AND** every listed row SHALL have `app` equal to `opencatalogi`

#### Scenario: Add integration goes to integriq
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** the Integrations page
- **WHEN** the admin chooses Add integration
- **THEN** the browser SHALL open integriq's Connections overview with `app=opencatalogi` and `link=1`

#### Scenario: A connection that works in part reads Limited
@e2e exclude Only a partial sync, broadcast or readiness check produces limited; tests/vitest/connectionRegistry.spec.js asserts the label in English and Dutch.

- **GIVEN** a row whose status is `limited`
- **WHEN** the page renders it
- **THEN** the cell SHALL read Limited, or Beperkt on a Dutch instance
