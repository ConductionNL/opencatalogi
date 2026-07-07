# Tasks: reconcile-notifications-retention-drift

This change is `kind: config` (ADR-032) and spec-only: it corrects a stale
requirement to match the already-shipped register JSON. No code, no schema edit.

- [ ] Freeze the delta spec under
  `openspec/changes/reconcile-notifications-retention-drift/specs/notifications/spec.md`
  (MODIFIED "CMS-config and ownerless schemas are not notified"); confirm
  `openspec validate reconcile-notifications-retention-drift --strict` is green
  - Spec ref: specs/notifications/spec.md (this change)
  - Acceptance: validator reports valid; the two catalogue/listing notification
    requirements are untouched
- [ ] Confirm the shipped `lib/Settings/publication_register.json` matches the
  corrected spec: `x-openregister-notifications` present on `publication`,
  `catalog`, `listing`; absent on `page`, `menu`, `theme`, `glossary`,
  `organization`
  - Spec ref: MODIFIED requirement (both scenarios)
  - Acceptance: grep confirms the presence/absence pattern; no `INotificationManager`
    / imperative dispatch in `lib/`
- [ ] On archive/sync, refresh the `notifications` spec Purpose prose to note
  `publication` also carries retention notifications (currently says only
  `catalog` + `listing`)
  - Spec ref: notifications/spec.md Purpose
  - Acceptance: Purpose no longer contradicts the shipped schema
