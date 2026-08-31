# Tasks: admin-settings

## Deduplication Check

Before implementation, verify no overlap with existing OpenRegister services:
- `ConfigurationService::importFromApp()` — used, not duplicated
- `RegisterMapper::findAll()` — used, not duplicated
- `SchemaMapper::find()` — used, not duplicated
- Frontend `CnRegisterMapping` — consumed for the 7-type mapping UI, not rebuilt
- Frontend `CnVersionInfoCard` — consumed as first element on admin page, not rebuilt
- **Finding:** No duplication. This module is custom settings business logic (per ADR-001 "What apps SHOULD build").

---

## 1. Routes and Controller

### Task 1: Define settings routes and SettingsController scaffold

- **spec_ref:** `specs/admin-settings/admin-settings.md#req-set-001`, `#req-set-002`, `#req-set-007`
- **files:** `appinfo/routes.php`, `lib/Controller/SettingsController.php`
- **acceptance_criteria:**
  - GIVEN all 7 route entries are registered WHEN each endpoint is called THEN it routes to the correct `SettingsController` method and returns 200 or 403 per the auth annotation
- [ ] 1.1 Add the 7 settings routes to `appinfo/routes.php` (`GET /api/settings`, `POST /api/settings`, `GET /api/settings/load`, `GET /api/settings/publishing`, `POST /api/settings/publishing`, `GET /api/settings/version`, `POST /api/settings/import`)
- [ ] 1.2 Scaffold `SettingsController` with 7 methods: `index()`, `update()`, `load()`, `publishing()`, `savePublishing()`, `version()`, `import()`
- [ ] 1.3 Annotate write methods with `#[AuthorizedAdminSetting(Application::APP_ID)]`
- [ ] 1.4 Annotate read methods `index()`, `publishing()`, `version()` with `#[NoAdminRequired]`
- [ ] 1.5 Each method delegates entirely to `SettingsService`; controller body must not exceed ~10 lines per method
- [ ] 1.6 Add SPDX header `// SPDX-License-Identifier: EUPL-1.2` to both files

---

## 2. SettingsService — Configuration Read

### Task 2: Implement GET /api/settings response assembly

- **spec_ref:** `specs/admin-settings/admin-settings.md#req-set-001`, `#req-set-013`
- **files:** `lib/Service/SettingsService.php`
- **acceptance_criteria:**
  - GIVEN OpenRegister is installed WHEN `getSettings()` is called THEN it returns all 7 object type configs, `openRegisters: true`, and `availableRegisters` enriched with full schema objects
  - GIVEN OpenRegister is absent WHEN `getSettings()` is called THEN `openRegisters: false`, `availableRegisters: []`
- [ ] 2.1 Inject `IAppConfig`, `RegisterMapper`, `SchemaMapper`, `IAppManager` via constructor
- [ ] 2.2 Add `getSettings(): array` — reads all 21 `{type}_{field}` config keys plus 3 publishing keys
- [ ] 2.3 Detect OpenRegister availability via `IAppManager::isInstalled('openregister')` and minimum version check (0.1.7)
- [ ] 2.4 Call `RegisterMapper::findAll()` to get available registers; for each, call `SchemaMapper::find()` for each schema ID to build the enriched `schemas` array
- [ ] 2.5 Return the assembled array with `objectTypes`, `openRegisters`, `availableRegisters`, `configuration`
- [ ] 2.6 Add SPDX header to `SettingsService.php`

---

## 3. SettingsService — Configuration Write

### Task 3: Implement settings update logic

- **spec_ref:** `specs/admin-settings/admin-settings.md#req-set-002`
- **files:** `lib/Service/SettingsService.php`
- **acceptance_criteria:**
  - GIVEN a POST body with `catalog_schema: "10"` WHEN `updateSettings()` is called THEN `IAppConfig::setValueString('opencatalogi', 'catalog_schema', '10')` is called AND existing non-updated keys are preserved
- [ ] 3.1 Add `updateSettings(array $data): array` — iterates over allowed keys, calls `IAppConfig::setValueString()` for each present key, then returns `getSettings()`
- [ ] 3.2 Allowed keys: `{type}_schema` and `{type}_register` for all 7 types (14 writable keys)
- [ ] 3.3 Silently ignore any unknown keys in `$data` (do not throw for extra fields)

