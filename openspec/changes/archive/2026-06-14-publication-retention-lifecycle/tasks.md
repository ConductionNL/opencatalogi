# Tasks: publication-retention-lifecycle

This change consumes the OR published-predicate, lifecycle declaration,
notification dialect, and audit-trail abstraction (hydra ADR-022). The only
new moving part is one daily evaluation job; everything else is schema
fields, configuration, declared rules, and UI on existing surfaces.

> Implementation note: this app stores publish/depublish dates as the schema
> fields `publicatiedatum` / `depublicatiedatum`. The OR published-predicate
> evaluates them through the publication schema's `authorization.read` `$now`
> match, so the spec's `@self.published` / `@self.depublished` references map
> onto those concrete fields. A future-dated `publicatiedatum` is an embargo;
> a future-dated `depublicatiedatum` is a scheduled depublication.

## Task 1: Implementation planning
- **Spec ref**: specs/publication-retention-lifecycle/spec.md
- **Status**: done
- **Acceptance criteria**: Requirements decomposed respecting the
  consume-vs-build split; warning-window default (30 days, catalog-configurable)
  and selectielijst vocabulary (free-text category + action enum) confirmed;
  magic-mapping `@self.published` upstream dependency noted (an OR-side
  limitation, see Dependencies in the spec).

## Task 2: Scheduled publish/depublish surfaced end-to-end
- **Spec ref**: specs/publication-retention-lifecycle/spec.md — RET-001, RET-002
- **Status**: done (already built; verified during audit)
- **Acceptance criteria**:
  - [x] Scheduled publish/depublish surfaced end-to-end: the
    `MassPublishObjects` / `MassDepublishObjects` modals (wired into the
    publication table) already offer "now" / "later" modes with date-time
    pickers that write future `publicatiedatum` / `depublicatiedatum`. No new
    embargo field or visibility check (OR predicate governs visibility).
  - [x] "Scheduled" status visible in the listing — derived by
    `src/services/publicationStatus.js` (a future `publicatiedatum` =
    concept/scheduled).
  - [~] Cache-validator audit on sitemap/DCAT/public-API conditional responses:
    deferred — design open question; public surfaces filter on the predicate at
    request time, the cache-granularity audit is tracked as a follow-up.
  - [~] Newman assertions (invisible before / visible after on every public
    surface): deferred to the federation/Newman suite; covered at the unit
    level by `publicationStatus` + `retentionStatus` derivation tests.

## Task 3: Retention schema fields + per-catalog defaults
- **Spec ref**: specs/publication-retention-lifecycle/spec.md — RET-003, RET-004
- **Status**: done
- **Acceptance criteria**:
  - [x] Publication schema gains `retentionCategory`, `retentionTermMonths`,
    `retentionAction`, `retentionExpiresAt`, `retentionNote` (+
    `retentionLastEvaluatedAt` idempotency marker), all in
    `lib/Settings/publication_register.json`.
  - [x] `retentionExpiresAt` computed (publication date + term) in
    `RetentionService::computeExpiry`; manual override requires a note
    (`recordHumanDecision` enforces a mandatory rationale).
  - [x] Admin per-catalog default `{termMonths, action}` per WOO category +
    fallback, applied at first publication and never retroactively
    (`RetentionService::applyDefaults` / `getRetentionDefaults` /
    `setRetentionDefaults`); terms stored as config JSON, never hard-coded.
  - [x] `publication` added to `SettingsService::updateObjectTypeConfiguration`
    so `publication_register` / `publication_schema` resolve.

## Task 4: Daily retention evaluation job
- **Spec ref**: specs/publication-retention-lifecycle/spec.md — RET-005
- **Status**: done
- **Acceptance criteria**:
  - [x] `Cron\RetentionEvaluation` (TimedJob, daily) registered via
    `appinfo/info.xml` `<background-jobs>` — the canonical NC registration, NOT
    the invalid registerJob-on-context pattern.
  - [x] Plain OR object search on `retentionExpiresAt`; per-action behaviour:
    depublish via `depublicatiedatum` (PUB-017 path), archive via the declared
    `x-openregister-lifecycle` transition (no PHP guard), review = flag only.
  - [x] Idempotent re-runs via the `retentionLastEvaluatedAt` marker; every
    automatic action persisted via OR `saveObject` (auto-audited).

## Task 5: Notifications (declared dialect)
- **Spec ref**: specs/publication-retention-lifecycle/spec.md — RET-008
- **Status**: done
- **Acceptance criteria**:
  - [x] `retention-expiring-soon` and `retention-review-required`/archived rules
    declared via `x-openregister-notifications` on the publication schema,
    canonical dialect only (gate-18 PASS). No bespoke listeners.

## Task 6: Review queue, archived filter, dashboard widget
- **Spec ref**: specs/publication-retention-lifecycle/spec.md — RET-006, RET-007
- **Status**: done
- **Acceptance criteria**:
  - [x] Retention fields are `facetable`, so the existing object-table surfaces
    retention-status facets and the "Archived" filter without bespoke UI.
  - [x] Per-item / bulk decisions: "extend term (note required)" / "depublish
    now" / "archive" via `RetentionController::decide`
    (`RetentionService::recordHumanDecision`); bulk reuses the same service.
  - [x] Dashboard retention widget (`Dashboard\RetentionWidget` +
    `src/views/widgets/RetentionWidget.vue`) with expiring-soon /
    review-required / archived counts deep-linking into the pre-filtered queue
    (`?retention=...`).
  - [~] Playwright UI coverage of the widget/dialog surfaces: deferred — covered
    at the unit level (`retentionStatus.spec.js`, RetentionService tests); the
    bulk-action surfaces already carry existing e2e/UI coverage.

## Task 7: Disposal decisions + retention report
- **Spec ref**: specs/publication-retention-lifecycle/spec.md — RET-006, RET-009
- **Status**: done
- **Acceptance criteria**:
  - [x] Disposal/extension decision recording (who/when/basis/rationale) appended
    to the object's `retentionDecisionLog` and persisted via OR `saveObject`, so
    the immutable audit trail captures it; mandatory rationale enforced.
  - [x] Authenticated CSV export (UTF-8 BOM) per catalog + date range
    (`RetentionController::exportReport` / `RetentionService::buildReport` +
    `renderReportCsv`), derived from stored fields + the decision log; no
    separate reporting store.
