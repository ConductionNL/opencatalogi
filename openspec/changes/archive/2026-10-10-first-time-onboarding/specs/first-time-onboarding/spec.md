# first-time-onboarding Specification

**Status**: planned
**Scope**: opencatalogi
**OpenSpec changes**:
- first-time-onboarding

## Purpose

Give a first-time OpenCatalogi user a guided path from an empty, unconfigured
install to a published, discoverable catalog. A declarative **setup wizard**
(ADR-042) gates the app shell until the publishing prerequisites are configured,
and a declarative **walkthrough tour** (ADR-043) then teaches the publishing
journey (create a catalog → add a publication → publish via schema-RBAC →
discover via the Directory / DCAT-AP output). Both are declared as manifest-only
configuration (`manifest.setup` + `manifest.walkthrough`); OpenCatalogi adds no
onboarding code.

## ADDED Requirements

### Requirement: Setup wizard gates the shell on required publishing configuration (ONB-001)

The system MUST declare a `manifest.setup` block (block-on-required) whose
required step verifies that OpenCatalogi's publishing prerequisites — the
catalog register and catalog schema, the publication register, and the listing
register — are configured before the app shell is usable. The wizard MUST track
completion via a `completionConfigKey` of `onboarding_completed_version`, and
MUST NOT include any server-side seed (`run-action`) step.

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

## Non-Functional Requirements

- **Performance:** Onboarding is declarative manifest data only — it adds no API
  call, no backend work, and no measurable shell-render cost beyond reading the
  manifest the app already loads.
- **Accessibility:** The walkthrough overlay MUST be WCAG 2.1 AA compliant
  (keyboard operable, focus management, screen-reader announcements) as provided
  by the shared `CnWalkthrough` engine (ADR-043).
- **Internationalization:** Dutch and English MUST be supported; source strings
  are English literals (ADR-007).

## Acceptance Criteria

- `manifest.setup` declares `welcome`, required `config-check`, `catalog-scope`,
  and `done` steps with `completionConfigKey: onboarding_completed_version` and
  no `run-action` step.
- `manifest.walkthrough` declares one tour with the eight journey steps and the
  documented `advanceOn` conditions.
- A "Restart tutorial" menu entry replays the tour.
- `validate-manifest` passes after the blocks are added.
- The tour is live-verified end to end on :8080.

## Notes

- ADR-042 (first-time setup wizard) and ADR-043 (product walkthrough engine)
  define the shared, manifest-driven engines reused here.
- A server-side privileged seed action (ADR-042 `run-action`) is deliberately
  out of scope and deferred to a follow-up CODE spec.
