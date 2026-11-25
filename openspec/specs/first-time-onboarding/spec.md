# first-time-onboarding Specification

## Purpose
Give a first-time OpenCatalogi user a guided path from an empty, unconfigured
install to a published, discoverable catalog. A declarative **setup wizard**
(ADR-042) gates the app shell until the publishing prerequisites are configured,
and a declarative **walkthrough tour** (ADR-043) then teaches the publishing
journey (create a catalog → add a publication → publish via schema-RBAC →
discover via the Directory / DCAT-AP output). Both are declared as manifest-only
configuration (`manifest.setup` + `manifest.walkthrough`); OpenCatalogi adds no
onboarding code.

## Requirements

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

### Requirement: Setup wizard captures default catalog scope (ONB-002)

The system MUST include a `catalog-scope` choice step that records the default
catalog visibility/scope into an app-config key, reflecting that publishing in
OpenCatalogi is governed by schema-RBAC (not a `@self.published` flag) and that a
catalog scope is mandatory.

#### Scenario: User picks a default catalog scope
- GIVEN the user is in the setup wizard past the required `config-check` step
- WHEN the user selects a default catalog visibility/scope on the
  `catalog-scope` step
- THEN the chosen value is written to the bound app-config key
- AND the choice is available as the default scope for catalogs created later

### Requirement: Walkthrough auto-starts on a user's first visit and teaches the publishing journey (ONB-003)

The system MUST declare a `manifest.walkthrough` block with one tour, triggered
on a user's **first visit** (shown once per user, tracked via the engine's
seen-version), whose steps walk the publishing journey: welcome (center) →
go to Catalogs (nav-item, advance on route-match) → create a catalog (Add button
target `data-walkthrough-id="index-add"`, advance on object-created for the
catalog register) → go to Publications (nav-item, route-match) → add a
publication (Add button, advance on object-created for the publication schema) →
explain schema-RBAC publishing (manual / `allowManualNext`) → discover via the
Directory (nav-item, route-match) → done (center, pointing at the DCAT-AP
output). The tour MUST be restartable from a menu entry.

#### Scenario: Tour auto-starts on a user's first visit
- GIVEN setup is complete and the user has not seen the tour before
- WHEN the app shell renders
- THEN the walkthrough tour starts as a non-gating overlay on the `welcome` step
- AND it is not shown again on subsequent visits once seen or dismissed

#### Scenario: Create-catalog step advances when a catalog is created
- GIVEN the tour is on the `create-catalog` step spotlighting the Add button
  (`data-walkthrough-id="index-add"`) on the Catalogs index
- WHEN the user creates their first catalog
- THEN the step advances on the `object-created` event for the catalog register

#### Scenario: Add-publication step advances when a publication is created
- GIVEN the tour is on the `add-publication` step spotlighting the Add button on
  the Publications index
- WHEN the user creates a publication
- THEN the step advances on the `object-created` event for the publication schema

#### Scenario: Publish step explains schema-RBAC and allows manual advance
- GIVEN the tour reaches the `publish` step
- WHEN the step is shown
- THEN it explains that publishing is controlled by schema-RBAC (not a
  `@self.published` flag) and a catalog scope is mandatory
- AND the user can advance manually (`allowManualNext`)

#### Scenario: Discover step lands on the Directory and the tour ends on DCAT-AP
- GIVEN the tour is on the `discover` step targeting the Directory nav-item
- WHEN the user opens the Directory
- THEN the step advances on route-match
- AND the final `done` step points at the DCAT-AP harvest/output as how
  publications are discovered externally

#### Scenario: Tour is restartable from the menu
- GIVEN a user who has already seen or dismissed the tour
- WHEN they trigger the "Restart tutorial" menu entry
- THEN the tour restarts from its first step

### Requirement: Onboarding i18n source strings are English literals (ONB-004)

The system MUST author all setup-wizard and walkthrough step titles, bodies and
task lines as English literal source strings (ADR-007 / ADR-025), never Dutch as
the i18n key.

#### Scenario: All step strings are English source literals
- GIVEN the `manifest.setup` and `manifest.walkthrough` blocks
- WHEN the step `title`, `body` and `task` strings are inspected
- THEN every string is an English literal source string suitable as the i18n key
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

