# Retrofit — catalogs (frontend)

## Why
The catalogs backend is already specified by CAT-001..012 (Bucket 1), but the
catalog-specific **frontend** surface (the catalog Pinia store, create/edit modal, detail
view, and dashboard widget) had no spec coverage. This reverse-spec retroactively
documents that observed behavior.

## What Changes
Adds 4 ADDED requirements (CAT-013..016) to the `catalogs` capability and annotates the
implementing frontend code units with `@spec` tags. No code behavior changes.

## Affected code units
- src/store/modules/catalog.js (fetchPublications / setActiveCatalog / object-type registration)
- src/modals/catalog/CatalogModal.vue (create/edit)
- src/modals/catalog/ViewCatalogi.vue (view)
- src/views/catalogi/CatalogDetailPage.vue (detail page route)
- src/views/widgets/CatalogiWidget.vue + src/catalogiWidget.js (dashboard widget)

## Approach
- For each unit: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)

## Coverage-report drift
The report was generated on `feature/declarative-annotation-pilot`. On `development`,
`src/views/catalogi/CatalogiIndex.vue` listed in the report no longer exists; the other 6
catalog files are present and are the basis for these REQs.

## Observed note
`src/store/modules/catalog.js` is a bespoke Pinia store (`useCatalogStore`), which differs
from the createObjectStore pattern used elsewhere. This is recorded as observed behavior,
not changed here.

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