---

## 4. SettingsService — Configuration Import

### Task 4: Implement loadSettings and updateObjectTypeConfiguration

- **spec_ref:** `specs/admin-settings/admin-settings.md#req-set-003`, `#req-set-007`
- **files:** `lib/Service/SettingsService.php`
- **acceptance_criteria:**
  - GIVEN `publication_register.json` exists WHEN `loadSettings(force: false)` is called and `shouldLoadSettings()` is true THEN `ConfigurationService::importFromApp()` runs AND slug-to-ID mapping populates all 7 type config keys
- [ ] 4.1 Add `loadSettings(bool $force = false): array` — checks `shouldLoadSettings()` unless `force: true`, reads JSON from `lib/Settings/publication_register.json`, injects `x-openregister.sourceUrl` and `sourceType` if absent, calls `ConfigurationService::importFromApp()`
- [ ] 4.2 After import, call `updateObjectTypeConfiguration()` to match schema slugs to IDs and persist to `IAppConfig`
- [ ] 4.3 Return import result array with `registers`, `schemas`, `objects` counts
- [ ] 4.4 Catch any exception from `ConfigurationService`; log with `$this->logger->error()` and return a failure indicator — never propagate `$e->getMessage()` to callers

---

## 5. SettingsService — Version Tracking

### Task 5: Implement version comparison and shouldLoadSettings

- **spec_ref:** `specs/admin-settings/admin-settings.md#req-set-006`, `#req-set-010`
- **files:** `lib/Service/SettingsService.php`
- **acceptance_criteria:**
  - GIVEN `configuredVersion = "0.7.8"` and app version `"0.7.9"` WHEN `shouldLoadSettings()` is called THEN it returns `true`
  - GIVEN both versions are `"0.7.9"` WHEN `shouldLoadSettings()` is called THEN it returns `false`
- [ ] 5.1 Add `shouldLoadSettings(): bool` — compares app version (from `IAppManager` or `\OCP\App::getAppVersion()`) with `configuredVersion` from `IAppConfig` using `version_compare()`
- [ ] 5.2 Add `getVersionInfo(): array` — returns `['appVersion' => ..., 'configuredVersion' => ..., 'match' => ...]`
- [ ] 5.3 After successful import, call `IAppConfig::setValueString('opencatalogi', 'configuredVersion', $appVersion)` to persist the new version

---

## 6. SettingsService — Auto-configuration

### Task 6: Implement autoConfigure

- **spec_ref:** `specs/admin-settings/admin-settings.md#req-set-004`
- **files:** `lib/Service/SettingsService.php`
- **acceptance_criteria:**
  - GIVEN a register whose slug contains `"publication"` and schemas titled `"Catalog"`, `"Listing"`, etc. WHEN `autoConfigure()` is called THEN all 7 `{type}_register` and `{type}_schema` keys are set to the matching IDs
- [ ] 6.1 Add `autoConfigure(): array` — calls `RegisterMapper::findAll()` and finds the register whose slug contains `"publication"`
- [ ] 6.2 For each of the 7 object types, search the register's schemas for one whose title (case-insensitive) matches the type name
- [ ] 6.3 For each match found, write `{type}_register` and `{type}_schema` to `IAppConfig`; skip types with no match without error
- [ ] 6.4 Return a summary array of which types were configured and which were skipped

---

## 7. SettingsService — Publishing Options

### Task 7: Implement publishing options read and write

- **spec_ref:** `specs/admin-settings/admin-settings.md#req-set-008`, `#req-set-009`
- **files:** `lib/Service/SettingsService.php`
- **acceptance_criteria:**
  - GIVEN no prior publishing option set WHEN `getPublishingOptions()` is called THEN all three flags are `false`
  - GIVEN `{"auto_publish_attachments": true}` WHEN `updatePublishingOptions()` is called THEN only `auto_publish_attachments` changes; others retain previous values
