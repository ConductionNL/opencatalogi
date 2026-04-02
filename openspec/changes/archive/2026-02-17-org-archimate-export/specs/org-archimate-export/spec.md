# Organization-Specific ArchiMate Export Specification

## Purpose
Defines how the softwarecatalog app exports an organization-enriched ArchiMate (AMEFF) XML file that includes the base GEMMA model plus the organization's applications plotted on referentiecomponenten, with proper folder structure, naming, and metadata.

## Requirements

### Requirement: Export MUST produce valid ArchiMate XML with organization applications
The organization export MUST generate a valid AMEFF XML file that includes all base GEMMA objects plus synthesized application elements, specialization relationships, enriched view copies, and organization folder structure.

#### Scenario: Organization with mapped applications exports successfully
- GIVEN an organization "Zeist" with 10 modules mapped to referentiecomponenten
- WHEN the organization export is requested for "Zeist"
- THEN the response MUST be a valid ArchiMate XML file
- AND the file MUST contain all base GEMMA elements, relationships, and property definitions
- AND the file MUST contain 10 additional `<element>` entries for the applications
- AND the file MUST contain specialization relationships linking each application to its referentiecomponent
- AND the file MUST import into Archi without errors

#### Scenario: Organization with no mapped applications
- GIVEN an organization "EmptyOrg" with 0 modules mapped to referentiecomponenten
- WHEN the organization export is requested for "EmptyOrg"
- THEN the response MUST be a valid ArchiMate XML file containing only the base GEMMA objects
- AND no SWC-specific elements, relationships, or view copies MUST be added
- AND no error MUST be returned

### Requirement: Application elements MUST be ApplicationComponent type with Bron property
Each organization application MUST be exported as an ArchiMate `<element>` with `xsi:type="ApplicationComponent"`, a unique identifier, and a `Bron=Softwarecatalogus` property.

#### Scenario: Application element has correct structure
- GIVEN a module "Topdesk" belonging to organization "Zeist"
- WHEN the organization export is generated
- THEN the XML MUST contain an element like `<element identifier="id-swc-app-{uuid}" xsi:type="ApplicationComponent">`
- AND the element MUST have a `<name>` child with value "Topdesk"
- AND the element MUST have a `<properties>` section with `Bron` property set to "Softwarecatalogus"

#### Scenario: Application element has unique SWC identifier
- GIVEN two modules "Topdesk" and "Key2Financien"
- WHEN the organization export is generated
- THEN each application element MUST have a unique `identifier` attribute prefixed with `id-swc-app-`
- AND the identifiers MUST NOT collide with any existing GEMMA element identifiers

### Requirement: SpecializationRelationship MUST link applications to referentiecomponenten
Each application-to-referentiecomponent mapping MUST produce a `<relationship>` of type `SpecializationRelationship` in the export.

#### Scenario: Application mapped to one referentiecomponent
- GIVEN module "Topdesk" is mapped to referentiecomponent "Zaakregistratiecomponent"
- WHEN the organization export is generated
- THEN the XML MUST contain a `<relationship xsi:type="SpecializationRelationship">` with `source` pointing to the Topdesk application element and `target` pointing to the Zaakregistratiecomponent element
- AND the relationship MUST have a unique identifier prefixed with `id-swc-rel-`
- AND the relationship MUST have the `Bron=Softwarecatalogus` property

#### Scenario: Application mapped to multiple referentiecomponenten
- GIVEN module "SAP" is mapped to 3 referentiecomponenten
- WHEN the organization export is generated
- THEN the XML MUST contain 3 separate SpecializationRelationship elements, one per mapping
- AND each relationship MUST have a unique identifier

### Requirement: Views MUST be copied with applications plotted inside referentiecomponenten
The export MUST create copies of qualifying GEMMA views and inject application nodes as children of their mapped referentiecomponent nodes.

#### Scenario: View with applications plotted on referentiecomponenten
- GIVEN a GEMMA view "BBN poster" with referentiecomponent node "Zaakregistratiecomponent"
- AND module "Topdesk" is mapped to "Zaakregistratiecomponent"
- WHEN the organization export is generated
- THEN the export MUST contain a copy of the "BBN poster" view with a new identifier prefixed with `id-swc-view-`
- AND the copied view MUST contain a child `<node>` inside the "Zaakregistratiecomponent" node with `elementRef` pointing to the "Topdesk" application element
- AND a `<connection>` element MUST be added for the specialization relationship between "Topdesk" and "Zaakregistratiecomponent"

#### Scenario: Multiple applications stacked inside one referentiecomponent
- GIVEN referentiecomponent "Zaakregistratiecomponent" has 3 mapped applications
- WHEN the organization export is generated
- THEN the referentiecomponent node MUST contain 3 child `<node>` elements
- AND each child node MUST have positioning attributes (`x`, `y`, `w`, `h`) that fit within the parent node bounds
- AND the child nodes MUST be stacked vertically without overlapping

#### Scenario: Application appears in multiple referentiecomponenten across views
- GIVEN module "Topdesk" is mapped to 2 referentiecomponenten that appear on 3 views
- WHEN the organization export is generated
- THEN each view copy MUST contain child nodes for "Topdesk" inside each occurrence of its mapped referentiecomponenten
- AND each child node MUST have a unique identifier

