---
kind: config
depends_on: []
---

# Proposal: reconcile-notifications-retention-drift

## Summary

Reconcile the `notifications` capability spec (status `done`) with what the
`publication` schema actually ships after `publication-retention-lifecycle`
landed. The spec's requirement "CMS-config and ownerless schemas are not
notified" asserts that `publication` MUST NOT carry `x-openregister-notifications`
"(which has no lifecycle or owner field)" — but the shipped
`lib/Settings/publication_register.json` now declares **both**
`x-openregister-lifecycle` and `x-openregister-notifications` on `publication`
(the retention-expiring-soon / retention-review-required rules from RET-008).
This change MODIFIES that requirement so the "done" spec no longer contradicts
the shipped schema. No code changes — the schema is already correct; the spec is
stale.

## Motivation

A "done" capability spec that contradicts the shipped register JSON is a
correctness defect in the spec corpus:

- The `hydra-gate-notification-dialect` gate and future authors read these specs
  as the source of truth for what each schema may declare. A requirement that
  forbids what the schema legitimately ships invites a "fix" that would *delete*
  the retention notifications.
- Verified at HEAD `4d8b395`: `publication_register.json` carries
  `x-openregister-lifecycle` (~line 217) and `x-openregister-notifications`
  (~line 233) on `publication`, added by `publication-retention-lifecycle`
  (RET-008 "Retention notifications are schema-declared"). Both are the
  canonical ADR-031 declarative dialect — no imperative dispatch — so this is a
  spec-vs-code drift, not an abstraction violation.

## Goals

1. MODIFY the "CMS-config and ownerless schemas are not notified" requirement so
   `publication` is removed from the exclusion list, and the scenario checks only
   the genuinely ownerless config schemas (`page`, `menu`, `theme`, `glossary`,
   `organization`).
2. State that `publication`'s notifications are the retention rules owned by
   `publication-retention-lifecycle` (RET-008), so the two specs cross-reference
   rather than contradict.

## Non-Goals

- **No code or schema change.** The register JSON already ships the correct
  declarative notifications; only the spec text is corrected.
- **No re-specification of the retention notifications** — those remain owned by
  `publication-retention-lifecycle` (RET-008). This change only removes the
  false exclusion.

## High-Level Approach

A single MODIFIED requirement on the `notifications` capability, plus a task to
confirm the shipped schema matches the corrected spec.
