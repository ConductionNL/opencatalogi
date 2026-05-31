# retrofit-2026-05-26-object-modals

## Why

OpenCatalogi ships a rich object-editing surface — the view/edit modal, the create modal, file upload, download, merge, and migration wizards — that predates any written spec. These are real, user-facing capabilities (editing objects against a schema, attaching/publishing files, merging two objects, migrating objects between register/schema). This change reverse-specs them so every method carries an `@spec` reference per ADR-003/ADR-008.

## What Changes

- Document the object view/edit modal capability (schema-driven form, form↔JSON sync, tag editing, per-file publish/depublish/delete, validation, save).
- Document the create-object modal (register/schema/catalog selection, JSON validation, save).
- Document file upload (multi-file selection, size/duplicate checks, tag/label editing, retry).
- Document object download, merge wizard, and register/schema migration wizard.
- No code changes — annotation-only retrofit.

## Impact

- **Affected specs**: new capability `retrofit-2026-05-26-object-modals`
- **Affected code**: `src/modals/object/*.vue` (docblock `@spec` annotations only)
- **Risk**: none — comment-only; production app, no admin merge.