#### Scenario: View without any matching referentiecomponenten
- GIVEN a view that contains no referentiecomponenten with mapped applications
- WHEN the organization export is generated
- THEN the view copy MUST be included unchanged (no child nodes added)
- AND the view copy MUST still have the new identifier and name

### Requirement: View copies MUST use Titel view SWC property for naming
Copied views MUST be named using the `Titel view SWC` property from the original view combined with the organization name.

#### Scenario: View has Titel view SWC property
- GIVEN a GEMMA view with property `Titel view SWC` = "Applicatieservices bestuur"
- AND the organization name is "Zeist"
- WHEN the organization export is generated
- THEN the copied view's `<name>` MUST be "Applicatieservices bestuur Zeist"

#### Scenario: View without Titel view SWC property
- GIVEN a GEMMA view named "BBN poster" without a `Titel view SWC` property
- AND the organization name is "Zeist"
- WHEN the organization export is generated
- THEN the copied view's `<name>` MUST fall back to the original view name plus organization name: "BBN poster Zeist"

### Requirement: SWC objects MUST be organized in dedicated folders
All SWC-added elements, relationships, and views MUST be placed in organization folders within the `<organizations>` section.

#### Scenario: Organization folders created for SWC objects
- GIVEN an organization export with applications, relationships, and view copies
- WHEN the XML is generated
- THEN the `<organizations>` section MUST contain an item with label "Applicaties (Softwarecatalogus)" under the "Applications" folder, referencing all SWC application elements
- AND it MUST contain an item with label "Relaties (Softwarecatalogus)" under the "Relations" folder, referencing all SWC relationship elements
- AND it MUST contain an item with label "Views (Softwarecatalogus)" under the "Views" folder, referencing all SWC view copies

### Requirement: File and model MUST follow naming convention
The export file name and ArchiMate model name MUST include the organization name and export date.

#### Scenario: File name includes date and organization
- GIVEN the organization name is "Zeist"
- AND the current date is 17-02-2026
- WHEN the organization export is generated
- THEN the Content-Disposition header MUST set the filename to `17-02-2026_Softwarecatalogus_AMEFF_export_Zeist.xml`

#### Scenario: Model name includes organization
- GIVEN the organization name is "Zeist"
- WHEN the organization export is generated
- THEN the root `<model>` element's `<name>` MUST be "Softwarecatalogus Zeist"

### Requirement: API endpoint MUST accept organization UUID and return XML download
The export MUST be triggered via `POST /api/archimate/export/organization` with an organization UUID in the request body.

#### Scenario: Valid organization UUID provided
- GIVEN a valid organization UUID
- WHEN `POST /api/archimate/export/organization` is called with `{"organization": "uuid-123"}`
- THEN the response MUST have status 200
- AND Content-Type MUST be `application/xml`
- AND the body MUST contain valid ArchiMate XML

#### Scenario: Missing organization UUID
- GIVEN no organization UUID in the request
- WHEN `POST /api/archimate/export/organization` is called with `{}`
- THEN the response MUST have status 400
- AND the response MUST contain error message "Organization UUID is required"

#### Scenario: Non-existent organization UUID
- GIVEN a UUID that does not match any organization
- WHEN `POST /api/archimate/export/organization` is called
- THEN the response MUST have status 404
- AND the response MUST contain error message "Organization not found"

### Requirement: Bron property definition MUST be added to the model
The export MUST include a `<propertyDefinition>` for "Bron" so that the `Bron=Softwarecatalogus` property on SWC objects references a valid definition.

#### Scenario: Bron property definition does not already exist
- GIVEN the base GEMMA model does not have a "Bron" property definition
- WHEN the organization export is generated
- THEN the XML MUST contain a `<propertyDefinition identifier="id-swc-propdef-bron">` with name "Bron" in the `<propertyDefinitions>` section

#### Scenario: Bron property definition already exists
- GIVEN the base GEMMA model already has a "Bron" property definition
- WHEN the organization export is generated
- THEN the existing property definition MUST be reused
- AND a duplicate MUST NOT be created

### Requirement: Connection elements MUST be created for plotted applications
Each application node plotted inside a referentiecomponent MUST have a corresponding `<connection>` element in the view linking it via the specialization relationship.

#### Scenario: Connection links application node to referentiecomponent node
- GIVEN application node "Topdesk" plotted inside referentiecomponent node "Zaakregistratiecomponent"
- AND a SpecializationRelationship exists between them
- WHEN the view copy is generated
- THEN a `<connection>` element MUST be added to the view with `relationshipRef` pointing to the SpecializationRelationship identifier
- AND `source` pointing to the application node identifier
- AND `target` pointing to the referentiecomponent node identifier

### Requirement: Frontend MUST provide organization export button
The ArchiMate settings section MUST include a button to trigger the organization-specific export, using the selected organization from the dropdown.

#### Scenario: User triggers organization export
- GIVEN the user is on the ArchiMate settings page
- AND an organization is selected in the organization dropdown
- WHEN the user clicks the "Organization Export" button
- THEN the frontend MUST call `POST /api/archimate/export/organization` with the selected organization UUID
- AND the browser MUST download the resulting XML file

#### Scenario: No organization selected
- GIVEN the user is on the ArchiMate settings page
- AND no organization is selected in the dropdown
- WHEN the user attempts to click the export button
- THEN the button MUST be disabled or show a message requiring organization selection

## MODIFIED Requirements

_None — this is a new capability._

## REMOVED Requirements

_None._
