# Design — Retrofit dashboard (frontend)

**Retrofit change. Tasks describe retroactive annotation, not new implementation work.**

No code behavior changes. Documents already-shipped frontend behavior of the dashboard
cluster (SPA shell, overview, widgets, directory + listing management) and attaches `@spec`
annotations.

## Code units → REQ map
| REQ | Code units |
|---|---|
| DSH-009 | App.vue, navigation/MainMenu.vue |
| DSH-010 | views/dashboard/Dashboard.vue, sidebars/dashboard/DashboardSideBar.vue |
| DSH-011 | UnpublishedAttachmentsWidget.vue + .js, UnpublishedPublicationsWidget.vue + .js |
| DIR-012 | sidebars/directory/DirectorySideBar.vue, modals/directory/AddDirectoryModal.vue, modals/directory/ViewDirectoryModal.vue |
| LST-007 | modals/listing/EditListingModal.vue, modals/directory/EditListingModal.vue, dialogs/listing/DeleteListingDialog.vue |

## Notes
- `App.vue` is a manifest-driven `CnAppRoot` shell (ADR-024 Tier-4).
- `EditListingModal.vue` is duplicated (directory vs listing target); observed, not changed.
