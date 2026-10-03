# adopt-connection-registry tasks

## 1. Declare

- [x] 1.1 Write `lib/Settings/connections.json` with `directory`, `broadcast` and `woo-index`.
- [x] 1.2 Give the Federation sync and Woo-index harvester readiness sections the ids the file links to.
- [x] 1.3 Guard the file in `tests/Unit/Settings/ConnectionsDeclarationTest.php`, against integriq's schema in `tests/Fixtures/Integriq/connections.schema.json`.

## 2. Reports and refresh

- [x] 2.1 Add `lib/Service/Connection/ConnectionObservations.php` and `ConnectionReporter.php`.
- [x] 2.2 Report from `DirectoryService::doCronSync()`.
- [x] 2.3 Report from `BroadcastService::broadcast()`, throttled.
- [x] 2.4 Report from `WooReadinessController::run()`.
- [x] 2.5 Refresh from `SettingsController::update()` and `SetupController::config()`.
- [x] 2.6 Add the integriq event stubs for PHPUnit and psalm.
- [x] 2.7 Cover it in `ConnectionObservationsTest`, `ConnectionReporterTest` and `ConnectionReportCallersTest`.

## 3. Page

- [x] 3.1 Add `src/manifest.d/connection-registry.json` with the page and its settings-gear menu entry.
- [x] 3.2 Add `src/services/connectionRegistry.js` with the two formatters and the Add integration handler.
- [x] 3.3 Wire the formatters in `src/App.vue` and the handler in `src/registry.js`; register `PowerPlugOutline` in `src/icons.js`.
- [x] 3.4 Add the strings to `l10n/en` and `l10n/nl`.
- [x] 3.5 Cover it in `tests/vitest/connectionRegistry.spec.js`.

## 4. End to end

- [x] 4.1 Write `tests/e2e/integrations-page.spec.ts`.
- [x] 4.2 Install integriq in the CI `additional-apps`.

## 5. After integriq ships

- [ ] 5.1 Run the e2e spec against an instance with both apps, then archive this change.
