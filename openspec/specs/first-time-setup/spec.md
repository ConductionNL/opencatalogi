# first-time-setup Specification

## Purpose
The first-time setup wizard gets a new OpenCatalogi install publishing: the administrator picks a default catalog scope, creates a first catalog and, optionally, loads example data from a card and connects the instance to the federated directory network. The server computes every step's state from real configuration and objects through `/api/setup/status`, runs the privileged steps as admin-only setup actions, and leaves the register import to the admin settings page instead of a wizard step.

## Requirements

### Requirement: Each example data card loads itself

The `demo-data` setup step MUST be a cards choice step with `loadAction: load-demo-data`. The setup wizard MUST NOT carry a separate run-action step that loads the picked dataset.

#### Scenario: The operator loads a dataset from its card

- GIVEN the setup wizard shows the example data cards
- WHEN the operator presses Load on a card
- THEN the wizard posts `{ "dataset": <card value> }` to `/api/setup/action/load-demo-data`
- AND the server loads that dataset
- AND the server records the dataset as the pick only after the load succeeds
- @e2e exclude the card and its spinner are CnSetupWizard UI, tested in nextcloud-vue; the posted body is covered by tests/Unit/Controller/SetupControllerTest.php

#### Scenario: An unknown dataset is refused

- GIVEN a dataset id that no card offers
- WHEN it is posted to `/api/setup/action/load-demo-data`
- THEN the server answers 400 with `success: false`
- AND nothing is loaded or stored
- @e2e exclude API contract without a browser path of its own; covered by tests/Unit/Controller/SetupControllerTest.php

#### Scenario: A call without a body keeps working

- GIVEN a dataset was stored through `/api/setup/config`
- WHEN `/api/setup/action/load-demo-data` is called without a body
- THEN the stored dataset is loaded
- @e2e exclude API contract without a browser path of its own; covered by tests/Unit/Controller/SetupControllerTest.php

#### Scenario: A failed load leaves the step open

- GIVEN the load of the posted dataset fails
- WHEN the server answers
- THEN the answer carries `success: false`
- AND no pick or decision is stored
- @e2e exclude needs a load that fails on a live instance; covered by tests/Unit/Controller/SetupControllerTest.php

### Requirement: Setup status reports every manifest step

`GET /api/setup/status` MUST report a `done` state for every step id in `manifest.setup.steps`.

#### Scenario: The status ids match the manifest

- GIVEN the OpenCatalogi manifest
- WHEN an administrator reads `/api/setup/status`
- THEN `steps` holds an entry for every manifest step id
- AND the retired load step is not reported
- @e2e exclude API contract without a browser path of its own; covered by tests/Unit/Controller/SetupControllerTest.php

### Requirement: Provisioning runs from the admin settings page

Provisioning, repair and register-import actions MUST NOT be wizard steps. The admin settings page MUST offer the configuration import. In OpenCatalogi that is the existing Reimport configuration button. The `reload-settings` setup action MUST keep working for runbooks and scripts.

#### Scenario: The administrator repairs the register from the admin page

- GIVEN an administrator on the OpenCatalogi admin settings page
- WHEN they press Reimport configuration
- THEN the page posts to `/api/settings/import`
- AND the publishing registers are imported again
- AND the result message is shown on the page
- @e2e exclude admin settings button calling an existing setup action; the action is covered by tests/Unit/Controller/SetupControllerTest.php

#### Scenario: The wizard no longer asks

- GIVEN the OpenCatalogi manifest
- WHEN the setup steps are read
- THEN none of `config-check` is a step
- @e2e exclude a manifest property; asserted by tests/Unit/Controller/SetupControllerTest.php

### Requirement: Setup server contract endpoints (ONB-005)

The system MUST implement the ADR-042 §4 server contract in a `SetupController`: `GET /api/setup/status`, `POST /api/setup/config` and `POST /api/setup/action/{actionId}`. The three routes MUST be registered ahead of the `/api/{catalogSlug}` wildcard so the path segment `setup` never resolves as a catalog.

`GET /api/setup/status` MUST answer a signed-in user with `{ version, completed, registersReady, datasets, steps }` and MUST refuse an anonymous caller with 401. Every step's `done` MUST be computed from real state (app config, catalog objects, listings), never from a stored per-step flag. `completed` MUST be true once every required step is satisfied: a default catalog scope is chosen and a catalog exists. The optional steps MUST NOT affect `completed`. `registersReady` MUST report whether the publishing register keys are set, as information and not as a step.

`POST /api/setup/config` MUST write only the whitelisted keys `default_catalog_scope`, `default_directory_url` and `demo_dataset`, and MUST ignore any other posted key. `POST /api/setup/action/{actionId}` MUST run the named action and MUST answer an unknown action id with 400. Both MUST be admin-authorised and CSRF-guarded. An action that fails MUST answer `success: false` with a message, so an optional step stays skippable. The `complete` action MUST write `onboarding_completed_version`, and `reload-settings` MUST re-run the register import for runbooks and scripts.

#### Scenario: Status endpoint resolves and reports per-step state

- GIVEN a running OpenCatalogi instance and a signed-in user
- WHEN the user requests `GET /apps/opencatalogi/api/setup/status`
- THEN the response is `200` with `{ version, completed, registersReady, datasets, steps }`
- AND it is NOT the catalog-wildcard error "catalog 'setup' does not exist"
- AND every manifest step appears in `steps` with a server-computed `done`
- @e2e exclude API contract without a browser path of its own; covered by tests/Unit/Controller/SetupControllerTest.php

#### Scenario: Completion follows the required steps

- GIVEN a default catalog scope is set and at least one catalog exists
- AND the federation steps are not done
- WHEN status is requested
- THEN `completed` is true
- @e2e exclude API contract; covered by tests/Unit/Controller/SetupControllerTest.php

