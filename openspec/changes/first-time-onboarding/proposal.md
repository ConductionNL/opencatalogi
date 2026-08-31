---
kind: config
depends_on: []
---

# Proposal: first-time-onboarding

## Summary

Add a first-time **setup wizard** (ADR-042) and a product **walkthrough tour**
(ADR-043) to OpenCatalogi as **manifest-only declarative configuration** — two
new top-level blocks, `setup` and `walkthrough`, in `src/manifest.json`. The
setup wizard blocks the app shell until the publishing prerequisites (catalog
register/schema, publication register, listing register) are configured; the
walkthrough then teaches a new user the publishing journey (create a catalog →
add a publication → publish via schema-RBAC → discover via the Directory /
DCAT-AP output) by spotlighting real UI elements in an empty environment.

## Motivation

A new user who installs OpenCatalogi lands on an empty shell with a menu of
Catalogs, Publications, Search, Directory, Themes and Glossary, but no guidance
on the actual journey: OpenCatalogi is broken until the catalog register/schema,
the publication register and the listing register are configured, and even once
configured, nothing explains that publishing is **schema-RBAC** (not a
`@self.published` flag), that a **catalog scope is mandatory**, or that
discovery happens through the **Directory and DCAT-AP** harvest/output. Today
that knowledge lives in documentation nobody reads in-context.

ADR-042 and ADR-043 already define a shared, abstract, manifest-driven setup
wizard and walkthrough engine owned by `@conduction/nextcloud-vue`, proven on
pipelinq. OpenCatalogi only needs to **declare its intent as manifest data** —
no bespoke onboarding code — to get a gating config check plus a guided
publishing tour. Doing it now, before more apps copy the pattern, keeps
OpenCatalogi consistent with the fleet (ADR-042/043 rollout).

## Affected Projects
- [x] Project: `opencatalogi` — add `setup` + `walkthrough` blocks to `src/manifest.json` (and synced manifest schema copies)

## Scope

### In Scope
- A `manifest.setup` block (block-on-required) with steps: `welcome` (info) →
  `config-check` (config-fields, **required**: verify catalog register/schema,
  publication register, listing register are configured) → `catalog-scope`
  (choice: default catalog visibility/scope, since publish is schema-RBAC) →
  `done` (summary: health check + next-step links to Catalogs / Directory).
  Completion tracked via `completionConfigKey` `onboarding_completed_version`.
- A `manifest.walkthrough` block with one tour covering the publishing journey:
  `welcome` → `go-catalogs` → `create-catalog` → `go-publications` →
  `add-publication` → `publish` (explain schema-RBAC) → `discover` →
  `done` (point at DCAT-AP output).
- English literal i18n source strings on all step titles/bodies (ADR-025/007).
- A "Restart tutorial" menu entry that replays the tour.

### Out of Scope
- Any server-side **seed action** (`run-action` step / controller / privileged
  seeding endpoint). ADR-042 allows a `run-action` step but OpenCatalogi
  introduces no new schemas here and only reads existing config/registers — a
  seed action is **deferred to a follow-up CODE spec** (see Open Questions).
- Any PHP, Vue, or controller changes — this change is `kind: config`
  (declarative manifest JSON only, ADR-032).
- Building or changing the wizard/walkthrough engine itself (owned by
  `@conduction/nextcloud-vue`).
- The `tilburg-woo-ui` public frontend — onboarding targets the admin app shell.

## Approach

Add the two declarative blocks to `src/manifest.json`, copying the exact shape
from pipelinq's `setup` and `walkthrough` blocks (step id naming, `advanceOn`
usage, `data-cn-route` nav-item targets, `data-walkthrough-id="index-add"` for
the Add button). The shared `CnSetupWizard` / `CnWalkthrough` engines render the
declared steps; OpenCatalogi writes no code. Target the existing menu routes
(`Catalogs`, `Publications`, `Directory`, `Search`) and config keys
(`catalog_register`, `catalog_schema`, `publication` register, `listing_register`).

## New Dependencies
None. The wizard + walkthrough engines already ship in `@conduction/nextcloud-vue`.

## Impact
- `src/manifest.json` — two new top-level keys (`setup`, `walkthrough`) plus a
  "Restart tutorial" menu entry. The bundled/synced manifest schema copy is
  re-validated.
- Runtime: the app shell gains a gating setup phase and a non-gating tour
  overlay on first visit. No backend, no API, no schema impact.

## Cross-Project Dependencies
None at the spec level. Runtime-only: the engine lives in
`@conduction/nextcloud-vue`; OpenCatalogi already depends on it.

## Risks

### Risk 1: Required config-check false-positives block the shell
**Severity:** Medium — **Mitigation:** The `config-check` required step only
verifies config keys that OpenCatalogi already documents as mandatory
(`catalog_register`, `catalog_schema`, publication register, `listing_register`).
Live-verify on :8080 that a configured instance passes the gate and an
unconfigured one is gated, before merge.

### Risk 2: Walkthrough targets drift from real DOM elements
**Severity:** Low — **Mitigation:** Targets use stable manifest identifiers
(`nav-item` route refs, `data-walkthrough-id="index-add"`) per ADR-043, not
brittle CSS. Live-verify the tour clicks through end to end.

## Rollback Strategy
Remove the `setup` and `walkthrough` keys (and the "Restart tutorial" menu
entry) from `src/manifest.json` and re-run `validate-manifest`. Because the
change is purely additive declarative config with no schema or DB impact,
deleting the two blocks fully reverts onboarding with no data migration.

## Resolved Decisions
- **Seed action:** confirmed out of scope — onboarding stays **config-only**; the
  wizard guides the user to create their own first catalog/publication. A
  server-side privileged seed action (ADR-042 `run-action`) is deferred to a
  separate later program / follow-up CODE spec, not this change.
- **Walkthrough trigger:** **`first-visit`** — the tour is shown once per user on
  their first visit (tracked via the engine's seen-version) and not re-shown once
  seen or dismissed, rather than re-firing whenever the index is empty.
