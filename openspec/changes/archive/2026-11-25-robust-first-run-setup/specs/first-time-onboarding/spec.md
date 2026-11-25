# first-time-onboarding Specification

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

#### Scenario: Unconfigured instance is gated on the required config-check step
- GIVEN a fresh OpenCatalogi install where the catalog register/schema,
  publication register, or listing register is not yet configured
- WHEN the user opens the app
- THEN the setup wizard opens and blocks the shell on the required
  `config-check` step
- AND the wizard does not allow completion until those config keys are set

#### Scenario: Configured instance passes the wizard
- GIVEN an OpenCatalogi install with the catalog register/schema, publication
  register and listing register all configured
- WHEN the user opens the app
- THEN the required `config-check` step is satisfied
- AND the wizard reaches the `done` summary step which runs a health check and
  links to Catalogs and the Directory
- AND `onboarding_completed_version` is recorded so the wizard does not re-gate

#### Scenario: No server-side seed action is declared
- GIVEN the `manifest.setup` block
- WHEN its steps are inspected
- THEN no step has `type: "run-action"`
- AND the wizard never writes OpenRegister objects from the browser

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
