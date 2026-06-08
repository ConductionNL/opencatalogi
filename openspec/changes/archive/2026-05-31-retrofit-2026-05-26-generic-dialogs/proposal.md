# retrofit-2026-05-26-generic-dialogs

## Why

OpenCatalogi uses a family of confirmation dialogs for destructive and copy operations across entities (objects, attachments, categories, listings, themes, publications). Each dialog confirms intent, performs the operation, and refreshes the relevant list. These are real capabilities lacking specs; this change reverse-specs the generic confirm/copy dialogs not covered by the entity-specific changes.

## What Changes

- Document the generic delete/copy object dialogs and the entity-specific delete dialogs (attachment, category, multiple categories, listing, theme list, publish publication) — confirm, perform, refresh/close.
- No code changes — annotation-only retrofit.

## Impact

- **Affected specs**: new capability `retrofit-2026-05-26-generic-dialogs`
- **Affected code**: `src/dialogs/generic/*.vue`, `src/dialogs/attachment/DeleteAttachmentDialog.vue`, `src/dialogs/category/*.vue`, `src/dialogs/listing/DeleteListingDialog.vue`, `src/dialogs/theme/DeleteMultipleThemesDialog.vue`, `src/dialogs/publication/PublishPublicationDialog.vue` (docblock `@spec` annotations only)
- **Risk**: none — comment-only; production app, no admin merge.
