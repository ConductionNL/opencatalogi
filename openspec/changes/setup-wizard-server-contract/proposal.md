---
kind: code
depends_on: [first-time-onboarding]
---

# Proposal: setup-wizard-server-contract

## Summary

Implement the ADR-042 first-time-setup **server contract** that OpenCatalogi's
existing `manifest.setup` block (shipped config-only by `first-time-onboarding`)
declares but has no backend for, and rework the setup flow so it stops asking a
new user to confirm registers/schemas that are **already auto-wired on install**.
A new `SetupController` exposes `GET /api/setup/status`, `POST /api/setup/config`
and `POST /api/setup/action/{actionId}` (admin/CSRF, privileged `_rbac:false`
actions), the manifest's blocking "type the register/schema IDs" form becomes a
non-blocking prerequisite that the status endpoint auto-satisfies, and two new
`run-action` steps guide the user to **create their first catalog** and
**connect to the federated network** (the national directory
`directory.opencatalogi.nl`) — the two things genuinely missing from setup today.

## Motivation

`first-time-onboarding` added the `manifest.setup` block but explicitly deferred
the server-side `run-action`/controller to "a separate later program / follow-up
CODE spec" (its Resolved Decisions). This is that spec. Without the backend, the
declared wizard is broken in four observable ways on a live instance:

- `GET /apps/opencatalogi/api/setup/status` is **shadowed by the
  `/api/{catalogSlug}` wildcard** (`[a-z0-9-]+` matches `setup`) → returns
  *"catalog 'setup' does not exist"*. `useSetupStatus` silently degrades to
  "nothing done", so every required step shows unmet and the wizard blocks the
  shell.
- `CnSetupWizard` never seeds the `config-fields` from current config, so the
  register/schema fields render **blank** and the user is asked to *type numeric
  register/schema IDs by hand* — IDs that `InitializeSettings` →
  `SettingsService::updateObjectTypeConfiguration()` already wrote on install
  (verified live: `catalog_register=14`, `catalog_schema=54`,
  `publication_register=14`, `listing_register=14/55`, …).
- `POST /apps/opencatalogi/api/setup/config` returns **405** — even a
  hand-filled form cannot save.
- `onboarding_completed_version` is never written, so the wizard re-shows on
  every load.

Separately, the wizard never gets the user to **create a first catalog** or
**connect to the federated network** — the two actions a new OpenCatalogi user
actually needs. Those exist only in the separate walkthrough tour and the manual
"Synchronize Directory" modal. `doCronSync()` already injects the national
directory on every cron run, so federation happens eventually and invisibly; the
wizard should surface it and let the user sync **now**.

## Affected Projects
- [x] Project: `opencatalogi` — new `lib/Controller/SetupController.php`; three
  setup routes in `appinfo/routes.php` (ahead of the catalog wildcard); reworked
  `manifest.setup` block in `src/manifest.json`; `Application::DEFAULT_DIRECTORY_URL`
  constant + `default_directory_url` config key replacing 8 hardcoded copies;
  PHPUnit + l10n.

## Scope

### In Scope
- **`SetupController`** implementing the ADR-042 §4 contract:
  - `GET /api/setup/status` → `{ version, completed, steps }`, with each step's
    `done` computed **server-side from real state**: `config-check` done when all
    of `catalog_register`/`catalog_schema`/`publication_register`/`listing_register`
    are non-empty; `catalog-scope` done when `default_catalog_scope` is set;
    `create-catalog` done when ≥1 catalog object exists; `connect-federation` done
    when the default directory is a known listing OR was explicitly skipped.
    `completed` = `onboarding_completed_version` equals the manifest `setup.version`.
  - `POST /api/setup/config` → admin/CSRF; writes the posted app-config keys
    (backs the `choice` step and any config field).
  - `POST /api/setup/action/{actionId}` → admin/CSRF; runs privileged
    (`_rbac:false`) actions: `reload-settings` (re-run `loadSettings()`),
    `create-first-catalog` (create one catalog object via OpenRegister using
    `default_catalog_scope`), `connect-federation` (`syncDirectory()` against the
    default directory now), and completion writes `onboarding_completed_version`.
- **Routes** registered before the `/api/{catalogSlug}` wildcard, with explicit
  requirements so `setup` can never resolve as a catalog slug. Live-verified.
- **`manifest.setup` rework**: `welcome` (info) → `config-check` becomes a
  non-blocking prerequisite auto-satisfied by the status endpoint and backed by a
  `reload-settings` run-action (only surfaces when registers are genuinely
  missing, e.g. OpenRegister enabled after OpenCatalogi) → `catalog-scope`
  (choice, kept) → **new** `create-catalog` (run-action) → **new**
  `connect-federation` (run-action, optional/skippable) → `done` (summary,
  healthCheck). `setup.version` bumped; manifest + `info.xml` version bumped.
