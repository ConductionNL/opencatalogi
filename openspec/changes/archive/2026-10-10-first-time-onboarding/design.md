# Design: first-time-onboarding

## Architecture Overview

OpenCatalogi already renders its shell from `src/manifest.json` via the
`@conduction/nextcloud-vue` manifest renderer (`CnAppRoot` / `CnPageRenderer`).
This change adds two new top-level declarative blocks to that manifest:

- `manifest.setup` — consumed by the shared `CnSetupWizard` / `useSetupStatus`
  engine (ADR-042). A new `CnAppRoot` setup phase gates the shell when a
  `required` step is unmet.
- `manifest.walkthrough` — consumed by the shared `CnWalkthrough` /
  `useWalkthrough` engine (ADR-043). After the setup phase clears, the tour runs
  as a non-gating spotlight overlay.

Both engines already exist in the library; OpenCatalogi contributes **data
only**. The blocks copy the proven shape from `pipelinq/src/manifest.json`
(step id naming, `advanceOn` usage, `data-cn-route` nav-item targets,
`data-walkthrough-id="index-add"` for the Add button), retargeted to
OpenCatalogi's menu routes (`Catalogs`, `Publications`, `Directory`, `Search`)
and config keys (`catalog_register`, `catalog_schema`, publication `register`,
`listing_register`).

```
src/manifest.json
├── setup          (NEW)  → CnSetupWizard  (gates shell on required config-check)
├── walkthrough    (NEW)  → CnWalkthrough  (publishing-journey tour)
└── menu[]         (+1)   → "Restart tutorial" entry → replay-walkthrough action
```

## Declarative-vs-imperative decision (ADR-031)

This change is **pure declarative manifest configuration**. No service classes,
controllers, composables, or Vue components are written in OpenCatalogi. Per
ADR-031 (schema-declarative business logic over service classes) and ADR-032
(`kind: config` — declarative JSON edits only):

- The setup wizard's steps, required-gating, and completion tracking are declared
  as `manifest.setup` data; the rendering, gating phase, and config writes are
  the shared `CnSetupWizard` engine's job (ADR-042).
- The walkthrough's tour, targets, and advance conditions are declared as
  `manifest.walkthrough` data; the spotlight overlay, advance watchers, and
  context bag are the shared `CnWalkthrough` engine's job (ADR-043).
- OpenCatalogi imports nothing, registers no route, and ships no PHP. The only
  imperative behaviour the wizard could trigger — a privileged server-side seed
  action (ADR-042 `run-action`) — is **explicitly excluded** and deferred to a
  follow-up CODE spec, keeping this change config-only.

## Database Changes

None. No tables, columns, or OpenRegister schema definitions are added or
modified. Onboarding reads existing app-config keys and existing registers.

## Nextcloud Integration

No new server-side integration. The shared engine reads/writes the app-config
keys named by `completionConfigKey` (`onboarding_completed_version`) and the
`catalog-scope` choice's bound config key through the library's existing settings
path; OpenCatalogi adds no controller, service, mapper, or event.

- Controllers: none
- Services: none
- Mappers/Entities: none
- Events/Hooks: none (advance watchers are the library's vue-router guard /
  object-store subscription, not app code)

## Security Considerations

No security impact from OpenCatalogi's side. The change adds no endpoint, no
auth surface, and no input handling — it is declarative manifest data. The
wizard NEVER writes OpenRegister objects from the browser (ADR-042); since no
`run-action` step is declared here, there is no privileged action to guard.
Publishing remains governed by schema-RBAC (the wizard's `catalog-scope` choice
only records a default scope into app-config).

## NL Design System

The walkthrough overlay and the setup wizard are rendered by the shared
`CnWalkthrough` / `CnSetupWizard` components, which are NL Design System themed
and WCAG 2.1 AA compliant (keyboard operable, focus management, `aria-live`
announcements) per ADR-043. OpenCatalogi inherits this; it adds no styling.

## File Structure

```
src/
  manifest.json          (MODIFIED — add setup + walkthrough blocks, Restart-tutorial menu entry)
```

Plus any synced/bundled manifest schema copy re-validated by `validate-manifest`.
No files under `lib/` change.

## Seed Data

**No new schemas are introduced by this change**, so there is no `_registers.json`
seed to generate. Onboarding only *reads* existing OpenCatalogi config and
registers (catalog register/schema, publication register, listing register) to
gate the wizard and to drive the tour's `object-created` advance conditions; it
defines no new data objects of its own. (A starter-catalog / sample-publication
seed would require the deferred server-side seed action and is out of scope.)

## Trade-offs

- **Manifest-only vs. a bespoke onboarding component.** Declaring the flow as
  manifest data (ADR-042/043) keeps OpenCatalogi free of per-app onboarding code
  and consistent with the fleet (pipelinq), at the cost of being limited to the
  engine's declarative step/advance vocabulary — which fully covers this journey.
- **`first-visit` walkthrough trigger (decided).** The tour triggers on
  **`first-visit`** — shown once per user on their first visit (tracked via the
  engine's seen-version) and not re-shown once seen or dismissed. This was chosen
  over `empty-index` (which re-fires whenever the index is empty) for standard
  "show the tour once" behaviour.
- **No seed step (decided).** Excluding the ADR-042 `run-action` seed keeps the
  change config-only; the trade-off is that an empty install has nothing to
  publish until the user creates a catalog by hand — which is precisely what the
  walkthrough guides them to do. A server-side seed action is deferred to a
  separate later program / follow-up CODE spec.

## Open Questions

- None blocking. The concrete IAppConfig key names asserted by the required
  `config-check` step (catalog register/schema, publication register, listing
  register) and the bound `catalog-scope` config key are confirmed against the
  running instance at the apply / live-verify step.
