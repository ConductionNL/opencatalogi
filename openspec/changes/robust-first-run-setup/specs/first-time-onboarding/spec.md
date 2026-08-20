# first-time-onboarding Specification

**Status**: in-progress
**Scope**: opencatalogi
**OpenSpec changes**:
- first-time-onboarding
- setup-wizard-server-contract
- robust-first-run-setup

## Purpose

Close three first-run robustness gaps left open after `setup-wizard-server-contract`:
(1) the register/schema auto-wiring that `config-check` (ONB-005) already
assumes is "set on install by `InitializeSettings`" does not actually run on
a clean `app:install`/`app:enable`, because `InitializeSettings` is only
registered as a `<post-migration>` repair step; (2) the optional,
never-blocking nature of the `connect-federation` step (ONB-007) needs to be
an explicit, tested contract rather than an implicit property of the current
implementation; and (3) OpenCatalogi's AppHost controller wiring (ADR-040)
constructs OpenRegister classes that exist only in OpenRegister >= 2.0.0, so
on an older OpenRegister every page fatal-500s — a runtime compatibility
guard must degrade gracefully instead.

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

The system MUST additionally guarantee that "set on install by
`InitializeSettings`" is true in practice, not just assumed: because Nextcloud's
`<post-migration>` repair-step pipeline does NOT run during `occ app:install` /
`occ app:enable`, the application MUST run a guarded, idempotent boot-time
fallback (`Application::boot()`) that invokes the same settings-loading logic
`InitializeSettings` uses, exactly once per install, whenever OpenRegister is
installed and the resolver keys are not yet wired. The fallback MUST be
guarded by a persisted flag so it does not re-run its settings-loading call on
every request once it has succeeded, and MUST leave the flag unset on failure
so the next request retries rather than permanently giving up.

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

#### Scenario: Clean install auto-wires resolver keys without any manual step
- GIVEN a truly-clean instance where OpenRegister is enabled first and
  OpenCatalogi is then enabled via `occ app:enable opencatalogi` (no
  `occ maintenance:repair` and no wizard action has run yet)
- WHEN any authenticated request boots the application
- THEN the boot-time fallback detects that the resolver keys are unset and the
  guard flag is not yet set
- AND it runs the same settings-loading logic as `InitializeSettings`
- AND on success it persists the guard flag so the fallback does not run its
  settings-loading call again on subsequent requests
- AND the very next `GET /api/setup/status` call reports `config-check` as
  `done` without the user running `reload-settings` or an operator running
  `occ maintenance:repair`

#### Scenario: Boot-time fallback is a no-op once the guard flag is set
- GIVEN an instance where the boot-time fallback has already run successfully
  once (the guard flag is set)
- WHEN a subsequent request boots the application
- THEN the fallback does not re-invoke the settings-loading logic
- AND no additional container resolution or register-import work happens on
  that request beyond the guard-flag read

#### Scenario: Boot-time fallback self-heals after a transient failure
- GIVEN an instance where the boot-time fallback's settings-loading call
  threw an exception on a prior request (e.g. OpenRegister briefly
  unavailable)
- WHEN the exception is caught
- THEN the guard flag remains unset
- AND the next request's boot re-attempts the settings-loading call rather
  than permanently skipping it

### Requirement: Connect-to-federation privileged action (ONB-007)

The system MUST expose a `connect-federation` action that synchronizes the
instance with the national OpenCatalogi directory **immediately** (rather than
waiting for `doCronSync`) by calling `DirectoryService::syncDirectory()` against
the default directory URL. The corresponding `connect-federation` setup step MUST
be optional (never gates the shell, so it is always skippable), and MUST report
`done` when the default directory is a known listing. A sync failure (unreachable
or slow directory) MUST surface as a non-fatal step error that the user can skip.

The system MUST guarantee this optionality holds even when the national
directory returns zero listings (an empty central directory) or is
temporarily unreachable: `connect-federation`'s per-step `done` state and any
sync failure MUST NOT be a factor in computing the wizard's overall
`completed` state or in writing `onboarding_completed_version`. Completion
MUST be derivable from the required steps alone (`config-check`,
`catalog-scope`, `create-catalog`), so the wizard can always reach "All set"
regardless of the directory's availability or contents. This MUST be covered
by an automated regression test that fails if a future change makes
`completed` or `onboarding_completed_version` depend on
`connect-federation`'s outcome.

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

#### Scenario: Wizard completes when the central directory returns zero listings
- GIVEN the national directory is reachable but currently has zero listings to
  offer ("0 new listings")
- WHEN the user runs `connect-federation` and then proceeds through the wizard
- THEN the `connect-federation` step may report `done: false`
- AND `GET /api/setup/status`'s `completed` field is still `true` once
  `config-check`, `catalog-scope` and `create-catalog` are all `done`
- AND `onboarding_completed_version` is set, so the wizard does not re-show

#### Scenario: Regression guard — completion never depends on federation outcome
- GIVEN a request to `GET /api/setup/status` where `connect-federation`'s
  computed `done` is `false` (directory unreachable or empty) but every
  required step is `done`