- **Default-directory unification (pre-existing fix)**: lift
  `https://directory.opencatalogi.nl/apps/opencatalogi/api/directory` to
  `Application::DEFAULT_DIRECTORY_URL` + overridable `default_directory_url`
  config key; repoint all 8 hardcoded call sites
  (`DirectoryService.php` ×3, `AddDirectoryModal.vue` ×4 via initial-state, the
  new federation action).
- PHPUnit for `SetupController`; l10n source strings for new manifest copy via
  `scripts/l10n-ai.js`; `@spec` annotations on new methods.

### Out of Scope
- Changes to the `@conduction/nextcloud-vue` `CnSetupWizard`/`useSetupStatus`
  engine — this change implements the server side the existing engine already
  calls; any config-field pre-seeding improvement in the shared engine is a
  separate nextcloud-vue change.
- The `manifest.walkthrough` tour (owned by `first-time-onboarding`/ADR-043) — untouched.
- Seeding example publications or themes — the wizard creates an empty first
  catalog only; content creation stays the user's job.

## Approach

`SetupController` reads/writes app-config via `IAppConfig` and delegates the two
privileged actions to the existing services (`SettingsService::loadSettings`,
`DirectoryService::syncDirectory`, and OpenRegister `ObjectService` for catalog
creation with `_rbac:false`, per ADR-022/ADR-042 §4). Status is derived, never
stored, so it always reflects real config + object state. The manifest stops
encoding register IDs as user input and instead encodes *intent* (reload if
missing; create a catalog; connect to the directory). Details in design.md.

## New Dependencies
None. The wizard engine ships in `@conduction/nextcloud-vue` (already a
dependency); all server work uses OpenCatalogi's existing services + OpenRegister.

## Impact
- `lib/Controller/SetupController.php` (new), `appinfo/routes.php` (3 routes),
  `lib/AppInfo/Application.php` (constant), `src/manifest.json` (setup block +
  version), `appinfo/info.xml` (version), `lib/Service/DirectoryService.php` +
  `src/modals/directory/AddDirectoryModal.vue` (de-hardcode URL), `lib/Controller/UiController.php`
  or initial-state provider (expose `default_directory_url` to the modal),
  `tests/Unit/Controller/SetupControllerTest.php` (new), `l10n/*.js`.
- Runtime: the setup wizard becomes functional — auto-passes when configured,
  blocks only when registers are genuinely missing, and adds working
  create-catalog + connect-federation actions.

## Cross-Project Dependencies
Runtime-only: OpenRegister (`ObjectService`, already a hard dependency) for
catalog creation and `loadSettings` import. No spec-level dependency beyond
`first-time-onboarding` (the manifest blocks this change wires up).

## Risks

### Risk 1: Route ordering — setup paths still shadowed by the catalog wildcard
**Severity:** High — **Mitigation:** Register the three `/api/setup/*` routes
before the `/api/{catalogSlug}` entries AND constrain the wildcard's `catalogSlug`
requirement so `setup` cannot match. Add a route-reachability assertion and a
live `curl /api/setup/status` returning `200` + JSON (not "catalog not found")
to the test plan before merge.

### Risk 2: Privileged actions create objects as the wrong tenant / bypass RBAC unsafely
**Severity:** Medium — **Mitigation:** Follow the `_rbac:false` system-context
path already used by `InitializeSettings` (ADR-042 §4); the actions are
admin-only + CSRF-guarded at the route (semantic-auth), and `create-first-catalog`
writes into the configured catalog register/schema for the current instance only.

### Risk 3: `connect-federation` blocks on a slow/unreachable national directory
**Severity:** Medium — **Mitigation:** The step is optional/skippable; the action
reuses `syncDirectory`'s existing SSRF guard + timeout, and a failure surfaces as
a non-fatal step error (the user can skip and rely on `doCronSync`).

### Risk 4: De-hardcoding the directory URL regresses cron/sync behaviour
**Severity:** Low — **Mitigation:** `DEFAULT_DIRECTORY_URL` keeps the exact same
literal as the current default; `default_directory_url` falls back to it when
unset. Existing `DirectoryServiceTest` fixtures are updated to reference the
constant, preserving the assertions.

## Rollback Strategy
Revert `src/manifest.json`'s setup block to the `first-time-onboarding` version,
delete `SetupController.php` + its routes, and revert the `DEFAULT_DIRECTORY_URL`
refactor (restore the inline literals). No schema, DB, or data migration is
involved, so removal is clean; auto-wiring on install is unaffected.
