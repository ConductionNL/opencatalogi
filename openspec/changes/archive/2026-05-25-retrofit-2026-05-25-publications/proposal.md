# Retrofit — publications (frontend)

## Why
The publications backend is already specified by PUB-001..015 (Bucket 1), but the
publication-specific **frontend** surface (publish/depublish store actions + the publish
confirmation dialog) had no spec coverage. This reverse-spec retroactively documents that
observed behavior so the retrofit cohort dashboards reflect real coverage.

## What Changes
Adds 3 ADDED requirements (PUB-016..018) to the `publications` capability and annotates the
3 implementing frontend code units with `@spec` tags. No code behavior changes.

## Affected code units
- src/store/modules/object.js::publishObject
- src/store/modules/object.js::depublishObject
- src/dialogs/publication/PublishPublicationDialog.vue

## Approach
- For each unit: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)
- Notes section surfaces the PublishPublicationDialog confirm-handler bug (copies a
  menu instead of publishing) — flagged, NOT fixed

## Coverage-report drift
The coverage report was generated on branch `feature/declarative-annotation-pilot`.
On `origin/development` the publications view shells listed in the report
(`PublicationIndex.vue`, `PublicationDetail.vue`, `PublicationDetailPage.vue`,
`PublicationList.vue`, `PublicationTable.vue`) no longer exist — the frontend was
refactored. Only `PublishPublicationDialog.vue` and the generic `object.js` store
remain. REQs are drawn from the code that actually exists on development; the generic
object store is shared plumbing and only its publication-specific methods are specified.

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
