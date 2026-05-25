# Design — generic-object-modals (retrofit)

**Retrofit change. Tasks describe retroactive annotation, not new implementation work.** The code already exists on `development`; this document records the design as observed.

## Context

OpenCatalogi's frontend manipulates OpenRegister objects belonging to many capabilities (publications, catalogs, pages, themes, menus, glossary, organizations). Rather than duplicating view/edit/delete/lock UI per capability, the app centralises object manipulation in a generic, type-agnostic modal/dialog/component layer wired through two Pinia stores:

- `navigationStore` — `modal` / `dialog` keys decide which overlay renders; `setModal()` / `setDialog()` open/close.
- `objectStore` — holds `objectItem` (active single object) and `selectedObjects` (multi-selection), and exposes all persistence + lifecycle methods (`lockObject`, `massDeleteObjects`, `mergeObjects`, `copyObject`, `getActiveObject(type)`, `getState(type)`, `isLoading(type)`).

## Behavior classes (→ REQ map)

| Class | Units | REQ |
|---|---|---|
| Single-object lifecycle | View/Object/Upload/Download/Lock | GOM-001 |
| Bulk operations over selection | MassDelete/Depublish/Publish/Lock/Unlock/Validate | GOM-002 |
| Cross-object transformation | Merge/Migration/Copy | GOM-003 |
| Type-agnostic confirmation dialogs | DeleteObject/ViewLog/DeleteCategory(+Multiple) | GOM-004 |
| Shared presentation components | GenericObjectTable/PropertiesPanel/MarkdownEditor/Pagination/PublicationCard/PublishedIcon/SelectAttachmentsList/SelectedObjectsList/EntityDetailPage | GOM-005 |

## Decisions (observed)

- **Type agnosticism via store state, not props/inheritance.** Modals read the active object from the store rather than receiving a typed prop, so adding a new object type needs no new modal. Dialogs that do take a type take it as a plain `objectType` string and resolve everything through `objectStore.getState(type)`.
- **Partial-failure handling in bulk ops.** Mass methods return `{ successful, failed }`; the dialog stays open on any failure and reports the count, rather than treating the batch as all-or-nothing.
- **Authorization delegated to OpenRegister (ADR-022).** No client-side auth gate; the store issues the request and the user sees server-returned errors. Not a fail-open — see spec Notes.

## Known issues surfaced (not fixed here)

- Some computed labels (e.g. `MassDeleteObject.dialogTitle`) return English strings outside `t()`, bypassing i18n (ADR-007). Documented in spec Notes for a future tightening change.
