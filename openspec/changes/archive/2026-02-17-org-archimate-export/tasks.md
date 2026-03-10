# Tasks: org-archimate-export

## 1. Route and Controller Setup

### Task 1: Add API route and controller method
- **spec_ref**: `specs/org-archimate-export/spec.md#requirement-api-endpoint-must-accept-organization-uuid-and-return-xml-download`
- **files**: `softwarecatalog/appinfo/routes.php`, `softwarecatalog/lib/Controller/SettingsController.php`
- **acceptance_criteria**:
  - GIVEN a POST to `/api/archimate/export/organization` with `{"organization": "uuid"}` WHEN the endpoint is called THEN it returns 200 with XML or appropriate error codes (400/404)
- [x] 1.1 Add route `POST /api/archimate/export/organization` to `routes.php`
- [x] 1.2 Add `exportOrgArchiMate()` method to `SettingsController` with input validation (missing UUID → 400, not found → 404)

## 2. Service Orchestrator

### Task 2: Add orchestrator method in ArchiMateService
- **spec_ref**: `specs/org-archimate-export/spec.md#requirement-export-must-produce-valid-archimate-xml-with-organization-applications`
- **files**: `softwarecatalog/lib/Service/ArchiMateService.php`
- **acceptance_criteria**:
  - GIVEN a valid organization UUID WHEN `exportOrgArchiMate()` is called THEN it retrieves the organization, gets AMEF config, and delegates to ArchiMateExportService
- [x] 2.1 Add `exportOrgArchiMate(string $organizationUuid): array` to `ArchiMateService`
- [x] 2.2 Method must query the organization from Voorzieningen register, validate it exists, get AMEF config, create schema ID map, and call the export service

## 3. Query Organization Modules and Gebruik

### Task 3: Query organization's modules and referentiecomponent mappings
- **spec_ref**: `specs/org-archimate-export/spec.md#requirement-specializationrelationship-must-link-applications-to-referentiecomponenten`
- **files**: `softwarecatalog/lib/Service/ArchiMateExportService.php`
- **acceptance_criteria**:
  - GIVEN an organization UUID WHEN modules are queried THEN all modules with their referentiecomponent mappings are returned, indexed for lookup
- [x] 3.1 Add `getOrganizationModules()` helper that queries modules from Voorzieningen register filtered by organization
- [x] 3.2 Build a lookup map: `moduleId → [referentiecomponentIdentifiers]` and `moduleId → moduleName`

## 4. Generate Application Elements

### Task 4: Create ArchiMate ApplicationComponent elements for modules
- **spec_ref**: `specs/org-archimate-export/spec.md#requirement-application-elements-must-be-applicationcomponent-type-with-bron-property`
- **files**: `softwarecatalog/lib/Service/ArchiMateExportService.php`
- **acceptance_criteria**:
  - GIVEN modules data WHEN application elements are generated THEN each has `xsi:type="ApplicationComponent"`, unique `id-swc-app-` identifier, name, and `Bron=Softwarecatalogus` property
- [x] 4.1 Add `generateApplicationElements()` that creates element XML data arrays for each module
- [x] 4.2 Each element must have identifier `id-swc-app-{moduleId}`, ApplicationComponent type, name from module, and Bron property

## 5. Generate Specialization Relationships

### Task 5: Create SpecializationRelationship elements
- **spec_ref**: `specs/org-archimate-export/spec.md#requirement-specializationrelationship-must-link-applications-to-referentiecomponenten`
- **files**: `softwarecatalog/lib/Service/ArchiMateExportService.php`
- **acceptance_criteria**:
  - GIVEN module-to-referentiecomponent mappings WHEN relationships are generated THEN each produces a SpecializationRelationship with unique id, correct source/target, and Bron property
- [x] 5.1 Add `generateSpecializationRelationships()` that creates relationship XML data arrays
- [x] 5.2 Each relationship has identifier `id-swc-rel-{moduleId}-{refCompId}`, source=app element, target=refcomp element, Bron property

## 6. Bron Property Definition

### Task 6: Ensure Bron property definition exists
- **spec_ref**: `specs/org-archimate-export/spec.md#requirement-bron-property-definition-must-be-added-to-the-model`
- **files**: `softwarecatalog/lib/Service/ArchiMateExportService.php`
- **acceptance_criteria**:
  - GIVEN base GEMMA objects WHEN the export is generated THEN a "Bron" propertyDefinition exists (either reused or created) and SWC properties reference it
- [x] 6.1 Add `ensureBronPropertyDefinition()` that checks existing property definitions and adds one if missing
- [x] 6.2 Return the propertyDefinitionRef identifier for use in element/relationship properties

## 7. Copy and Enrich Views

### Task 7: Copy qualifying views and inject application nodes
- **spec_ref**: `specs/org-archimate-export/spec.md#requirement-views-must-be-copied-with-applications-plotted-inside-referentiecomponenten`
- **files**: `softwarecatalog/lib/Service/ArchiMateExportService.php`
- **acceptance_criteria**:
  - GIVEN GEMMA view objects WHEN views are copied THEN each copy has new `id-swc-view-` identifier, application child nodes inside referentiecomponent nodes, and connection elements
