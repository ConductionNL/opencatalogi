# retrofit-2026-05-26-object-table-listing

## Why

OpenCatalogi renders object collections as a generic, configurable table with selection, per-row and mass actions, pagination, and dynamic columns derived from the schema. It also renders publication cards and a markdown editor used across detail views. These are real presentation/interaction capabilities lacking specs; this change reverse-specs them.

## What Changes

- Document the generic object table (column derivation from schema/metadata, selection, per-object and mass actions, pagination, view mode, row click/open).
- Document the pagination component (page/size change, visible page window).
- Document the publication card (title/summary/status/date/file-count derivation, truncation).
- Document the markdown editor wrapper (content binding, change handler).
- No code changes — annotation-only retrofit.

## Impact

- **Affected specs**: new capability `retrofit-2026-05-26-object-table-listing`
- **Affected code**: `src/components/GenericObjectTable.vue`, `src/components/PaginationComponent.vue`, `src/components/PublicationCard.vue`, `src/components/MarkdownEditor.vue` (docblock `@spec` annotations only)
- **Risk**: none — comment-only; production app, no admin merge.
