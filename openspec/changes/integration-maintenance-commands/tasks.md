# Tasks: integration-maintenance-commands

## 1. Directory

- [ ] 1.1 Add `opencatalogi:directory:sync` with `--url` (REQ-IMC-001). Verify: `tests/Unit/Command/DirectorySyncCommandTest.php` with a mocked `DirectoryService`, both paths and `--output=json`.
- [ ] 1.2 Add `opencatalogi:directory:broadcast` (REQ-IMC-001). Verify: `tests/Unit/Command/DirectoryBroadcastCommandTest.php`.

## 2. Retention

- [ ] 2.1 Give `RetentionService::evaluate()` a dry-run mode and add `opencatalogi:retention:evaluate` with `--apply` (REQ-IMC-002). Verify: `tests/Unit/Service/RetentionServiceTest.php` asserts nothing is saved in dry run; `tests/Unit/Command/RetentionEvaluateCommandTest.php`.

## 3. Woo readiness and cache

- [ ] 3.1 Add `opencatalogi:woo:readiness` with exit 1 on a failing verdict (REQ-IMC-003). Verify: `tests/Unit/Command/WooReadinessCommandTest.php` with a pass and a fail report.
- [ ] 3.2 Add `opencatalogi:catalog:cache` with `rebuild` and `clear` for one slug or `--all` (REQ-IMC-001). Verify: `tests/Unit/Command/CatalogCacheCommandTest.php`.

## 4. Registration and docs

- [ ] 4.1 Register the five commands in `appinfo/info.xml` (REQ-IMC-001). Verify: `occ list opencatalogi` on a dev instance shows all seven commands.
- [ ] 4.2 Document the commands with examples in `docs/`. Verify: `npm run lint`.