- WHEN the response's `completed` field and the `create-first-catalog`
  action's completion write are inspected
- THEN neither is derived from, nor blocked by, the `connect-federation` step
  or `DirectoryService::syncDirectory()`'s outcome

## ADDED Requirements

### Requirement: OpenRegister AppHost compatibility guard (ONB-009)

The system MUST guard its AppHost controller wiring (ADR-040) against a
too-old OpenRegister at runtime. `Application::register()` registers services
that construct OpenRegister's AppHost generic controllers
(`OCA\OpenRegister\AppHost\Controller\GenericDashboardController`,
`GenericPreferencesController`, `GenericHealthController`,
`GenericMetricsController`), which exist only in OpenRegister >= 2.0.0-beta.3.
Because Nextcloud's `info.xml` cannot express an app-to-app minimum version,
the application MUST check `class_exists(...)` on the canonical AppHost marker
class (`OCA\OpenRegister\AppHost\Controller\GenericDashboardController`)
before wiring these services. When the class is ABSENT the system MUST skip
the AppHost service/controller wiring so no fatal "Class ... not found" error
is raised, MUST log a clear warning naming the missing class, and MUST
surface an admin-facing signal (e.g. a Nextcloud admin notification / notice)
stating that OpenRegister >= 2.0 is required. When the class is PRESENT the
system MUST wire the AppHost services exactly as before. In the too-old case
the app MUST still load a usable page (or a clear message), never a fatal 500.

#### Scenario: Too-old OpenRegister degrades gracefully instead of fatal-500
- GIVEN an instance where OpenRegister is installed but older than 2.0
  (e.g. 1.1.1), so
  `OCA\OpenRegister\AppHost\Controller\GenericDashboardController` does not
  exist
- WHEN a user opens any page under `/apps/opencatalogi/`
- THEN the app does NOT throw a fatal "Class ... not found" 500
- AND the AppHost service/controller wiring is skipped
- AND a warning is logged naming the missing OpenRegister AppHost class
- AND an admin-facing notice is shown stating OpenRegister >= 2.0 is required

#### Scenario: Compatible OpenRegister wires AppHost unchanged
- GIVEN an instance where OpenRegister >= 2.0 is installed, so the AppHost
  generic controller classes exist
- WHEN `Application::register()` runs
- THEN the AppHost services (Dashboard, Preferences, Health, Metrics) are
  registered exactly as before the guard was added
- AND no OpenRegister-version notice is shown
- AND `/apps/opencatalogi/` pages render normally

## Non-Functional Requirements

- **Performance:** `GET /api/setup/status` MUST complete in well under a second —
  it reads a handful of app-config keys plus a bounded object-existence check; it
  MUST NOT perform the federation sync (that is an explicit `run-action`). The
  boot-time settings fallback MUST cost no more than a single app-config read on
  requests after it has succeeded once.
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
- A clean `occ app:enable` (OpenRegister already enabled) leaves
  `config-check` reporting `done` on the very first `GET /api/setup/status`
  call with no operator or user action in between.
- The boot-time fallback runs its settings-loading call at most once per
  install (verified via the guard flag) and is a no-op on every subsequent
  request once successful.
- `create-first-catalog` and `connect-federation` actions work end to end and
  flip their steps to `done`.
- `completed` and `onboarding_completed_version` are provably independent of
  `connect-federation`'s outcome, covered by an automated regression test.
- The default directory URL is defined once and all 8 prior call sites resolve to it.
- On an OpenRegister older than 2.0 (missing the AppHost generic controller
  class), `/apps/opencatalogi/` loads without a fatal 500, logs a warning, and
  shows an "OpenRegister >= 2.0 required" admin notice; on OpenRegister >= 2.0
  the AppHost wiring is unchanged.
- PHPUnit covers status derivation, config persistence, each action, the
  boot-time fallback (first-run, no-op-when-set, no-op-when-OpenRegister-absent,
  retry-after-failure), the connect-federation-optionality regression case, and
  the AppHost compat guard (class-present wires; class-absent skips + notices,
  no fatal).
- `validate-manifest` passes after the setup block rework.

## Notes

- ADR-042 §4 (server-side setup contract — privileged `_rbac:false` action path,
  admin/CSRF) is the governing decision; ADR-040 (health check reused by the
  `done` summary), ADR-022 (catalog creation via OpenRegister abstraction).
- Depends on `first-time-onboarding`, which introduced the `manifest.setup` block
  this change wires to a backend and which deferred exactly this server contract.
- `robust-first-run-setup` closes the gap between ONB-001/ONB-005's stated
  assumption ("set on install by `InitializeSettings`") and the actual
  Nextcloud repair-step lifecycle, and converts ONB-007's already-correct
  runtime behaviour into an explicit, regression-tested contract.
- ONB-009's compat guard is the runtime substitute for a declarable
  app-to-app minimum-version constraint that Nextcloud `info.xml` lacks; it
  keys off the ADR-040 AppHost engine's marker class. Live production incident
  driving it: `directory.opencatalogi.nl` running OpenCatalogi 1.1.0-beta.4
  against OpenRegister 1.1.1 fatal-500s on every page.
