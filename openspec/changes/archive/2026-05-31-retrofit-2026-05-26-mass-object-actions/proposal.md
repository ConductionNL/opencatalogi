# retrofit-2026-05-26-mass-object-actions

## Why

OpenCatalogi lets a user act on many objects at once: publish, depublish, delete, lock, unlock, and validate a selection, plus mass attachment publish/depublish and the supporting selection lists. These bulk operations are real capabilities with non-trivial logic (publish/depublish date handling, mode selection, already-in-state warnings, unsupported-object detection). This change reverse-specs them.

## What Changes

- Document mass publish / depublish (with publish/depublish date, mode, and already-in-state/unsupported warnings).
- Document mass delete, lock, unlock, validate.
- Document the mass attachment publish/depublish modal.
- Document the selected-objects and selected-attachments list components.
- No code changes — annotation-only retrofit.

## Impact

- **Affected specs**: new capability `retrofit-2026-05-26-mass-object-actions`
- **Affected code**: `src/modals/object/Mass*.vue`, `src/dialogs/attachment/MassAttachmentModal.vue`, `src/components/Selected*List.vue`, `src/components/SelectAttachmentsList.vue` (docblock `@spec` annotations only)
- **Risk**: none — comment-only; production app, no admin merge.
