# Tasks: setup-wizard-server-contract

## Implementation Tasks

### Task 1: Unify the national directory URL behind one constant + config key
- **spec_ref**: `openspec/changes/setup-wizard-server-contract/specs/first-time-onboarding/spec.md#requirement-default-directory-url-single-source-of-truth-onb-008`
- **files**: `lib/AppInfo/Application.php`, `lib/Service/DirectoryService.php`, `src/modals/directory/AddDirectoryModal.vue`, `lib/Controller/UiController.php`, `tests/Unit/Service/DirectoryServiceTest.php`
- **acceptance_criteria**:
  - GIVEN no override WHEN the default directory is resolved THEN it equals `Application::DEFAULT_DIRECTORY_URL` and no source file outside the constant hardcodes the literal
  - GIVEN `default_directory_url` is set WHEN resolved THEN the override is used
  - GIVEN the Add-Directory modal opens THEN its default comes from initial state, not a literal
- [x] Implement
- [x] Test

### Task 2: SetupController status endpoint (derived, route ahead of catalog wildcard)
- **spec_ref**: `openspec/changes/setup-wizard-server-contract/specs/first-time-onboarding/spec.md#requirement-setup-server-contract-endpoints-onb-005`
- **files**: `lib/Controller/SetupController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a running instance WHEN GET `/api/setup/status` THEN `200` + `{version,completed,steps}` (NOT the catalog-wildcard "catalog 'setup' does not exist")
  - GIVEN auto-wired registers WHEN status is fetched THEN `config-check.done` is true
  - GIVEN each step THEN its `done` is computed from real config/object/listing state, not stored
- [x] Implement
- [x] Test

### Task 3: SetupController config endpoint (admin/CSRF app-config write)
- **spec_ref**: `openspec/changes/setup-wizard-server-contract/specs/first-time-onboarding/spec.md#requirement-setup-server-contract-endpoints-onb-005`
- **files**: `lib/Controller/SetupController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an admin WHEN POST `/api/setup/config` `{default_catalog_scope:"public"}` THEN the value is persisted and `catalog-scope` reports done
  - GIVEN a non-admin WHEN POST `/api/setup/config` THEN it is rejected by the admin guard
- [x] Implement
- [x] Test

### Task 4: SetupController action endpoint (reload-settings, create-first-catalog, connect-federation, complete)
- **spec_ref**: `openspec/changes/setup-wizard-server-contract/specs/first-time-onboarding/spec.md#requirement-create-first-catalog-privileged-action-onb-006`
- **files**: `lib/Controller/SetupController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN missing registers WHEN `reload-settings` runs THEN `loadSettings()` re-imports and `config-check` becomes done
  - GIVEN a chosen scope and no catalog WHEN `create-first-catalog` runs THEN a catalog object is created (`_rbac:false`) with that scope and `create-catalog` becomes done
  - GIVEN the federation step WHEN `connect-federation` runs THEN `syncDirectory(DEFAULT_DIRECTORY_URL)` syncs now; an unreachable directory returns a non-fatal skippable error
  - GIVEN completion WHEN `complete` runs THEN `onboarding_completed_version` is written
  - GIVEN a non-admin THEN every action is rejected by the admin guard
- [x] Implement
- [x] Test

### Task 5: Rework manifest.setup to the contract + bump versions + l10n
- **spec_ref**: `openspec/changes/setup-wizard-server-contract/specs/first-time-onboarding/spec.md#requirement-setup-wizard-gates-the-shell-on-required-publishing-configuration-onb-001`
- **files**: `src/manifest.json`, `appinfo/info.xml`, `l10n/*.js`
- **acceptance_criteria**:
  - GIVEN the setup block THEN steps are welcome → config-check (non-blocking, reload-settings run-action) → catalog-scope → create-catalog (run-action) → connect-federation (run-action, optional/skippable) → done (summary, healthCheck)
  - GIVEN the block THEN `setup.version`, the manifest `version`, and `info.xml` `<version>` are bumped (cache-bust)
  - GIVEN new step strings THEN English source literals exist in `l10n/en.js` (+ nl) via `scripts/l10n-ai.js`; `validate-manifest` passes
- [x] Implement
- [x] Test

### Task 6: PHPUnit coverage for SetupController
- **spec_ref**: `openspec/changes/setup-wizard-server-contract/specs/first-time-onboarding/spec.md#requirement-setup-server-contract-endpoints-onb-005`
- **files**: `tests/Unit/Controller/SetupControllerTest.php`
- **acceptance_criteria**:
  - GIVEN unit tests THEN status derivation (each step done/undone), config persistence, and each action (incl. federation-failure path + admin-guard) are covered and pass
- [x] Implement
- [x] Test

## Verification
- All tasks checked off
- `openspec validate setup-wizard-server-contract --strict` passes
- Live: `curl /apps/opencatalogi/api/setup/status` on :8080 returns `200` JSON, not the catalog-wildcard error
- Hydra gates pass: route-auth, route-reachability, semantic-auth, no-admin-idor, spec-coverage, spdx-headers
- Manual: wizard auto-passes config-check on the wired dev instance; create-catalog + connect-federation work end to end

## Tests (company-wide ADR-009)
- PHPUnit unit tests for `SetupController` (status derivation, config write, each action) — Task 6
- Newman/Postman tests for the three `/api/setup/*` endpoints
- Browser test (Playwright MCP): wizard opens, config-check is not asked on the wired instance, create-catalog + connect-federation steps complete
- All tests pass (`composer test`, `newman run`)

## Documentation (company-wide ADR-010)
- Update `docs/` setup/onboarding page to describe the working wizard (create first catalog + connect to the federated network)
- Capture a screenshot of the reworked wizard and commit to `docs/images/`

## i18n (company-wide ADR-005)
- English (`en`) source literals + Dutch (`nl`) translations for new manifest step strings, added via `scripts/l10n-ai.js`; `npm run check:l10n` clean
