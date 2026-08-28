---
retrofit: true
---

# Generic Object Modals Specification

**Status**: done
**Scope**: opencatalogi
**OpenSpec changes**:
- retrofit-2026-05-25-generic-object-modals

## Purpose

OpenCatalogi ships a set of type-agnostic frontend modals, dialogs and presentation components under `src/modals/object/`, `src/dialogs/` and `src/components/` that operate on whatever OpenRegister object the user has selected — independent of which capability (publications, catalogs, pages, themes, etc.) the object belongs to. They are orchestrated through the navigation/object Pinia stores (`navigationStore.modal`, `navigationStore.dialog`, `objectStore.objectItem`, `objectStore.selectedObjects`) and delegate all persistence and authorization to the object store / OpenRegister. No spec previously described this generic object-manipulation surface; this retrofit documents the observed behavior. Frontend conventions follow ADR-004; modal/dialog isolation follows the per-file convention.

## ADDED Requirements

### Requirement: Provide single-object lifecycle modals driven by the navigation store (GOM-001)
The system MUST provide generic single-object modals for viewing, editing, uploading, downloading and locking the object currently held in `objectStore.objectItem`. Each modal renders only when `navigationStore.modal` matches its key, performs its action through an `objectStore` method, surfaces success/error via `NcNoteCard`, and closes via `navigationStore.setModal(false)`. The object type is not hard-coded — the same modals serve any object the active view selected.

#### Scenario: User locks the active object
- GIVEN an object is set as `objectStore.objectItem` and `navigationStore.modal === 'lockObject'`
- WHEN the user submits an optional process name and duration and confirms
- THEN `objectStore.lockObject(id, process, duration)` is called
- AND on success a confirmation note is shown and the modal auto-closes after a short delay
- AND on failure the error message is shown and the modal stays open

#### Scenario: User views an object
- GIVEN an object is set as `objectStore.objectItem`
- WHEN the view-object modal opens
- THEN the object's properties, metadata and attachments are rendered read-only without requiring the caller to know the object's schema

### Requirement: Provide bulk (mass) object operations over the current selection (GOM-002)
The system MUST provide mass-operation modals (delete, depublish, publish, lock, unlock, validate) that act on every object in `objectStore.selectedObjects` rather than a single item. Each bulk modal reviews the selection, invokes the corresponding `objectStore` mass method, and reports per-item success and failure counts. When all items succeed it MAY auto-close; when any item fails it MUST keep the dialog open and report the failure count.

#### Scenario: User mass-deletes selected publications
- GIVEN one or more objects are present in `objectStore.selectedObjects`
- WHEN the user confirms the mass delete
- THEN `objectStore.massDeleteObjects(selection)` is invoked
- AND the result is partitioned into `successful` and `failed`
- AND if `failed` is empty the dialog auto-closes and the list is refreshed
- AND if `failed` is non-empty the dialog stays open and shows "Failed to delete N object(s)"

#### Scenario: Bulk action with empty selection
- GIVEN `objectStore.selectedObjects` is empty
- WHEN a mass-operation dialog is shown
- THEN the confirm action is disabled

### Requirement: Provide cross-object transformation modals (GOM-003)
The system MUST provide modals that transform one object into or against another: merging two objects (`objectStore.mergeObjects`), migrating an object between registers/schemas, and copying an object (`objectStore.copyObject`). These modals are multi-step (select target, review, perform) and refresh the affected object list on success.

#### Scenario: User merges two objects
- GIVEN a source object is active and the user searches for and selects a target object
- WHEN the merge is performed
- THEN `objectStore.mergeObjects(...)` is called with the resolved source and target
- AND on success the user can navigate to the merged object and the list is refreshed

### Requirement: Provide type-agnostic confirmation dialogs keyed by object type (GOM-004)
The system MUST provide generic confirmation dialogs (delete object, copy object, delete category, view audit log) that take the object type as a prop and resolve the active object, loading state and result through `objectStore.getActiveObject(type)`, `objectStore.isLoading(type)` and `objectStore.getState(type)`. The dialog renders a loading state, a confirmation prompt, and a terminal success/error note from store state, so the same dialog component serves any registered object type.

#### Scenario: User views an object's audit log
- GIVEN a log entry is the active `'log'` object
- WHEN the view-log dialog opens
- THEN the log content is rendered from `objectStore.getActiveObject('log').content`
- AND loading and error states are derived from `objectStore.getState('log')`

#### Scenario: Delete confirmation suppresses configuration-error noise
- GIVEN the delete-object dialog is open for a type with an invalid configuration
- WHEN the store state error equals "Invalid configuration for object type: …"
- THEN that specific error is not surfaced as a user-facing error note

### Requirement: Provide shared object-presentation components (GOM-005)
The system MUST provide reusable presentation components used across capabilities — a generic object table, a properties panel, a markdown editor, a pagination control, a publication card, a published-status icon, an attachment picker and a multi-selection list. These components accept generic object/collection data and emit selection/navigation events, leaving data fetching and persistence to the calling view and the object store.

#### Scenario: Generic table lists objects of any type
- GIVEN a view passes a collection of OpenRegister objects to the generic object table
- WHEN the table renders
- THEN rows and columns are derived from the supplied objects without the component hard-coding a specific schema
- AND row selection updates the shared selection used by bulk operations (REQ-002)

## Non-Functional Requirements

- **Accessibility:** Modals and dialogs MUST use `NcDialog`/`NcModal` so focus trapping and ARIA roles are handled by the Nextcloud Vue library (ADR-004, WCAG 2.1 AA).
- **Internationalization:** All user-facing strings MUST be wrapped in `t('opencatalogi', …)`; Dutch and English MUST be supported (ADR-007).
- **Authorization:** These components MUST delegate all authorization to the object store / OpenRegister; they MUST NOT implement their own access checks (ADR-022).

## Acceptance Criteria

- [x] Single-object lifecycle modals render off `navigationStore.modal` and act through `objectStore`
- [x] Bulk modals operate over `objectStore.selectedObjects` and report partial failure
- [x] Transformation modals (merge/migrate/copy) are multi-step and refresh on success
- [x] Confirmation dialogs are type-agnostic via an `objectType` prop and store state
- [x] Shared presentation components are schema-agnostic

## Notes

- **Observed, not aspirational.** Several modals hard-code user-facing labels in English in computed properties (e.g. `MassDeleteObject.dialogTitle` returns `'Delete publication'` outside `t()`); these strings bypass i18n. Flagged for future tightening — not changed here.
- **Security note (delegated authorization).** `LockObject` and the mass-operation modals act on `objectStore.objectItem.id` / `objectStore.selectedObjects` with no client-side authorization gate. This is the intended design — authorization is enforced server-side by OpenRegister (ADR-022) — but it means the frontend will optimistically issue the request and rely on the backend to reject unauthorized operations. No silent-fail or fail-open was observed in the modal layer; errors from the store are surfaced to the user.
- `category` and `publicationType` referenced by `DeleteCategoryDialog` / related code are not enumerated in any capability spec's object-type list (coverage-report note). Documented here as observed; whether they should be first-class object types is a separate decision.
