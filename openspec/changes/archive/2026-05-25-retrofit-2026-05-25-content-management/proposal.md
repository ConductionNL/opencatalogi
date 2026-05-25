# Retrofit — content-management (frontend)

## Why
The content-management backend (pages, menus, themes, glossary) is already specified by
CMS-001..035 (Bucket 1), but the **frontend** management surface had no spec coverage.
This reverse-spec retroactively documents that observed behavior.

## What Changes
Adds 5 ADDED requirements (CMS-036..040) to the `content-management` capability and
annotates the implementing frontend code units with `@spec` tags. No code behavior changes.

The cluster has 13 present frontend files spanning four sub-domains (pages, menus, themes,
glossary) plus two service helpers. Capped at 5 REQs per the reverse-spec guardrail — one
REQ per sub-domain CRUD-UI family plus one for the shared helpers.

## Affected code units
- Pages: ViewPageModal.vue, PageContentForm.vue, DeletePageContentDialog.vue (CMS-036)
- Menus: ViewMenuModal.vue, MenuItemForm.vue, DeleteMenuItemModal.vue, CopyMenuDialog.vue (CMS-037)
- Themes: ViewThemeModal.vue, AddPublicationThemeModal.vue, DeleteMultipleThemesDialog.vue (CMS-038)
- Glossary: ViewGlossaryModal.vue (CMS-039)
- Helpers: services/getTheme.js, services/getPublicationTypeId.js (CMS-040)

## Approach
- For each unit: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)

## Observed notes
- Menu items and page content blocks are stored as **nested data** on the parent
  menu/page object; saving an item/block persists the whole parent via `updateObject`.
- `getTheme()` reads `data-theme-*` body attributes for theme detection. This is the
  Nextcloud theme-detection idiom (a client-side presentation concern), not the
  server-data DOM-dataset anti-pattern that ADR-004 / the initial-state gate forbid.

## Coverage-report drift
Report generated on `feature/declarative-annotation-pilot`. On `development`, the
`*Index.vue` / `*DetailPage.vue` shells for pages/menus/themes/glossary listed in the
report no longer exist (frontend refactor). The 13 modal/dialog/service files above are
present and form the basis of these REQs.

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
