# Design — Retrofit catalogs (frontend)

**Retrofit change. Tasks describe retroactive annotation, not new implementation work.**

No code behavior changes. Documents already-shipped frontend behavior of the catalogs
cluster and attaches `@spec` annotations.

## Code units → REQ map
| REQ | Code unit |
|---|---|
| CAT-013 | `src/store/modules/catalog.js` (fetchPublications / setActiveCatalog) |
| CAT-014 | `src/modals/catalog/CatalogModal.vue` (saveCatalog) |
| CAT-015 | `src/modals/catalog/ViewCatalogi.vue`, `src/views/catalogi/CatalogDetailPage.vue` |
| CAT-016 | `src/views/widgets/CatalogiWidget.vue`, `src/catalogiWidget.js` |

## Notes
- The catalogs backend is already specified (CAT-001..012) and Bucket-1 annotated.
- `catalog.js` is a bespoke Pinia store (observed, not changed).
