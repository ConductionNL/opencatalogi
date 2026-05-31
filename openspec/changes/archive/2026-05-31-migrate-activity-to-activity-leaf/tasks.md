# Tasks: migrate-activity-to-activity-leaf

This change consumes the OpenRegister activity leaf (hydra ADR-022) for the
per-publication activity feed and trims the bespoke object-lifecycle listeners to
the auto-publishing side effect only. SPEC-ONLY — apply runs through Hydra once
the activity leaf is available upstream.

## Task 1: Implementation planning
- **Spec ref**: specs/auto-publishing/spec.md
- **Status**: done
- **Acceptance criteria**: Requirements decomposed respecting the keep/migrate
  split (feed → leaf; auto-publish side effect → stays); OR activity-leaf
  availability confirmed as the apply gate.

## Task 2: Surface the OR activity leaf feed on the publication detail page
- **Spec ref**: specs/auto-publishing/spec.md — APB-ACT-001; ADR-024 / ADR-036
- **Status**: done
- **Acceptance criteria**:
  - Activity widget declared on the `PublicationDetail` manifest entry
    (`src/manifest.json`) and rendered in `PublicationDetail.vue`.
  - Feed lists create / update / publish / depublish / file-change events from
    the activity leaf.
  - Graceful "activity integration required" handling when the leaf is absent.
  - NO bespoke in-app activity table is introduced.

## Task 3: Trim ObjectCreatedEventListener to the auto-publish side effect (APB-001)
- **Spec ref**: specs/auto-publishing/spec.md — APB-001
- **Status**: done
- **Acceptance criteria**:
  - Listener performs only the OpenCatalogi-specific auto-publish side effect.
  - Debug `OPENCATALOGI_EVENT_*` logging removed.
  - No implicit activity-recording responsibility remains.

## Task 4: Trim ObjectUpdatedEventListener to the auto-publish side effect (APB-002)
- **Spec ref**: specs/auto-publishing/spec.md — APB-002
- **Status**: done
- **Acceptance criteria**:
  - Listener performs only the auto-publish side effect behind `shouldProcessUpdate()`.
  - Debug `OPENCATALOGI_EVENT_*` logging removed.

## Task 5: Verify auto-publishing continuity and feed completeness
- **Spec ref**: specs/auto-publishing/spec.md — APB-001, APB-002, APB-ACT-001
- **Status**: done
- **Acceptance criteria**:
  - Auto-publish on create/update still works end-to-end after the trim.
  - The activity leaf feed includes publish/depublish events (not just CRUD).
