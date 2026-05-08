# Organization-Specific ArchiMate Export Specification

## Purpose
Enhances the org-specific AMEF export to include deelname (participation) data, organise output into typed folders, and replace the POST endpoint with a GET download endpoint with query parameters.

## Requirements

### Requirement: Export MUST include deelname data when deelnames parameter is enabled
When the `deelnames` query parameter is `true`, the export MUST query gebruik objects where the current organisation's UUID appears in the `deelnemers` field (with RBAC disabled) and include those applications in the output.

#### Scenario: Organisation has deelname gebruik
- GIVEN organisation "Zeist" appears in the `deelnemers` field of 5 gebruik objects owned by other organisations
- WHEN the export is requested with `?deelnames=true`
- THEN the XML MUST contain application elements for the 5 deelname modules
- AND specialization relationships MUST link each deelname application to its referentiecomponent
- AND the deelname applications MUST be placed in the `Deelnames (Softwarecatalogus)` folder

#### Scenario: Organisation has no deelname gebruik
- GIVEN organisation "EmptyDeelnames" does not appear in any other organisation's `deelnemers` field
- WHEN the export is requested with `?deelnames=true`
- THEN the XML MUST still be valid
- AND the `Deelnames (Softwarecatalogus)` folder MUST NOT appear in the output
- AND no error MUST be returned

#### Scenario: Deelnames parameter is not set
- GIVEN organisation "Zeist" has deelname gebruik
- WHEN the export is requested without the `deelnames` parameter (or `deelnames=false`)
- THEN the XML MUST NOT contain any deelname application elements
- AND no deelname query MUST be executed

### Requirement: Deelname query MUST use RBAC-disabled ObjectService search
Deelname gebruik objects are owned by other organisations. The query MUST bypass RBAC to find records where the current organisation appears in the `deelnemers` array.

#### Scenario: Deelname query filters on deelnemers field
- GIVEN organisation "Zeist" with UUID "uuid-zeist"
- WHEN the deelname query is executed
- THEN ObjectService.searchObjects MUST be called with `_rbac: false` and `_multitenancy: false`
- AND the query MUST contain `'deelnemers' => 'uuid-zeist'`
- AND the query MUST target the gebruik schema in the voorzieningen register

### Requirement: Export MUST support query parameters for toggling data layers
The GET endpoint MUST accept boolean query parameters that control which data is included in the export.

#### Scenario: All parameters enabled
- GIVEN a valid organisation UUID
- WHEN the export is requested with `?modules=true&deelnames=true&gebruik=true`
- THEN the XML MUST contain module application elements in `Gebruikt (Softwarecatalogus)` folder
- AND deelname application elements in `Deelnames (Softwarecatalogus)` folder
- AND gebruik data MUST be included
- AND all corresponding relationships and view enrichments MUST be present

#### Scenario: No parameters provided (default behavior)
- GIVEN a valid organisation UUID
- WHEN the export is requested without any query parameters
- THEN the export MUST behave as if `modules=true` (current default behavior)
- AND deelnames and gebruik data MUST NOT be included

#### Scenario: Only deelnames enabled
- GIVEN a valid organisation UUID
- WHEN the export is requested with `?deelnames=true`
- THEN the XML MUST contain only deelname application elements
- AND module elements from the organisation's own gebruik MUST NOT be included

## MODIFIED Requirements

### Requirement: API endpoint MUST accept organization UUID and return XML download
The export MUST be triggered via `GET /api/archimate/export/organization/{organizationUuid}` with the organization UUID as a path parameter and optional boolean query parameters.

#### Scenario: Valid organization UUID provided
- GIVEN a valid organization UUID "uuid-123"
- WHEN `GET /api/archimate/export/organization/uuid-123` is called
- THEN the response MUST have status 200
- AND Content-Type MUST be `application/xml`
- AND Content-Disposition MUST include `attachment; filename="..."`
- AND the body MUST contain valid ArchiMate XML

