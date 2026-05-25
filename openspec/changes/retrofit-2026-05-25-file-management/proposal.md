# Retrofit — file-management (frontend)

## Why
The file-management backend is already specified by FIL-001..015 (Bucket 1), but the
**frontend** attachment/upload surface had no spec coverage. This reverse-spec
retroactively documents that observed behavior.

## What Changes
Adds 4 ADDED requirements (FIL-016..019) to the `file-management` capability and annotates
the implementing frontend code units with `@spec` tags. No code behavior changes.

## Affected code units
- src/modals/generic/UploadFiles.vue (FIL-016)
- src/dialogs/attachment/DeleteAttachmentDialog.vue (FIL-017)
- src/modals/attachment/EditAttachmentModal.vue (FIL-018)
- src/composables/UseFileSelection.js, src/dialogs/attachment/MassAttachmentModal.vue (FIL-019)

## Approach
- For each unit: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)

## Observed note
Frontend attachment operations call the OpenRegister files endpoints directly
(`/apps/openregister/api/objects/{register}/{schema}/{id}/files[...]`), reading
register/schema/id from the active publication's `@self` metadata.

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
