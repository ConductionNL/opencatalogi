# Retrofit — dashboard (frontend)

## Why
The dashboard/directory/listing backend is already specified by DSH/DIR/LST REQs (Bucket 1),
but the **frontend** surface (SPA shell, dashboard overview, unpublished widgets, directory
and listing management UIs) had no spec coverage. This reverse-spec retroactively documents
that observed behavior.

## What Changes
Adds 5 ADDED requirements (DSH-009..011, DIR-012, LST-007) to the `dashboard` capability and
annotates the implementing frontend code units with `@spec` tags. No code behavior changes.

## Affected code units
- SPA shell: App.vue, navigation/MainMenu.vue (DSH-009)
- Overview: views/dashboard/Dashboard.vue, sidebars/dashboard/DashboardSideBar.vue (DSH-010)
- Widgets: views/widgets/UnpublishedAttachmentsWidget.vue + .js, UnpublishedPublicationsWidget.vue + .js (DSH-011)
- Directory: sidebars/directory/DirectorySideBar.vue, modals/directory/AddDirectoryModal.vue, modals/directory/ViewDirectoryModal.vue (DIR-012)
- Listing: modals/listing/EditListingModal.vue, modals/directory/EditListingModal.vue, dialogs/listing/DeleteListingDialog.vue (LST-007)

## Approach
- For each unit: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)

## Observed notes
- `App.vue` is now a manifest-driven `CnAppRoot` shell (ADR-024 Tier-4 manifest pattern),
  which is why the old `src/router/index.js` and several `*Index.vue` views are gone.
- `EditListingModal.vue` is duplicated across `modals/directory` and `modals/listing`
  (flagged by the report). LST-007 specifies both; de-duplication is a separate code change.

## Coverage-report drift
Report generated on `feature/declarative-annotation-pilot`. On `development`,
`src/views/directory/DirectoryIndex.vue`, `src/router/index.js`, and
`src/views/organizations/OrganizationIndex.vue` (in the report) no longer exist (manifest
refactor). The 14 present files above form the basis of these REQs. Cluster had 17 report
entries; capped at 5 REQs per the reverse-spec guardrail.

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
