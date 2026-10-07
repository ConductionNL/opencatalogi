# Tasks: data-file-import-failure-alert

Build after `open-data-table-query-and-dictionary` has merged (it adds `publishedTable`, `PublishedTableService` and the Tables section). Read `design.md`; ADR-031 for the notification dialect. Hydra gate 18 (`notification-dialect`) checks the declaration.

- [ ] 1.1 Add `importedBy` and the `table-import-failed` rule of design D1 to `publishedTable` in `lib/Settings/register.d/open-data-tables.json`, version bumped (REQ-DFA-001). Verify: `tests/Unit/Settings/PublishedTableNotificationSchemaTest.php::testTheRuleValidates` runs OpenRegister's `NotificationAnnotationValidator` on it (skipped with a named reason without OpenRegister); hydra gate 18.
- [ ] 1.2 `PublishedTableService` writes `importing` first, sets `importedBy`, and ends `ready` or `failed` (REQ-DFA-002). Verify: `tests/Unit/Service/PublishedTableServiceTest.php::testAFailedPreviewGoesFromImportingToFailed`.
- [ ] 1.3 `lib/BackgroundJob/PublishedTableImportJob.php` for files over 5,000 rows, Throwable to `failed`, stale `importing` to `failed` after an hour (REQ-DFA-002). Verify: `tests/Unit/BackgroundJob/PublishedTableImportJobTest.php::testAFailedImportEndsFailedWithImportedBy`, `::testAThrowableEndsFailed`, `::testAStaleImportIsFailed`.
- [ ] 1.4 Listener on OpenRegister's file update event queues a re-import for an attachment behind a table, registered in `Application::register()` (REQ-DFA-003). Verify: `tests/Unit/Listener/PublishedTableReimportListenerTest.php::testAReplacedFileQueuesAReimport` on the real event class.
- [ ] 2.1 Tables section: "Import failed" chip, `lastError`, time, Retry (REQ-DFA-004). Verify: e2e `tests/e2e/data-file-import-failure-alert.spec.ts` publishes a CSV with a bad row, reads the failure on the page and the notification in Nextcloud's notification list, carrying `@e2e` for "The editor follows the notification".
- [ ] 2.2 nl and en strings; diff check while building; once before push `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, `format`. One PR `--base development`.
- [ ] 2.3 Live: on the dev instance import a CSV with a bad row and paste the notification's subject and message in the PR body.
