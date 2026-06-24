# Tasks: first-time-onboarding

## Implementation Tasks

### Task 1: Add `setup` block to `src/manifest.json`
- **spec_ref**: `openspec/changes/first-time-onboarding/specs/first-time-onboarding/spec.md#req-001-setup-wizard-gates-the-shell-on-required-publishing-configuration`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the app opens THEN the wizard gates the shell on the required `config-check` step until catalog register/schema, publication register and listing register are configured
  - GIVEN the block WHEN inspected THEN steps are `welcome` (info) → required `config-check` (config-fields) → `catalog-scope` (choice) → `done` (summary), with `completionConfigKey: onboarding_completed_version` and NO `run-action` step
- [x] Add the `setup` block (welcome, required config-check, catalog-scope choice, done summary) to `src/manifest.json`, matching pipelinq's shape, with English literal i18n source strings

### Task 2: Add `walkthrough` block to `src/manifest.json`
- **spec_ref**: `openspec/changes/first-time-onboarding/specs/first-time-onboarding/spec.md#req-003-walkthrough-auto-starts-on-a-users-first-visit-and-teaches-the-publishing-journey`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN setup is complete and the user has not seen the tour WHEN the shell renders THEN the tour auto-starts on the `welcome` step, and not again once seen/dismissed
  - GIVEN the tour WHEN stepped through THEN it runs welcome → go-catalogs → create-catalog (`data-walkthrough-id="index-add"`, advance object-created catalog register) → go-publications → add-publication (advance object-created publication schema) → publish (schema-RBAC, `allowManualNext`) → discover (Directory, route-match) → done (DCAT-AP)
- [x] Add the `walkthrough` block (one tour, `first-visit` trigger, eight journey steps) to `src/manifest.json`, matching pipelinq's shape, with English literal i18n source strings
- [x] Add a "Restart tutorial" menu entry (`replay-walkthrough` action) to `manifest.menu`

### Task 3: Sync manifest schema copies and validate
- **spec_ref**: `openspec/changes/first-time-onboarding/specs/first-time-onboarding/spec.md#req-004-onboarding-i18n-source-strings-are-english-literals`
- **files**: `src/manifest.json`, synced manifest schema copy
- **acceptance_criteria**:
  - GIVEN the edited manifest WHEN `validate-manifest` runs THEN it passes
  - GIVEN the setup + walkthrough strings WHEN inspected THEN every title/body/task is an English literal source string
- [x] Sync the manifest schema copies and run `validate-manifest` until it passes

### Task 4: Live-verify onboarding on :8080
- **spec_ref**: `openspec/changes/first-time-onboarding/specs/first-time-onboarding/spec.md#req-002-setup-wizard-captures-default-catalog-scope-publish-is-schema-rbac`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN an unconfigured instance on :8080 WHEN the app opens THEN the wizard gates; GIVEN a configured instance THEN the wizard passes to `done`
  - GIVEN an empty catalog index WHEN the shell renders THEN the tour clicks through end to end (create catalog → add publication → discover via Directory)
- [ ] Live-verify the setup gating and the walkthrough tour end to end on :8080

## Quality checklist

- `manifest.setup` and `manifest.walkthrough` are valid against the v2 manifest schema (`validate-manifest` passes)
- All onboarding step titles/bodies/task lines are English literal source strings (ADR-007 / ADR-025) — never Dutch as the i18n key
- The walkthrough overlay is keyboard operable and screen-reader announced (inherited from `CnWalkthrough`, ADR-043)
- No PHP, Vue, controller, route, or schema changes were introduced (this is `kind: config`)
- No `run-action` / server-side seed step was added (deferred to a follow-up CODE spec)
- The setup gating and the tour are live-verified on :8080 before merge
- `openspec validate first-time-onboarding --strict` passes