- [x] 7.1 Add `copyAndEnrichViews()` that iterates view objects, deep-copies their XML, assigns new identifiers
- [x] 7.2 Walk each view's node tree to find nodes with `elementRef` matching referentiecomponenten that have mapped applications
- [x] 7.3 For each match, add child `<node>` elements with positioning (stacked vertically, fitting inside parent bounds)
- [x] 7.4 Add `<connection>` elements for each specialization relationship in the view

## 8. View Naming

### Task 8: Apply view naming convention
- **spec_ref**: `specs/org-archimate-export/spec.md#requirement-view-copies-must-use-titel-view-swc-property-for-naming`
- **files**: `softwarecatalog/lib/Service/ArchiMateExportService.php`
- **acceptance_criteria**:
  - GIVEN a view with `Titel view SWC` property WHEN the copy is created THEN the name is `{TitelViewSWC} {OrgName}`, falling back to `{OriginalName} {OrgName}` if property is missing
- [x] 8.1 Extract `Titel view SWC` from view properties during copy, compose name with organization name
- [x] 8.2 Handle fallback when property is missing

## 9. Organization Folders

### Task 9: Build SWC organization folder structure
- **spec_ref**: `specs/org-archimate-export/spec.md#requirement-swc-objects-must-be-organized-in-dedicated-folders`
- **files**: `softwarecatalog/lib/Service/ArchiMateExportService.php`
- **acceptance_criteria**:
  - GIVEN SWC elements, relationships, and views WHEN the organizations section is generated THEN dedicated folders "Applicaties (Softwarecatalogus)", "Relaties (Softwarecatalogus)", and "Views (Softwarecatalogus)" exist with identifierRef items
- [x] 9.1 Add `buildSwcOrganizationFolders()` that creates the folder items with identifierRef entries for all SWC objects
- [x] 9.2 Inject these folders into the existing GEMMA organization tree under the appropriate parent folders (Applications, Relations, Views)

## 10. Main Export Assembly

### Task 10: Assemble the complete organization export XML
- **spec_ref**: `specs/org-archimate-export/spec.md#requirement-export-must-produce-valid-archimate-xml-with-organization-applications`
- **files**: `softwarecatalog/lib/Service/ArchiMateExportService.php`
- **acceptance_criteria**:
  - GIVEN all generated components (elements, relationships, views, folders) WHEN the XML is assembled THEN it produces valid ArchiMate XML with correct model name and all sections
- [x] 10.1 Add `exportOrganizationArchiMateXml()` main method that orchestrates: get base objects → query org modules → generate elements → generate relationships → ensure Bron propdef → copy/enrich views → build folders → assemble XML
- [x] 10.2 Set model name to `Softwarecatalogus {OrgName}`
- [x] 10.3 Inject SWC elements/relationships into the appropriate XML sections alongside base GEMMA objects

## 11. File Naming

### Task 11: Set correct file name in response
- **spec_ref**: `specs/org-archimate-export/spec.md#requirement-file-and-model-must-follow-naming-convention`
- **files**: `softwarecatalog/lib/Service/ArchiMateService.php`, `softwarecatalog/lib/Controller/SettingsController.php`
- **acceptance_criteria**:
  - GIVEN organization "Zeist" and date 17-02-2026 WHEN the export is generated THEN the download filename is `17-02-2026_Softwarecatalogus_AMEFF_export_Zeist.xml`
- [x] 11.1 Generate file name in `ArchiMateService.exportOrgArchiMate()` using `d-m-Y` format and organization name
- [x] 11.2 Pass file name to controller, set Content-Disposition header

## 12. Frontend Export Button

### Task 12: Add organization export button to frontend
- **spec_ref**: `specs/org-archimate-export/spec.md#requirement-frontend-must-provide-organization-export-button`
- **files**: `softwarecatalog/src/views/settings/sections/ArchiMateImportExport.vue`
- **acceptance_criteria**:
  - GIVEN the ArchiMate settings page WHEN an organization is selected and "Organization Export" is clicked THEN the browser downloads the enriched XML file
- [x] 12.1 Add "Organization Export" button next to existing export button, reusing the organization dropdown
- [x] 12.2 Button calls `POST /api/archimate/export/organization` with selected organization UUID
- [x] 12.3 Button is disabled when no organization is selected

## 13. Integration Testing

### Task 13: End-to-end verification
- **spec_ref**: `specs/org-archimate-export/spec.md` (all requirements)
- **files**: N/A (manual testing)
- **acceptance_criteria**:
  - GIVEN the full implementation WHEN tested end-to-end THEN the export produces valid XML that can be imported into Archi
- [x] 13.1 Restart Apache, test API endpoint with curl for valid org UUID, missing UUID, and invalid UUID
- [x] 13.2 Verify exported XML contains SWC application elements with Bron property
- [x] 13.3 Verify exported XML contains SpecializationRelationship elements
- [x] 13.4 Verify view copies contain application child nodes inside referentiecomponent nodes
- [x] 13.5 Verify organization folders contain identifierRef items for SWC objects
- [x] 13.6 Test frontend button triggers download with correct filename

## Verification
- [x] All tasks checked off
- [x] Manual testing against acceptance criteria
- [ ] Export XML imports into Archi without errors
- [x] Existing base export still works unchanged
