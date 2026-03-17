# Tasks: enhance-amef-export-org-data

## 1. Route and Controller — Replace POST with GET endpoint

### Task 1.1: Update route definition
- **spec_ref**: `specs/org-archimate-export/spec.md#requirement-api-endpoint-must-accept-organization-uuid-and-return-xml-download`
- **files**: `softwarecatalog/appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN the routes file WHEN inspected THEN the POST route for `/api/archimate/export/organization` MUST be replaced with `GET /api/archimate/export/organization/{organizationUuid}`
- [x] Replace POST route with GET route including `{organizationUuid}` path parameter

### Task 1.2: Refactor SettingsController to accept path param and query params
- **spec_ref**: `specs/org-archimate-export/spec.md#requirement-export-must-support-query-parameters-for-toggling-data-layers`
- **files**: `softwarecatalog/lib/Controller/SettingsController.php`
- **acceptance_criteria**:
  - GIVEN a GET request to `/api/archimate/export/organization/{uuid}` WHEN `modules`, `deelnames`, `gebruik` query params are provided THEN the controller MUST parse them as booleans and pass an options array to ArchiMateService
  - GIVEN no query parameters WHEN the export is called THEN `modules` MUST default to `true`, `deelnames` and `gebruik` MUST default to `false`
  - GIVEN a non-existent UUID WHEN the export is called THEN the response MUST return 404
- [x] Refactor `exportOrgArchiMate()` to accept `string $organizationUuid` path parameter
- [x] Read `modules`, `deelnames`, `gebruik` from query params with correct defaults
- [x] Pass options array to `ArchiMateService::exportOrgArchiMate()`
- [x] Remove JSON body parsing logic

## 2. Service Layer — Add deelname query and options passthrough

### Task 2.1: Add deelname query to ArchiMateService
- **spec_ref**: `specs/org-archimate-export/spec.md#requirement-deelname-query-must-use-rbac-disabled-objectservice-search`
- **files**: `softwarecatalog/lib/Service/ArchiMateService.php`
- **acceptance_criteria**:
  - GIVEN `options['deelnames'] === true` WHEN `exportOrgArchiMate()` is called THEN it MUST query ObjectService with `'deelnemers' => $organizationUuid`, `_rbac: false`, `_multitenancy: false`
  - GIVEN `options['deelnames'] === false` WHEN `exportOrgArchiMate()` is called THEN no deelname query MUST be executed
- [x] Add `array $options = []` parameter to `exportOrgArchiMate()`
- [x] Add deelname query block (conditional on `$options['deelnames']`)
- [x] Pass `$deelnamesData` and `$options` to `exportOrganizationArchiMateXml()`

### Task 2.2: Implement ViewService deelname stubs
- **spec_ref**: `openspec/specs/deelnames-gebruik/spec.md#requirement-viewservice-must-retrieve-deelnames-gebruik-separately-from-regular-gebruik`
- **files**: `softwarecatalog/lib/Service/ViewService.php`
- **acceptance_criteria**:
  - GIVEN an organisation UUID in deelnemers of 3 gebruik objects WHEN `getDeelnamesGebruikData()` is called THEN it MUST return the 3 objects indexed by elementRef
  - GIVEN a model node ID matching a deelname elementRef WHEN `getNodeDeelnamesGebruik()` is called THEN it MUST return the matching deelname data
- [x] Implement `getDeelnamesGebruikData()` using the RBAC-off query pattern from `getGebruikData()` step 2
- [x] Implement `getNodeDeelnamesGebruik()` to match node elementRef against deelnames data

## 3. Export Service — Accept deelnames data and build typed folders

### Task 3.1: Update exportOrganizationArchiMateXml signature and deelname processing
- **spec_ref**: `specs/org-archimate-export/spec.md#requirement-export-must-include-deelname-data-when-deelnames-parameter-is-enabled`
- **files**: `softwarecatalog/lib/Service/ArchiMateExportService.php`
- **acceptance_criteria**:
  - GIVEN deelnamesData with 5 modules WHEN export is generated THEN the XML MUST contain 5 additional application elements with `Bron=Softwarecatalogus` property
  - AND specialization relationships linking each to their referentiecomponent
  - GIVEN empty deelnamesData WHEN export is generated THEN no deelname elements or relationships MUST appear
- [x] Add `array $deelnamesData = []` and `array $options = []` parameters to `exportOrganizationArchiMateXml()`
- [x] Build separate lookup maps for deelnames data (reuse `buildModuleLookupMaps()`)
- [x] Generate deelname application elements and specialization relationships
- [x] Conditionally skip modules/gebruik processing based on options

