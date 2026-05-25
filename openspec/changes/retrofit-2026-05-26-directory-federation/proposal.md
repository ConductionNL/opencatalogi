# retrofit-2026-05-26-directory-federation

## Why

OpenCatalogi federates with other catalog instances via a directory of listings and publication types. The directory side bar manages publication types (create/copy/delete, toggle enablement, synchronize), and the directory modals add/view listings. These are real federation capabilities lacking specs; this change reverse-specs them.

## What Changes

- Document the directory side bar (publication-type CRUD, enable/disable toggle, synchronize directory and publication types, listing item resolution, open link).
- Document the add-directory and view-directory modals (date/action formatting, listing display, close).
- No code changes — annotation-only retrofit.

## Impact

- **Affected specs**: new capability `retrofit-2026-05-26-directory-federation`
- **Affected code**: `src/sidebars/directory/DirectorySideBar.vue`, `src/modals/directory/*.vue` (docblock `@spec` annotations only)
- **Risk**: none — comment-only; production app, no admin merge.