#### Scenario: Config endpoint persists whitelisted keys only

- GIVEN an admin in the setup wizard
- WHEN the wizard posts `{ default_catalog_scope: "public", other_key: "x" }` to `POST /api/setup/config`
- THEN `default_catalog_scope` is written to app config and `other_key` is not
- AND a later status reports the `catalog-scope` step as `done`
- @e2e exclude API contract; covered by tests/Unit/Controller/SetupControllerTest.php

#### Scenario: Non-admin cannot write config or run actions

- GIVEN a signed-in user who is not an administrator
- WHEN they post to `/api/setup/config` or `/api/setup/action/{actionId}`
- THEN the admin authorisation guard rejects the request
- @e2e exclude enforced by the AuthorizedAdminSetting attribute through Nextcloud's middleware; no unit test asserts it

### Requirement: Create-first-catalog privileged action (ONB-006)

The system MUST expose a `create-first-catalog` action that creates one catalog object in the configured catalog register and schema through OpenRegister's `ObjectService` with system privileges (`_rbac: false`, `_multitenancy: false`, per ADR-042 §4 and ADR-022). The catalog MUST be `listed` when `default_catalog_scope` is `public`, MUST start in status `development`, and MUST be scoped to the configured publication register and schema when those are set. The action MUST answer `success: false` without creating anything when OpenRegister or the catalog register and schema are missing. When a catalog already exists it MUST create nothing and answer success. On success it MUST write `onboarding_completed_version`. The `create-catalog` step MUST report `done` once a catalog exists.

#### Scenario: Action creates a catalog and marks the step done

- GIVEN a chosen `default_catalog_scope` of `public` and no catalog yet
- WHEN the administrator runs `create-first-catalog`
- THEN a listed catalog in status `development` is created in the configured catalog register and schema
- AND status reports the `create-catalog` step as `done`
- @e2e exclude privileged action with a wizard button rendered by CnSetupWizard; covered by tests/Unit/Controller/SetupControllerTest.php

#### Scenario: Step is already done when a catalog exists

- GIVEN an instance that already has at least one catalog object
- WHEN the wizard fetches status
- THEN the `create-catalog` step is reported `done` without running the action
- @e2e exclude API contract; covered by tests/Unit/Controller/SetupControllerTest.php

### Requirement: Connect-to-federation privileged action (ONB-007)

The system MUST expose a `connect-federation` action that synchronises the instance with the default directory immediately, by calling `DirectoryService::syncDirectory()` on the default directory URL, and then announces this instance to that directory through the broadcast service. The answer MUST report how many listings were created and updated and whether the announcement succeeded. The `connect-federation` step MUST be optional and MUST report `done` when the default directory is a known listing. A sync failure MUST surface as a non-fatal `success: false` answer the administrator can skip; a failed announcement MUST NOT fail the action.

#### Scenario: Action syncs the national directory now

- GIVEN the administrator reaches the optional `connect-federation` step
- WHEN they run the action
- THEN `syncDirectory()` runs on the default directory URL and imports its listings
- AND the instance is announced to the directory
- AND the step is reported `done` once the directory is a known listing
- @e2e exclude needs a reachable national directory; covered by tests/Unit/Controller/SetupControllerTest.php

#### Scenario: Step is skippable and survives a sync failure

- GIVEN the national directory is unreachable
- WHEN the administrator runs `connect-federation`
- THEN the action answers `success: false` with a message that the sync runs later
- AND the administrator can skip the step and complete the wizard
- AND `doCronSync` still federates later on its normal schedule
- @e2e exclude needs an unreachable directory; covered by tests/Unit/Controller/SetupControllerTest.php

### Requirement: Sync-all-directories optional step (ONB-009)

The setup wizard MUST offer an optional `sync-all-directories` run-action step that runs `DirectoryService::doCronSync()` at once and answers how many directories were synchronised out of how many, and how many failed. A failure MUST answer `success: false` and stay skippable. The step MUST report `done` whenever `connect-federation` is done, because that guarantees at least one sync ran, and it MUST NOT affect `completed`.

#### Scenario: The administrator syncs every known directory

- GIVEN directories are registered on the instance
- WHEN the administrator runs `sync-all-directories`
- THEN every registered directory is synchronised
- AND the answer names the synchronised, total and failed counts
- @e2e exclude needs reachable peer directories; no unit test asserts this action yet

### Requirement: Default directory URL single source of truth (ONB-008)

The system MUST define the national OpenCatalogi directory URL (`https://directory.opencatalogi.nl/apps/opencatalogi/api/directory`) once, as `Application::DEFAULT_DIRECTORY_URL`, overridable by the `default_directory_url` app-config key and falling back to the constant when unset. `DirectoryService::getDefaultDirectoryUrl()` MUST resolve it for the cron sync, the manual sync and the `connect-federation` action. The Add-Directory modal MUST read it from initial state, keeping the literal only as the `loadState` fallback.

#### Scenario: All references resolve to the single source

- GIVEN no `default_directory_url` override is set
- WHEN the cron sync, `syncDirectory`, the Add-Directory modal and the `connect-federation` action resolve the default directory
- THEN each uses `Application::DEFAULT_DIRECTORY_URL`
- @e2e exclude a server-side constant read in DirectoryService::getDefaultDirectoryUrl(); the controller tests stub that method

#### Scenario: Admin override is honoured

- GIVEN an administrator sets `default_directory_url` to their own directory endpoint
- WHEN the default directory is resolved
- THEN the override is used instead of the constant
- @e2e exclude app-config resolution in DirectoryService::getDefaultDirectoryUrl(); no unit test asserts the override
