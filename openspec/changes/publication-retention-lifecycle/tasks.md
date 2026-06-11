# Tasks: publication-retention-lifecycle

This change consumes the OR published-predicate, lifecycle declaration,
notification dialect, and audit-trail abstraction (hydra ADR-022). The only
new moving part is one daily evaluation job; everything else is schema
fields, configuration, declared rules, and UI on existing surfaces.

## Task 1: Implementation planning
- **Spec ref**: specs/publication-retention-lifecycle/spec.md
- **Status**: todo
- **Acceptance criteria**: Requirements decomposed respecting the
  consume-vs-build split; warning-window default and selectielijst
  vocabulary decision confirmed with the user; magic-mapping
  `@self.published` upstream dependency status checked with OR.

## Task 2: Scheduled publish/depublish surfaced end-to-end
- **Spec ref**: specs/publication-retention-lifecycle/spec.md — RET-001,
  RET-002
- **Status**: todo
- **Acceptance criteria**:
  - Publish/depublish dialog (PUB-018) gains "Publish on" / "Depublish on"
    date-time pickers; store actions write future `@self.published` /
    `@self.depublished`. NO new embargo field or visibility check.
  - "Scheduled" status visible in the publications listing.
  - Cache-validator audit: sitemap/DCAT/public-API conditional responses
    cannot mask an embargo boundary beyond documented cache granularity.
  - Newman assertions: invisible before, visible after, on every public
    surface.

## Task 3: Retention schema fields + per-catalog defaults
- **Spec ref**: specs/publication-retention-lifecycle/spec.md — RET-003,
  RET-004
- **Status**: todo
- **Acceptance criteria**:
  - Publication schema update: `retentionCategory`, `retentionTermMonths`,
    `retentionAction`, `retentionExpiresAt` (computed; override requires
    note), `retentionNote`.
  - Admin-settings: per-catalog default `{termMonths, action}` per WOO
    information category (WOO-003 list) + fallback; applied at first
    publication, never retroactive, never hard-coded in PHP.

## Task 4: Daily retention evaluation job
- **Spec ref**: specs/publication-retention-lifecycle/spec.md — RET-005
- **Status**: todo
- **Acceptance criteria**:
  - Background job registered via proper IRegistrationContext registration
    (NOT the invalid registerJob-on-context pattern that left fleet jobs
    never running).
  - Plain OR object search on `retentionExpiresAt`; per-action behaviour:
    depublish via the standard PUB-017 path, archive via the declared
    lifecycle transition (no PHP guard), review = flag only.
  - Idempotent re-runs; every automatic action recorded via the OR
    audit-trail abstraction.

## Task 5: Notifications (declared dialect)
- **Spec ref**: specs/publication-retention-lifecycle/spec.md — RET-008
- **Status**: todo
- **Acceptance criteria**:
  - Expiring-soon / review-required / auto-actioned rules declared via
    `x-openregister-notifications` on the publication schema, consistent with
    the `opencatalogi-notifications` change; canonical dialect only
    (gate-18); deep links into the review queue.

## Task 6: Review queue, archived filter, dashboard widget
- **Spec ref**: specs/publication-retention-lifecycle/spec.md — RET-006,
  RET-007
- **Status**: todo
- **Acceptance criteria**:
  - Retention status facets on the existing publications table; "Archived"
    filter for authenticated users; per-item + bulk "extend term (note
    required)" / "depublish now" / "archive" via existing
    mass-object-actions and dialog surfaces.
  - Dashboard retention widget with expiring-soon / review-required counts
    deep-linking into the pre-filtered queue.
  - Archived objects verified absent from all public surfaces, present
    internally with history + attachments.
  - Playwright UI coverage for the queue/widget/dialog surfaces; Newman for
    visibility assertions (UI-only Playwright, API in Newman).

## Task 7: Disposal decisions + retention report
- **Spec ref**: specs/publication-retention-lifecycle/spec.md — RET-006,
  RET-009
- **Status**: todo
- **Acceptance criteria**:
  - Disposal decision recording (who/when/basis/rationale) via the OR
    audit-trail abstraction, mandatory before any deletion.
  - Authenticated CSV export (UTF-8 BOM) per catalog + date range, derived
    from stored fields + audit records; no separate reporting store.
