# Design — Retrofit content-management (frontend)

**Retrofit change. Tasks describe retroactive annotation, not new implementation work.**

No code behavior changes. Documents already-shipped frontend behavior of the
content-management cluster and attaches `@spec` annotations.

## Code units → REQ map
| REQ | Code units |
|---|---|
| CMS-036 | ViewPageModal.vue, PageContentForm.vue, DeletePageContentDialog.vue |
| CMS-037 | ViewMenuModal.vue, MenuItemForm.vue, DeleteMenuItemModal.vue, CopyMenuDialog.vue |
| CMS-038 | ViewThemeModal.vue, AddPublicationThemeModal.vue, DeleteMultipleThemesDialog.vue |
| CMS-039 | ViewGlossaryModal.vue |
| CMS-040 | services/getTheme.js, services/getPublicationTypeId.js |

## Notes
- The content-management backend is already specified (CMS-001..035) and Bucket-1 annotated.
- Menu items / page content blocks persist as nested data on the parent object.
- `getTheme()` uses the Nextcloud `data-theme-*` body-attribute idiom (presentation), not
  the server-data DOM-dataset anti-pattern.