- [ ] 7.1 Add `getPublishingOptions(): array` — reads `auto_publish_attachments`, `auto_publish_objects`, `use_old_style_publishing_view` from `IAppConfig`, casting the stored string to bool (default `false`)
- [ ] 7.2 Add `updatePublishingOptions(array $data): array` — for each of the 3 allowed keys present in `$data`, calls `IAppConfig::setValueString()` with `"true"` or `"false"`, then returns `getPublishingOptions()`

---

## 8. InitializeSettings Repair Step

### Task 8: Implement IRepairStep for app install/upgrade

- **spec_ref:** `specs/admin-settings/admin-settings.md#req-set-011`
- **files:** `lib/Migration/InitializeSettings.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria:**
  - GIVEN OpenRegister is installed WHEN the repair step runs THEN `loadSettings(force: false)` is called AND the step outputs the import result counts
  - GIVEN OpenRegister is absent WHEN the repair step runs THEN a warning is output AND no exception is thrown
- [ ] 8.1 Create `InitializeSettings` implementing `IRepairStep`
- [ ] 8.2 In `getName()` return `"Initialize OpenCatalogi settings"`
- [ ] 8.3 In `run(IOutput $output)`: check `IAppManager::isInstalled('openregister')`; if yes call `SettingsService::loadSettings(force: false)` and output result counts; if no output a warning and return cleanly
- [ ] 8.4 Register in `Application::register()` via `$context->registerRepairStep(InitializeSettings::class)`
- [ ] 8.5 Add SPDX header to `InitializeSettings.php`

---

## 9. Database Migrations

### Task 9: Verify and document the 4 migration files

- **spec_ref:** `specs/admin-settings/admin-settings.md#req-set-014`
- **files:** `lib/Migration/Version6Date20241011085015.php`, `Version6Date20241129151236.php`, `Version6Date20241208222530.php`, `Version6Date20250419123213.php`
- **acceptance_criteria:**
  - GIVEN all 4 files exist WHEN Nextcloud migration system runs THEN they execute in date order without error AND no existing migration file has been modified
- [ ] 9.1 Verify all 4 migration files exist in `lib/Migration/` with correct class names matching file names
- [ ] 9.2 Confirm each class extends `SimpleMigrationStep` (or equivalent) and implements `changeSchema()` or `postSchemaChange()`
- [ ] 9.3 Confirm no existing migration file has been modified — git blame to verify original authorship
- [ ] 9.4 Add SPDX header to any migration file missing one

---

## 10. OpenCatalogiAdmin Settings Panel

### Task 10: Implement ISettings panel and settings entry point

- **spec_ref:** `specs/admin-settings/admin-settings.md#req-set-012`
- **files:** `lib/Settings/OpenCatalogiAdmin.php`, `webpack.config.js`, `appinfo/info.xml`
- **acceptance_criteria:**
  - GIVEN an admin user navigates to Nextcloud admin settings WHEN the OpenCatalogi section is opened THEN the settings page renders via `settings.js` without errors
- [ ] 10.1 Create `OpenCatalogiAdmin` implementing `ISettings`; `getSection()` returns `'opencatalogi'`; `getPriority()` returns an appropriate integer
- [ ] 10.2 In `getPanel()` use `IInitialState::provideInitialState()` for any values needed by the frontend
- [ ] 10.3 Register `settings` entry point in `webpack.config.js` pointing to `src/settings.js`
- [ ] 10.4 Register the admin settings section in `appinfo/info.xml` under `<settings><admin>…</admin></settings>`
- [ ] 10.5 Add SPDX header to `OpenCatalogiAdmin.php`

---

## 11. Frontend — AdminRoot.vue and Settings.vue

### Task 11: Implement the admin settings Vue components

- **spec_ref:** `specs/admin-settings/admin-settings.md#req-set-012`
- **files:** `src/settings.js`, `src/views/settings/AdminRoot.vue`, `src/views/settings/Settings.vue`
- **acceptance_criteria:**
  - GIVEN the admin settings page is opened WHEN `Settings.vue` mounts THEN it calls `GET /api/settings`, populates all dropdowns, and saves changes via `POST /api/settings`
  - GIVEN `AdminRoot.vue` is the settings entry root WHEN the vue-router initializes THEN `AdminRoot.vue` is NOT registered as a route
