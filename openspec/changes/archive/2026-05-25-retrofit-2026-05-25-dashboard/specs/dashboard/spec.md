---
retrofit_extensions:
  - DSH-009
  - DSH-010
  - DSH-011
  - DIR-012
  - LST-007
---

# Dashboard

## ADDED Requirements

### Requirement: Manifest-driven SPA shell and main navigation (DSH-009)
The frontend SPA SHALL render through a manifest-driven `CnAppRoot` shell (`App.vue`) for
app id `opencatalogi`, passing the app manifest, custom components, page types, a
per-app translate closure, and a computed `permissions` array. The permissions computed
augments `window.OC.currentUser.permissions` with an `'admin'` entry when
`window.OC.isUserAdmin()` is true (so manifest entries gated on `permission: "admin"`
resolve). On `created()` it preloads object collections via
`objectStore.preloadCollections()` so navigation items and catalog-slug routes resolve on
first render. `MainMenu.vue` provides the in-app navigation.

**Priority:** Must **Status:** Implemented

#### Scenario: Render the SPA shell for an admin user
- GIVEN `window.OC.isUserAdmin()` returns true
- WHEN `App.vue` mounts
- THEN the computed `permissions` MUST include `'admin'`
- AND object collections MUST be preloaded via `objectStore.preloadCollections()`

### Requirement: Dashboard overview view (DSH-010)
The system SHALL provide a `Dashboard.vue` overview that, on load, fetches the catalog
collection (`objectStore.fetchCollection('catalog')`), the total publication count
(`GET /apps/opencatalogi/api/publications?_page=1&_limit=1000&_extend=@self.schema,@self.register`,
storing `data.total`), and an activity chart, surfacing a load error message on failure.
A `DashboardSideBar` accompanies the view.

**Priority:** Should **Status:** Implemented

#### Scenario: Load dashboard data
- GIVEN the dashboard view mounts
- WHEN data loading runs
- THEN catalogs, the publication total, and the activity chart MUST be fetched
- AND a user-facing error message MUST be shown if any fetch rejects

### Requirement: Unpublished-content dashboard widgets (DSH-011)
The system SHALL provide two Nextcloud dashboard widgets —
`UnpublishedAttachmentsWidget` (fetches `attachment` collection) and
`UnpublishedPublicationsWidget` (fetches `publication` collection) — each registered via
its own bundle entry-point (`unpublishedAttachmentsWidget.js`,
`unpublishedPublicationsWidget.js`) and rendering the unpublished items.

**Priority:** Should **Status:** Implemented

#### Scenario: Load unpublished widgets
- GIVEN the dashboard renders the unpublished widgets
- WHEN each widget mounts
- THEN `UnpublishedAttachmentsWidget` MUST fetch the `attachment` collection
- AND `UnpublishedPublicationsWidget` MUST fetch the `publication` collection

### Requirement: Directory management UI (DIR-012)
The system SHALL provide a directory management frontend: a `DirectorySideBar`, an
`AddDirectoryModal` that registers an external directory by POSTing the directory URL to
`/apps/opencatalogi/api/directory` (default placeholder
`https://directory.opencatalogi.nl/apps/opencatalogi/api/directory`), and a
`ViewDirectoryModal` for inspecting a directory entry. Modals are toggled through the
navigation store.

**Priority:** Should **Status:** Implemented

#### Scenario: Add an external directory
- GIVEN the add-directory modal is open with a directory URL
- WHEN the user confirms
- THEN a POST MUST be sent to `/apps/opencatalogi/api/directory` with the URL
- AND the modal MUST close on success

### Requirement: Listing management UI (LST-007)
The system SHALL provide listing management dialogs: an `EditListingModal` (present in two
locations — `modals/listing` editing the `listing` type and `modals/directory` editing the
`directory` type — that save via `objectStore.updateObject(...)` then refresh the relevant
collection) and a `DeleteListingDialog` that removes a listing via
`objectStore.deleteObject('listing', id)`.

**Priority:** Should **Status:** Implemented

#### Scenario: Edit a listing
- GIVEN the listing edit modal is open
- WHEN the user saves
- THEN the listing MUST be persisted via `objectStore.updateObject(...)` and the collection refreshed

#### Scenario: Delete a listing
- GIVEN a listing is selected for deletion
- WHEN the delete-listing dialog is confirmed
- THEN the listing MUST be removed via `objectStore.deleteObject('listing', id)`

> **Notes (observed duplication — not fixed by this retrofit):**
> `EditListingModal.vue` exists twice — `src/modals/directory/EditListingModal.vue`
> (targets `directory`) and `src/modals/listing/EditListingModal.vue` (targets `listing`).
> The coverage report flags this as duplicated. LST-007 specifies the observed behavior of
> both; de-duplication is a code change tracked separately, not resolved here.
