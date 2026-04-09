# Proposal: enhance-amef-export-org-data

## Summary

The organisation-specific AMEF export (`/api/archimate/export/organization`) currently generates application elements and specialization relationships from an organisation's `gebruik` and `module` data, but does **not** include deelname (participation) data — which organisations use, own, or provide which applications. Additionally, the export endpoint is a POST that reads a JSON body, whereas it should be a simple GET download endpoint with query parameters to toggle which data layers to include.

## Motivation

The AMEF export is meant to provide a complete picture of an organisation's application landscape for use in architecture tools like Archi. Without deelname data, the export is incomplete — it only shows *what* applications exist and which reference components they map to, but not *who* uses, provides, or participates in them. This data is critical for municipal architects who need to understand their organisation's actual software usage.

The ViewService already has partial scaffolding for deelnames (`shouldIncludeDeelnamesGebruik`, `getDeelnamesGebruikData`, `getNodeDeelnamesGebruik`) but these are all stub/placeholder implementations returning empty arrays.

The API design also needs improvement: an export that returns a file download is semantically a GET request, not a POST. Using query parameters (`?modules=true&deelnames=true&gebruik=true`) makes the endpoint bookmarkable, cacheable, and simpler to integrate.

### Related GitHub Issues

- [#72 - Exporteren ArchiMate](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/72) — Main export feature (88% sub-issues done). Outstanding: org-specific folders, municipality views, end-to-end testing.
- [#71 - Importeren ArchiMate](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/71) — Import prerequisite (66% done). Must import GEMMA model before export can be tested.
- [#70 - Ontsluiten architectuur concepten](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/70) — Making GEMMA architecture concepts available for filtering/search (API testability).
- [#135 - Valideren non-functionele eisen](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/135) — Performance: Zeist view with 271 packages must render in <=11 seconds.
- [#160 - Performance plotten views](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/160) — Zeist test case: 261 packages in 11 seconds.

## Affected Projects

- [x] Project: `softwarecatalog` — Backend export service, controller, routes, frontend export UI

## Scope

### In Scope

1. **Implement deelname data in org export** — A deelname is a gebruik object where the current organisation's UUID appears in its `deelnemers` property (queried with RBAC disabled via ObjectService). Include these in the AMEF XML output in separate organisation-specific folders per type.
2. **Implement `getDeelnamesGebruikData()` and `getNodeDeelnamesGebruik()`** — Complete the stub implementations in ViewService using the same pattern as `getGebruikData()` step 2 (query gebruik with `'deelnemers' => $currentOrg`, RBAC off).
3. **Separate folders per relationship type** — The export organises organisation-specific data into distinct folders:
   - `Gebruikt (Softwarecatalogus)` — applications the org uses (own gebruik)
   - `Aangeboden (Softwarecatalogus)` — applications the org provides
   - `Deelnames (Softwarecatalogus)` — applications the org participates in (deelnemers)
4. **Replace export endpoint with GET** — Remove `POST /api/archimate/export/organization` and replace with `GET /api/archimate/export/organization/{organizationUuid}` with query parameters:
   - `?modules=true` — include module elements and specialization relationships (current default behavior)
   - `?deelnames=true` — include deelname/participation data
   - `?gebruik=true` — include gebruik (usage) data
5. **Update frontend** — Adapt `ArchiMateImportExport.vue` to use the new GET endpoint with query parameters instead of POST with JSON body.
6. **Test with real data** — Import `GEMMA release.xml` and test with Zeist (~271 packages).

### Out of Scope

- Changes to the AMEF import functionality (already 66% done, separate concern)
- Performance optimizations for view plotting (#160 — separate issue)
- Non-functional validation items from #135 (accessibility, security, etc.)
- Changes to the base (non-organisation) AMEF export
- New ArchiMate views or view layouts

## Approach

### Current State

| Component | Status |
|-----------|--------|
| `ArchiMateExportService.exportOrganizationArchiMateXml()` | Works — generates app elements + specialization relationships from gebruik/modules |
| `ArchiMateService.exportOrgArchiMate()` | Works — fetches org + gebruik + modules, delegates to export service |
| `SettingsController.exportOrgArchiMate()` | Works — POST endpoint, reads JSON body |
| `ViewService.getDeelnamesGebruikData()` | **Stub** — returns `[]` |
| `ViewService.getNodeDeelnamesGebruik()` | **Stub** — returns `[]` |
| `ViewService.getGebruikData()` | Partial — has step 2 deelnames logic (RBAC-off query) but not wired to export |
| Frontend export UI | Works — calls POST endpoint with JSON body |

### Proposed Changes

1. **Backend: Implement deelname data retrieval** — Complete the ViewService stubs by querying gebruik objects where `deelnemers` contains the current org UUID (RBAC disabled). The pattern already exists in `getGebruikData()` step 2.

2. **Backend: Add deelname data to XML export** — In `ArchiMateExportService`, generate additional application elements and specialization relationships from deelname gebruik, and place them in separate typed folders (`Gebruikt`, `Aangeboden`, `Deelnames`).

3. **Backend: Replace with GET endpoint** — Remove `POST /api/archimate/export/organization` route entirely. Add `GET /api/archimate/export/organization/{organizationUuid}` with boolean query params (`modules`, `deelnames`, `gebruik`).

4. **Frontend: Update fetch call** — Switch from POST+JSON to simple `window.location` or `fetch` GET with query string.

## Cross-Project Dependencies

- **OpenRegister** — Used for querying objects (ObjectService). No changes needed in OpenRegister itself.
- **Voorzieningen data model** — Deelnames are stored as gebruik objects; the `gebruik_schema` must be configured in voorzieningen config (already is).

## Rollback Strategy

- ViewService stub implementations can be reverted to return `[]` to disable deelname data without breaking the export.
- Frontend changes are isolated to `ArchiMateImportExport.vue`.
- If the GET endpoint causes issues, the POST route can be re-added quickly.

## Decisions

1. **Deelname data model** — A deelname is a gebruik object where the current organisation's UUID is in the `deelnemers` property. Queried with RBAC disabled via ObjectService (same pattern as `ViewService.getGebruikData()` step 2).
2. **Folder structure** — Separate folders per relationship type: `Gebruikt (Softwarecatalogus)`, `Aangeboden (Softwarecatalogus)`, `Deelnames (Softwarecatalogus)`.
3. **Test organisation** — Zeist (~271 packages, benchmark from issues #135 and #160).
4. **Backward compatibility** — Replace POST endpoint entirely with GET. No legacy endpoint.
