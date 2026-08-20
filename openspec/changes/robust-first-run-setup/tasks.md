# Tasks: robust-first-run-setup

## Implementation Tasks

### Task 1: Add the `settings_initialized` guarded boot fallback to `Application::boot()`
- **spec_ref**: `openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-setup-wizard-gates-the-shell-on-required-publishing-configuration-onb-001`
- **files**: `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN OpenRegister is installed and the `settings_initialized` app-config flag is unset WHEN `boot()` runs THEN it resolves `SettingsService` and calls `loadSettings(force: false)`
  - GIVEN the call succeeds WHEN `boot()` finishes THEN `settings_initialized` is set to `'true'` via `IAppConfig`
  - GIVEN `settings_initialized` is already `'true'` WHEN `boot()` runs THEN it does not resolve `SettingsService` or call `loadSettings()` again
  - GIVEN OpenRegister is not installed WHEN `boot()` runs THEN the fallback is a no-op (mirrors `InitializeSettings`'s own guard)
- [ ] Implement
- [ ] Test

### Task 2: Handle and log `loadSettings()` failure in the boot fallback without setting the guard flag
- **spec_ref**: `openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-setup-wizard-gates-the-shell-on-required-publishing-configuration-onb-001`
- **files**: `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN `loadSettings()` throws an exception WHEN `boot()` catches it THEN the exception is logged via the injected logger and the request continues normally
  - GIVEN the exception was caught WHEN `boot()` finishes THEN `settings_initialized` remains unset so the next request retries
- [ ] Implement
- [ ] Test

### Task 3: PHPUnit coverage for the boot-time settings fallback
- **spec_ref**: `openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-setup-wizard-gates-the-shell-on-required-publishing-configuration-onb-001`
- **files**: `tests/Unit/AppInfo/ApplicationTest.php`
- **acceptance_criteria**:
  - GIVEN the flag is unset and OpenRegister is installed WHEN `boot()` runs THEN `loadSettings()` is invoked exactly once and the flag is written
  - GIVEN the flag is already set WHEN `boot()` runs THEN `loadSettings()` is never invoked
  - GIVEN OpenRegister is absent WHEN `boot()` runs THEN `loadSettings()` is never invoked and the flag stays unset
  - GIVEN `loadSettings()` throws WHEN `boot()` runs THEN the flag stays unset and no exception escapes `boot()`
- [ ] Implement
- [ ] Test

### Task 4: Add a PHPUnit regression test proving `connect-federation` cannot gate wizard completion
- **spec_ref**: `openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-connect-to-federation-privileged-action-onb-007`
- **files**: `tests/Unit/Controller/SetupControllerTest.php`
- **acceptance_criteria**:
  - GIVEN `config-check`, `catalog-scope` and `create-catalog` are all `done` and `connect-federation`'s computed `done` is `false` WHEN `status()` is called THEN `completed` is `true`
  - GIVEN `DirectoryService::syncDirectory()` throws (unreachable directory) WHEN `connectFederation()` runs THEN the response is `{success: false, ...}` with HTTP 200, not a 5xx, and does not touch `onboarding_completed_version`
  - GIVEN the national directory returns zero listings WHEN `syncDirectory()` completes THEN the step remains skippable and `completed` is unaffected
- [ ] Implement
- [ ] Test

### Task 5: Add the `class_exists` OpenRegister-compatibility guard to `Application::register()`
- **spec_ref**: `openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-openregister-apphost-compatibility-guard-onb-009`
- **files**: `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN `OCA\OpenRegister\AppHost\Controller\GenericDashboardController` does not exist WHEN `register()` runs THEN the four AppHost `registerService(...)` calls (Health, Metrics, Dashboard, Preferences) are skipped and no fatal "Class not found" is raised
  - GIVEN the class is absent WHEN `register()` runs THEN a warning naming the missing class is logged and an admin-facing "OpenRegister >= 2.0 required" notice is registered
  - GIVEN the class exists WHEN `register()` runs THEN the four AppHost services are registered exactly as before the guard, with no notice
  - GIVEN OpenRegister is too old WHEN a user opens any `/apps/opencatalogi/` page THEN a usable page/message renders instead of a 500
- [ ] Implement
- [ ] Test

### Task 6: PHPUnit coverage for the AppHost compatibility guard
- **spec_ref**: `openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-openregister-apphost-compatibility-guard-onb-009`
- **files**: `tests/Unit/AppInfo/ApplicationTest.php`
- **acceptance_criteria**:
  - GIVEN the marker class is present WHEN `register()` runs THEN the AppHost services are registered on the registration context (assert via a spy/mock context) and no incompatibility notice is emitted
  - GIVEN the marker class is absent WHEN `register()` runs THEN the AppHost registrations are skipped, a warning is logged, the incompatibility notice/flag is set, and `register()` completes without throwing
- [ ] Implement
- [ ] Test

### Task 7: Add `@spec` traceability annotations for the touched methods
- **spec_ref**: `openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-setup-wizard-gates-the-shell-on-required-publishing-configuration-onb-001`
- **files**: `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN `boot()`'s docblock WHEN it is inspected THEN it carries an `@spec` tag pointing at this change's spec delta requirement ONB-001, replacing the now-inaccurate `@spec exclude` comment
  - GIVEN `register()`'s docblock WHEN it is inspected THEN it carries an `@spec` tag pointing at ONB-009 for the compat guard
- [ ] Implement
- [ ] Test

## Quality checklist

<!-- These are reminders for the builder, not tracked checkboxes.
     Keeping them as plain text avoids inflating the Hydra cap count. -->

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — Tasks 3, 4 and 6 above
- No new API endpoints and no new frontend UI, so Newman/Postman and Playwright coverage are N/A for this change
- All tests pass (`composer test`)
- The "OpenRegister >= 2.0 required" admin notice IS user-facing — its English source string must be added with a Dutch (`nl_NL`) translation (ADR-007); the `settings_initialized` app-config key is internal and never rendered
- Feature documentation update is N/A — this is an internal robustness fix with no new user-facing behaviour to document; the existing setup-wizard docs already describe the config-check and connect-federation steps accurately
- `openspec validate` passes
- `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes on `lib/AppInfo/Application.php`
- Hydra gate-16 (`hydra-gate-spec-coverage`) passes on the changed `boot()` and `register()` methods

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria — verify on a clean `occ app:enable openregister && occ app:enable opencatalogi` that `GET /api/setup/status` reports `config-check: done` with no manual repair/reload step
- [ ] Code review against spec requirements
