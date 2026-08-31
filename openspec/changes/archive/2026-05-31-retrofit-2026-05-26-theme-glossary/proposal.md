# retrofit-2026-05-26-theme-glossary

## Why

OpenCatalogi supports publication themes (categorization) and a glossary of terms surfaced in publications. The theme add/view modals and the glossary view modal are real capabilities lacking specs; this change reverse-specs them.

## What Changes

- Document the add-publication-theme modal and the view-theme modal (theme options, save, open-edit, close).
- Document the glossary view modal (term display, term selection, open-edit, close).
- No code changes — annotation-only retrofit.

## Impact

- **Affected specs**: new capability `retrofit-2026-05-26-theme-glossary`
- **Affected code**: `src/modals/theme/*.vue`, `src/modals/glossary/ViewGlossaryModal.vue` (docblock `@spec` annotations only)
- **Risk**: none — comment-only; production app, no admin merge.