#### Scenario: Valid organization UUID with query parameters
- GIVEN a valid organization UUID "uuid-123"
- WHEN `GET /api/archimate/export/organization/uuid-123?modules=true&deelnames=true` is called
- THEN the response MUST have status 200
- AND the XML MUST include both module and deelname data

#### Scenario: Non-existent organization UUID
- GIVEN a UUID that does not match any organization
- WHEN `GET /api/archimate/export/organization/uuid-invalid` is called
- THEN the response MUST have status 404
- AND the response MUST contain error message "Organization not found"

### Requirement: SWC objects MUST be organized in typed folders
All SWC-added elements MUST be placed in organisation folders within the `<organizations>` section, separated by relationship type.

#### Scenario: Organisation folders created with typed subfolders
- GIVEN an organization export for "Zeist" with modules, deelnames, and gebruik enabled
- AND Zeist has own modules, deelname modules, and relationships/views
- WHEN the XML is generated
- THEN the `<organizations>` section MUST contain a top-level item with label "Zeist"
- AND under it, a subfolder with label `Gebruikt (Softwarecatalogus)` referencing the org's own application elements
- AND a subfolder with label `Aangeboden (Softwarecatalogus)` referencing applications the org provides
- AND a subfolder with label `Deelnames (Softwarecatalogus)` referencing deelname application elements
- AND a subfolder with label `Relaties (Softwarecatalogus)` referencing all SWC relationship elements
- AND a subfolder with label `Views (Softwarecatalogus)` referencing all SWC view copies

#### Scenario: Empty folders are omitted
- GIVEN organisation "Zeist" has modules but no deelnames and no aangeboden
- WHEN the export is generated with all parameters enabled
- THEN the `Gebruikt (Softwarecatalogus)` folder MUST be present with application references
- AND the `Deelnames (Softwarecatalogus)` folder MUST NOT appear
- AND the `Aangeboden (Softwarecatalogus)` folder MUST NOT appear

#### Scenario: Only deelnames enabled produces only deelnames folder
- GIVEN organisation "Zeist" has deelname gebruik
- WHEN the export is generated with only `?deelnames=true`
- THEN only the `Deelnames (Softwarecatalogus)` folder MUST appear under the org
- AND the `Gebruikt (Softwarecatalogus)` folder MUST NOT appear

### Requirement: Frontend MUST provide organization export with data layer toggles
The ArchiMate settings section MUST include checkboxes for selecting which data layers to include, and trigger the export via the GET endpoint.

#### Scenario: User triggers organization export with toggles
- GIVEN the user is on the ArchiMate settings page
- AND an organization is selected in the organization dropdown
- AND the "Modules" checkbox is checked
- AND the "Deelnames" checkbox is checked
- WHEN the user clicks the "Organization Export" button
- THEN the frontend MUST call `GET /api/archimate/export/organization/{uuid}?modules=true&deelnames=true`
- AND the browser MUST download the resulting XML file

#### Scenario: No organization selected
- GIVEN the user is on the ArchiMate settings page
- AND no organization is selected in the dropdown
- WHEN the user attempts to click the export button
- THEN the button MUST be disabled or show a message requiring organization selection

#### Scenario: Default checkbox state
- GIVEN the user navigates to the ArchiMate settings page
- THEN the "Modules" checkbox MUST be checked by default
- AND the "Deelnames" checkbox MUST be unchecked by default
- AND the "Gebruik" checkbox MUST be unchecked by default

## REMOVED Requirements

### Requirement: POST endpoint for organization export
The `POST /api/archimate/export/organization` endpoint is removed and replaced by the GET endpoint described above.

**Reason:** An export/download is a safe, idempotent read operation. GET is the semantically correct HTTP method. It enables browser-native downloads, bookmarking, and simpler integration.

**Migration:** All callers (frontend `ArchiMateImportExport.vue`) MUST be updated to use the new GET endpoint with path parameter and query string.
