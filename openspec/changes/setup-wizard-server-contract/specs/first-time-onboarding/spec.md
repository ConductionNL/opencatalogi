# first-time-onboarding Specification

**Status**: in-progress
**Scope**: opencatalogi
**OpenSpec changes**:
- first-time-onboarding
- setup-wizard-server-contract

## Purpose

Implement the ADR-042 server-side setup contract that the `manifest.setup` block
(introduced config-only by `first-time-onboarding`) declares but had no backend
for, and turn the wizard from "type the register/schema IDs" into a guided path
that auto-passes when the registers are already wired and instead helps the user
**create their first catalog** and **connect to the federated network**. This
delta adds the `SetupController` status/config/action endpoints, reworks the
`config-check` step to be auto-satisfied and non-blocking, adds `create-catalog`
and `connect-federation` `run-action` steps, and unifies the national directory
URL behind a single constant/config key.

## MODIFIED Requirements

### Requirement: Setup wizard gates the shell on required publishing configuration (ONB-001)

The system MUST declare a `manifest.setup` block whose `config-check` step
verifies that OpenCatalogi's publishing prerequisites — the catalog register and
catalog schema, the publication register, and the listing register — are
configured. The step MUST be **auto-satisfied from real app-config state by the
server** (see ONB-005): on an install where those keys are already wired (the
normal case, set on install by `InitializeSettings`), the step reports `done` and
does NOT block the shell or ask the user to enter register/schema IDs. The step
MUST only surface — and only then gate — when the prerequisites are genuinely
missing (e.g. OpenRegister was enabled after OpenCatalogi), in which case it MUST
be remediable via a `reload-settings` `run-action` rather than manual ID entry.
The wizard MUST track completion via a `completionConfigKey` of
`onboarding_completed_version`. (Previously: the `config-check` step was a
required `config-fields` form and the block MUST NOT include any `run-action`
step — both constraints are lifted by the server contract.)

#### Scenario: Auto-wired instance is not gated on config-check
- GIVEN an OpenCatalogi install where `catalog_register`, `catalog_schema`,
  `publication_register` and `listing_register` are all set (the install-time
  default)
- WHEN the user opens the app and the wizard fetches `GET /api/setup/status`
- THEN the `config-check` step is reported `done` by the server
- AND the shell is not blocked on it
- AND the user is never asked to type a register or schema ID

#### Scenario: Genuinely unconfigured instance is gated and remediated by reload
- GIVEN an install where one of the required register/schema keys is empty
  (OpenRegister enabled after OpenCatalogi)
- WHEN the user opens the app
- THEN the `config-check` step reports `done: false` and gates the shell
- AND the user can run the `reload-settings` action which re-imports the register
  configuration and sets the keys
- AND on success the step becomes `done` and the shell unlocks

## ADDED Requirements

### Requirement: Setup server contract endpoints (ONB-005)

The system MUST implement the ADR-042 §4 server contract via a `SetupController`:
`GET /api/setup/status`, `POST /api/setup/config`, and
`POST /api/setup/action/{actionId}`. `GET /api/setup/status` MUST return
`{ version, completed, steps }` where each step's `done` is computed
server-side from real state (config keys, object existence, listing presence),
never from a stored flag, and `completed` is true only when
`onboarding_completed_version` equals the manifest `setup.version`.
`POST /api/setup/config` MUST persist the posted app-config keys.
`POST /api/setup/action/{actionId}` MUST run the named privileged action.
All three routes MUST be registered so that the path segment `setup` resolves to
`SetupController` and is never captured by the `/api/{catalogSlug}` wildcard.
Config-write and action endpoints MUST be admin-authorized and CSRF-guarded.

#### Scenario: Status endpoint resolves and reports per-step state
- GIVEN a running OpenCatalogi instance
- WHEN a client requests `GET /apps/opencatalogi/api/setup/status`
- THEN the response is `200` with a JSON body `{ version, completed, steps }`
- AND it is NOT the catalog-wildcard error "catalog 'setup' does not exist"
- AND each declared setup step appears in `steps` with a server-computed `done`

#### Scenario: Config endpoint persists posted keys
- GIVEN an admin user in the setup wizard
- WHEN the wizard POSTs `{ default_catalog_scope: "public" }` to
  `POST /api/setup/config`
- THEN the value is written to OpenCatalogi app-config
- AND a subsequent `GET /api/setup/status` reflects the `catalog-scope` step as `done`

#### Scenario: Non-admin cannot write config or run actions
- GIVEN a non-admin authenticated user
- WHEN they POST to `/api/setup/config` or `/api/setup/action/{actionId}`
- THEN the request is rejected by the admin authorization guard

### Requirement: Create-first-catalog privileged action (ONB-006)

