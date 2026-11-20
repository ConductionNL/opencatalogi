---
description: Start a new OpenSpec change for this app
---

Start a new OpenSpec change named `$ARGUMENTS` (kebab-case, describing what the change does).

1. Read `openspec/config.yaml` for the rules on proposal, specs, design and tasks.
2. Check `openspec/specs/` for the capability the change touches, and `openspec/changes/` for an open change that already covers it.
3. Create `openspec/changes/<name>/` with, following an existing change such as `openspec/changes/catalogs/`:
   - `proposal.md`: Summary, Motivation, Scope (in and out), Risks.
   - `design.md`: how it is built and which existing services it reuses (optional for small changes).
   - `tasks.md`: numbered tasks with spec references, files and acceptance criteria checkboxes.
   - `specs/<capability>/spec.md`: delta requirements under `## ADDED Requirements` or `## MODIFIED Requirements`, each `### Requirement:` with `#### Scenario:` blocks in GIVEN / WHEN / THEN form.
4. If the change adds pages or menu entries, put them in a new fragment in `src/manifest.d/` (see `src/manifest.d/README.md`).

When the change is implemented, its requirements go into `openspec/specs/` and the change folder moves to `openspec/changes/archive/` with a date prefix.
