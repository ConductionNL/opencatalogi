# Proposal: org-archimate-export

## Summary

Extend the softwarecatalog ArchiMate export to produce a complete organization-enriched AMEFF XML file: the base GEMMA model plus the organization's applications plotted on referentiecomponenten, organized into typed folders (Gebruikt, Aangeboden, Deelnames, Relaties, Views), with deelnames data layer support, configurable via boolean query parameters, and downloadable via a GET endpoint. The frontend receives data-layer toggle checkboxes and a loading state on the export button.

## Motivation

The softwarecatalog has a partial ArchiMate export (`GET /api/archimate/export/organization/{uuid}`) that includes module elements and specialization relationships but is missing several capabilities that block real-world adoption:

- **No deelnames layer**: Municipalities that participate in shared software ("deelnames") cannot see those participations in their ArchiMate export. Architects working on inter-municipal models have an incomplete picture.
- **Flat folder structure**: All SWC-added objects land in generic folders. Archi users cannot distinguish "software we use ourselves" from "software we provide" from "participations in others' software" without inspecting individual properties.
- **Boolean parameter parsing is brittle**: The API only accepts `true` (lowercase). URLs produced by common tools (`?modules=1`, `?modules=yes`) silently produce wrong results.
- **No file name sanitization**: Organization names containing `'`, `-`, or spaces (e.g., "Gemeente 's-Hertogenbosch") produce illegal or malformed download filenames.
- **No frontend layer toggles**: The export button has no checkboxes; users cannot control which data layers appear in the export without crafting URLs by hand.

## Affected Projects

- [x] **softwarecatalog** — Backend controller/service extension, frontend Vue component update

## Scope

### In Scope

**Deelnames data layer:**
- Query gebruik objects owned by other organisations where the current organisation appears in the `deelnemers` field (RBAC disabled, `_multitenancy: false`)
- Generate `ApplicationComponent` elements and `SpecializationRelationship` entries for deelname modules
- Place deelname elements in `Deelnames (Softwarecatalogus)` folder (only when non-empty)

**Typed organization folders:**
Replace the current flat `Applicaties (Softwarecatalogus)` folder with per-type subfolders under the org label:
- `Gebruikt (Softwarecatalogus)` — org's own use
- `Aangeboden (Softwarecatalogus)` — org provides
- `Deelnames (Softwarecatalogus)` — org participates
- `Relaties (Softwarecatalogus)` — all SWC relationships
- `Views (Softwarecatalogus)` — all SWC view copies
Empty folders are omitted.

**Boolean parameter normalization:**
Accept `1`, `yes`, `true`, `TRUE` (and any case variant) as truthy for `modules`, `deelnames`, `gebruik`.

**File name sanitization:**
Sanitize the organization name in the Content-Disposition filename (replace `'`, spaces, and non-ASCII with underscores or hyphens). The model `<name>` inside the XML preserves the original.

**Frontend data-layer checkboxes:**
Three `NcCheckboxRadioSwitch` (or equivalent) controls in the ArchiMate settings section: Modules (default on), Deelnames (default off), Gebruik (default off). Export button shows a spinner while the download is in progress.

**Connection and view requirements (already partially implemented — verify and harden):**
All existing requirements around view copying, node positioning, `<connection>` elements, `Titel view SWC` naming, and `Bron` property definitions are confirmed in scope.

### Out of Scope

- Modifying the base (non-organization) ArchiMate export
- Round-trip import of enriched XML back into softwarecatalog
- Product data enrichment in the export
- Streaming large exports — current in-memory approach is sufficient for known data volumes

## Approach

1. **Controller**: Extend `SettingsController::exportOrgArchiMate()` to parse boolean query params with liberal truthy detection; sanitize org name for Content-Disposition.
2. **ArchiMateService**: Add conditional deelname query (`ObjectService::searchObjects` with `_rbac: false`, `'deelnemers' => $uuid`); pass typed data arrays and options to export service.
3. **ArchiMateExportService**: Accept `$deelnamesData` and `$options`; refactor `buildSwcOrganizationFolders()` to produce typed subfolders; conditionally process each data layer.
4. **Frontend**: Add three checkbox data properties and bind to `URLSearchParams`; add loading state on the export button using `try/finally`.

## Cross-Project Dependencies

- **OpenRegister** `ObjectService` — existing dependency for querying modules, gebruik, and deelnames
- **deelnames-gebruik** spec — deelname query pattern (`getDeelnamesGebruikData()`)
- **view-enrichment-api** spec — module-to-referentiecomponent matching logic (reused pattern)

## Rollback Strategy

All changes are additive or parameter-controlled refactors on existing endpoints:
- Default behavior (no query params) is preserved — `modules=true` stays the default
- No schema or data migrations required
- Rolling back means reverting the service/controller changes; the base export is unaffected

## Open Questions

- Should the `Aangeboden (Softwarecatalogus)` folder be populated in this change, or is aangeboden data retrieval deferred? (Context brief references it in folder structure but not in data layer toggle scenarios — treat as conditionally present, populated when gebruik data is enabled and "aangeboden" usage type exists.)
- What is the cap for applications per referentiecomponent node before view layout breaks? (Existing code caps at 20; preserve as-is.)
