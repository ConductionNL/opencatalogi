# Design: publication-retention-lifecycle

## Architecture Overview

The whole feature is a **thin temporal policy layer** on mechanisms that
already exist:

| Concern | Owner (consumed) | This change adds |
|---|---|---|
| "Is it public right now?" | OR published-predicate over `@self.published`/`@self.depublished` (APB-006) | nothing — relies on it |
| Scheduling publish/depublish | the same two timestamps, set in the future | UI + explicit spec semantics |
| `published → archived` transition | `x-openregister-lifecycle` (APB-SM-001) | the trigger that requests it |
| Retention metadata storage | OR publication schema | new fields + per-catalog defaults |
| Notifications | `x-openregister-notifications` (ADR-031) | new declared rules |
| Decision immutability | OR audit-trail abstraction | the decision payloads |
| Expiry detection | — | ONE daily evaluation job (in-app) |

## Key decisions

### 1. Embargo and scheduled depublication are the published-predicate, not a new mechanism
`@self.published` and `@self.depublished` are date-times that OR already
compares against *now* (APB-006: "published when published ≤ now and
depublished is null or > now"). Setting them in the future therefore already
embargoes / schedules — but nothing in any spec states this contract, no UI
exposes it (the PUB-018 dialog is now-or-nothing), and no test pins it. The
design choice is to **bless the existing semantics** rather than add an
"embargo" field: zero new state, and every public surface (publications API,
sitemap, DCAT, federation) is automatically coherent because they all already
filter on the same predicate.

Implication to verify during implementation: cache headers on public
endpoints must not outlive an embargo boundary (a 09:00 publish must not be
masked by a stale 304), and the known magic-mapping `@self.published` gap is
an upstream OR dependency for objects created that way.

### 2. Retention metadata is data, policy is configuration
New optional fields on the publication schema (delivered as a schema update,
stored in OR like any field):

- `retentionCategory` — selectielijst/WOO-category reference
- `retentionTermMonths` — statutory term
- `retentionAction` — `review` | `depublish` | `archive`
- `retentionExpiresAt` — computed: publication date + term (recomputed on
  change; overridable for legal exceptions with a note)
- `retentionNote` — legal basis / exception rationale

Per-catalog admin configuration maps each WOO information category
(woo-compliance WOO-003's 17 categories) to a default `{termMonths, action}`,
applied when a publication is first published and left untouched thereafter
(officers may override per object). Hardcoding terms in PHP is forbidden —
municipalities differ by selectielijst version.

### 3. One evaluation job, everything else declared
The only new moving part is a daily background pass (same pattern as the
existing directory-sync cron; registered properly per the
IRegistrationContext gotcha) that:

1. queries OR for publications with `retentionExpiresAt` within the warning
   window or in the past (a plain object search — no bespoke index);
2. emits the *declared* notification events (expiring-soon, expired);
3. for `retentionAction: depublish` → sets `@self.depublished = now`
   (the standard depublication path, PUB-017);
4. for `retentionAction: archive` → requests the `archive` transition
   declared in `x-openregister-lifecycle` (OR validates it);
5. for `retentionAction: review` → flags only; humans decide in the review
   queue.

The job never embodies policy beyond reading the fields; it is a dumb
evaluator. Idempotent: re-running on the same day produces no duplicate
notifications/actions (last-evaluated marker on the object).

### 4. Archived ≠ deleted, archived ≠ published
`archived` (the existing lifecycle state) means: no longer publicly visible
(depublished timestamp set as part of the transition), retained internally
with its full history, excluded from sitemaps/DCAT/federation, included in
the retention report. Actual disposal (vernietiging) is always an explicit
human decision recorded via the audit-trail abstraction; this change adds the
decision recording + report, not automated deletion.

### 5. Review queue is a filtered list, not a new surface
The officer-facing "expiring soon / expired / archived" queue is the existing
publications table with retention facets + a dashboard count widget — built on
the existing object-table/dashboard infrastructure (retrofit specs), not a new
view framework. Decisions (extend / depublish now / archive) are existing
actions plus an "extend term" edit that updates `retentionExpiresAt` with a
mandatory note.

## What is explicitly NOT built (ADR-022)
- No bespoke scheduler/state machine (lifecycle declaration + two timestamps).
- No bespoke notification listeners (schema-declared dialect; the
  `opencatalogi-notifications` change establishes the pattern).
- No bespoke audit table (OR audit-trail abstraction).
- No e-Depot/TMLO/MDTO transfer pipeline (future change consumes the archive
  state + decision trail produced here).

## Open questions
1. Default warning window (proposed: 30 days, catalog-configurable).
2. Should `archive` also be offered as a manual bulk action from the review
   queue (proposed: yes, reusing mass-object-actions)?
3. Selectielijst vocabulary: free-text reference now vs. a managed list
   schema later (proposed: free-text + category dropdown now).