The system MUST expose a `create-first-catalog` action on
`POST /api/setup/action/{actionId}` that creates one catalog object in the
configured catalog register/schema via the OpenRegister abstraction with system
privileges (`_rbac:false`, per ADR-042 §4 / ADR-022), applying the
`default_catalog_scope` chosen earlier in the wizard. The corresponding
`create-catalog` setup step MUST report `done` once at least one catalog object
exists.

#### Scenario: Action creates a catalog and marks the step done
- GIVEN the user has chosen a `default_catalog_scope` and no catalog exists yet
- WHEN they run the `create-first-catalog` action
- THEN a catalog object is created in the configured catalog register/schema with
  that scope
- AND `GET /api/setup/status` reports the `create-catalog` step as `done`

#### Scenario: Step is already done when a catalog exists
- GIVEN an instance that already has at least one catalog object
- WHEN the wizard fetches status
- THEN the `create-catalog` step is reported `done` without running the action

### Requirement: Connect-to-federation privileged action (ONB-007)

The system MUST expose a `connect-federation` action that synchronizes the
instance with the national OpenCatalogi directory **immediately** (rather than
waiting for `doCronSync`) by calling `DirectoryService::syncDirectory()` against
the default directory URL. The corresponding `connect-federation` setup step MUST
be optional (never gates the shell, so it is always skippable), and MUST report
`done` when the default directory is a known listing. A sync failure (unreachable
or slow directory) MUST surface as a non-fatal step error that the user can skip.

#### Scenario: Action syncs the national directory now
- GIVEN the user reaches the optional `connect-federation` step
- WHEN they run the `connect-federation` action
- THEN `syncDirectory()` runs against the default directory URL and imports its
  listings
- AND the step is reported `done`

#### Scenario: Step is skippable and survives a sync failure
- GIVEN the national directory is unreachable
- WHEN the user runs `connect-federation`
- THEN the step surfaces a non-fatal error
- AND the user can skip the step and complete the wizard
- AND `doCronSync` still federates later on its normal schedule

### Requirement: Default directory URL single source of truth (ONB-008)

The system MUST define the national OpenCatalogi directory URL
(`https://directory.opencatalogi.nl/apps/opencatalogi/api/directory`) as a single
`Application::DEFAULT_DIRECTORY_URL` constant, overridable by a
`default_directory_url` app-config key (falling back to the constant when unset),
and MUST source every reference — `DirectoryService` cron/sync defaults, the
"Synchronize Directory" modal default, and the `connect-federation` action — from
it, replacing the previously hardcoded copies.

#### Scenario: All references resolve to the single source
- GIVEN no `default_directory_url` override is set
- WHEN `doCronSync`, `syncDirectory`, the Add-Directory modal, and the
  `connect-federation` action resolve the default directory
- THEN each uses `Application::DEFAULT_DIRECTORY_URL`
- AND no source file outside that constant hardcodes the literal directory URL

#### Scenario: Admin override is honoured
- GIVEN an admin sets `default_directory_url` to their own directory endpoint
- WHEN the default directory is resolved
- THEN the configured override is used instead of the constant

## Non-Functional Requirements

- **Performance:** `GET /api/setup/status` MUST complete in well under a second —
  it reads a handful of app-config keys plus a bounded object-existence check; it
  MUST NOT perform the federation sync (that is an explicit `run-action`).
- **Accessibility:** The wizard chrome is provided by the shared `CnSetupWizard`
  engine (WCAG 2.1 AA); this change adds no new client UI of its own.
- **Internationalization:** Dutch and English MUST be supported; new manifest
  step strings are authored as English literal source strings (ADR-007).

## Acceptance Criteria

- `SetupController` implements `GET /api/setup/status`, `POST /api/setup/config`,
  `POST /api/setup/action/{actionId}` with admin/CSRF posture matching each verb.
- `GET /api/setup/status` live-returns `200` + `{version,completed,steps}` on
  :8080 (not the catalog-wildcard error).
- The `config-check` step auto-passes on an auto-wired instance and only gates a
  genuinely unconfigured one.
- `create-first-catalog` and `connect-federation` actions work end to end and
  flip their steps to `done`.
- The default directory URL is defined once and all 8 prior call sites resolve to it.
- PHPUnit covers status derivation, config persistence, and each action.
- `validate-manifest` passes after the setup block rework.

## Notes

- ADR-042 §4 (server-side setup contract — privileged `_rbac:false` action path,
  admin/CSRF) is the governing decision; ADR-040 (health check reused by the
  `done` summary), ADR-022 (catalog creation via OpenRegister abstraction).
- Depends on `first-time-onboarding`, which introduced the `manifest.setup` block
  this change wires to a backend and which deferred exactly this server contract.