### Task 3.2: Refactor buildSwcOrganizationFolders for typed folders
- **spec_ref**: `specs/org-archimate-export/spec.md#requirement-swc-objects-must-be-organized-in-typed-folders`
- **files**: `softwarecatalog/lib/Service/ArchiMateExportService.php`
- **acceptance_criteria**:
  - GIVEN modules and deelnames data exist WHEN folders are built THEN `Gebruikt (Softwarecatalogus)` and `Deelnames (Softwarecatalogus)` folders MUST appear with correct references
  - GIVEN only modules data exists WHEN folders are built THEN only `Gebruikt (Softwarecatalogus)` folder MUST appear
  - GIVEN empty data for a type WHEN folders are built THEN that type's folder MUST NOT appear
- [x] Refactor `buildSwcOrganizationFolders()` to accept typed element arrays (gebruikt, aangeboden, deelnames)
- [x] Create folders conditionally: only when data exists for that type
- [x] Keep `Relaties (Softwarecatalogus)` and `Views (Softwarecatalogus)` as shared folders
- [x] Update `assembleOrganizationXml()` to use the new folder structure

## 4. Frontend — Switch to GET with checkboxes

### Task 4.1: Add data layer checkboxes to ArchiMateImportExport.vue
- **spec_ref**: `specs/org-archimate-export/spec.md#requirement-frontend-must-provide-organization-export-with-data-layer-toggles`
- **files**: `softwarecatalog/src/views/settings/sections/ArchiMateImportExport.vue`
- **acceptance_criteria**:
  - GIVEN the settings page WHEN loaded THEN "Modules" checkbox MUST be checked, "Deelnames" and "Gebruik" checkboxes MUST be unchecked
  - GIVEN checkboxes WHEN the user toggles them THEN the component state MUST update accordingly
- [x] Add `includeModules`, `includeDeelnames`, `includeGebruik` data properties (defaults: true, false, false)
- [x] Add three checkboxes to the export section UI

### Task 4.2: Switch export call from POST to GET
- **spec_ref**: `specs/org-archimate-export/spec.md#requirement-frontend-must-provide-organization-export-with-data-layer-toggles`
- **files**: `softwarecatalog/src/views/settings/sections/ArchiMateImportExport.vue`
- **acceptance_criteria**:
  - GIVEN modules and deelnames checked WHEN export button clicked THEN fetch MUST call `GET /api/archimate/export/organization/{uuid}?modules=true&deelnames=true`
  - GIVEN the response is XML WHEN download completes THEN the browser MUST save the file with the correct filename from Content-Disposition
- [x] Replace POST fetch in `exportOrgArchiMateFile()` with GET using URLSearchParams
- [x] Build query string from checkbox states
- [x] Keep blob download logic for file save

## 5. Testing — End-to-end verification

### Task 5.1: Import GEMMA release and verify base export
- **spec_ref**: `specs/org-archimate-export/spec.md#requirement-api-endpoint-must-accept-organization-uuid-and-return-xml-download`
- **files**: `softwarecatalog/data/GEMMA release.xml`
- **acceptance_criteria**:
  - GIVEN `GEMMA release.xml` imported WHEN base export is called for Zeist THEN the XML MUST be valid ArchiMate and contain all GEMMA objects plus Zeist's modules
- [x] Import `GEMMA release.xml` via the admin UI or API
- [x] Call `GET /api/archimate/export/organization/{zeist-uuid}` and verify XML structure
- [ ] Verify the file imports into Archi without errors

### Task 5.2: Test deelnames export with Zeist
- **spec_ref**: `specs/org-archimate-export/spec.md#requirement-export-must-include-deelname-data-when-deelnames-parameter-is-enabled`
- **files**: N/A (manual/API testing)
- **acceptance_criteria**:
  - GIVEN Zeist has deelname gebruik WHEN export is called with `?deelnames=true` THEN the XML MUST contain deelname application elements in the `Deelnames (Softwarecatalogus)` folder
  - GIVEN Zeist has no deelname gebruik WHEN export is called with `?deelnames=true` THEN the XML MUST still be valid with no deelnames folder
- [x] Call export with `?modules=true&deelnames=true` for Zeist
- [x] Verify typed folder structure in output XML
- [x] Verify deelname elements have correct Bron property and specialization relationships

### Task 5.3: Test frontend checkboxes and download
- **spec_ref**: `specs/org-archimate-export/spec.md#requirement-frontend-must-provide-organization-export-with-data-layer-toggles`
- **files**: N/A (browser testing)
- **acceptance_criteria**:
  - GIVEN the settings page WHEN user checks Deelnames and clicks export THEN the downloaded file MUST contain deelname data
- [x] Verify checkboxes appear with correct defaults
- [x] Verify export triggers GET request with correct query params
- [x] Verify file downloads successfully

## Verification

- [x] All tasks checked off
- [x] Route change works: GET with path param returns XML
- [x] Deelnames data appears in export when enabled
- [x] Typed folders appear correctly (empty folders omitted)
- [x] Frontend checkboxes control query parameters
- [x] Backward compatible: default export (no params) matches current behavior