- [ ] 11.1 Create `src/settings.js` entry point that mounts `AdminRoot.vue` (not `App.vue`) — no vue-router, no Pinia store init for catalogs, etc.
- [ ] 11.2 Create `AdminRoot.vue` as a minimal wrapper that renders `Settings.vue`; add SPDX header
- [ ] 11.3 Create `Settings.vue` with:
  - `CnVersionInfoCard` as the first element (required, ADR-004)
  - `CnRegisterMapping` component for the 7 object type → register/schema mappings
  - `CnSettingsSection` section for publishing options with three `NcCheckboxRadioSwitch` toggles
  - `NcButton` "Save settings" that calls `POST /api/settings` via `@nextcloud/axios`
  - `NcButton` "Import configuration" that calls `POST /api/settings/import`
- [ ] 11.4 All user-visible strings use `t('opencatalogi', '...')` — no hardcoded Dutch or English text
- [ ] 11.5 All `await axios.post(...)` calls are wrapped in `try/catch` with `showError(t('opencatalogi', 'Failed to save settings'))` feedback
- [ ] 11.6 `Settings.vue` imports ALL used components in `components: {}` — no implicit global registration
- [ ] 11.7 Add SPDX header `<!-- SPDX-License-Identifier: EUPL-1.2 -->` to both `.vue` files

---

## 12. Translation Keys

### Task 12: Register all new i18n keys

- **spec_ref:** `specs/admin-settings/admin-settings.md` (non-functional requirements — translations)
- **files:** `l10n/en.js`, `l10n/nl.js` (via `scripts/l10n-ai.js add`)
- **acceptance_criteria:**
  - GIVEN all user-visible strings in `Settings.vue` and `AdminRoot.vue` WHEN `npm run check:l10n` is run THEN zero MISSING keys are reported
- [ ] 12.1 Run `node scripts/l10n-ai.js list-locales` to identify all locales
- [ ] 12.2 For each new `t('opencatalogi', '...')` key in the settings UI, run `node scripts/l10n-ai.js add "<key>" --value en="<key>" --value nl="<dutch translation>"`
- [ ] 12.3 Run `npm run check:l10n` — confirm zero MISSING and zero UNWRAPPED
- [ ] 12.4 Run `npm run find:unwrapped -- src/views/settings/` — confirm no unwrapped prose candidates remain

---

## 13. Verification

### Task 13: End-to-end verification

- **spec_ref:** `specs/admin-settings/admin-settings.md` (all requirements)
- **files:** N/A (manual testing and CI)
- **acceptance_criteria:**
  - GIVEN the full implementation WHEN tested end-to-end THEN all REQ-SET-001 through REQ-SET-014 scenarios pass
- [ ] 13.1 Restart Apache/php-fpm; run `GET /api/settings` with curl — verify shape matches spec
- [ ] 13.2 Test `POST /api/settings` with admin credentials — verify config persists; test with non-admin — verify 403
- [ ] 13.3 Test `POST /api/settings/import` with `force: true` — verify import counts reported; test without force when versions match — verify import skipped
- [ ] 13.4 Test `GET /api/settings/version` — verify `match` field is correct before and after import
- [ ] 13.5 Test repair step by simulating install: clear `configuredVersion` key, run `occ maintenance:repair` — verify settings are re-imported
- [ ] 13.6 Open Nextcloud admin settings → OpenCatalogi — verify `CnVersionInfoCard` renders first, dropdowns populate, save works
- [ ] 13.7 Run `npm run check:l10n` — zero MISSING, zero UNWRAPPED
- [ ] 13.8 Verify `AdminRoot.vue` is absent from the vue-router: `grep -rn "AdminRoot" src/router/` must return no results
- [ ] 13.9 Run all Hydra gates: `hydra-gate-route-auth`, `hydra-gate-semantic-auth`, `hydra-gate-spdx`, `hydra-gate-forbidden-patterns`, `hydra-gate-admin-router`, `hydra-gate-nc-input-labels`

## Verification checklist

- [ ] All tasks checked off
- [ ] `npm run check:l10n` clean
- [ ] `npm run find:unwrapped` clean
- [ ] All Hydra gates pass (no FAIL status)
- [ ] Manual end-to-end test completed
- [ ] Existing catalog browsing and publication workflows unaffected
