# View Enrichment API Specification

## Purpose
Defines how the frontend obtains enriched view data (base GEMMA view + organization-specific modules and usage data) through the softwarecatalog enrichment API, replacing direct OpenRegister calls.

## ADDED Requirements

### Requirement: Frontend MUST call enrichment API for views
The frontend MUST use the softwarecatalog enrichment API (`/softwarecatalog/api/views/{viewId}`) instead of the OpenRegister direct API (`/openregister/api/objects/vng-gemma/view/{id}`) for all view rendering.

#### Scenario: Beheer view loads with enrichment
- GIVEN a user navigates to `/beheer/view/{id}`
- WHEN the view component mounts
- THEN the frontend MUST request `GET /softwarecatalog/api/views/{viewId}`
- AND the request MUST include enrichment parameters based on active filter toggles

#### Scenario: Public view loads with enrichment
- GIVEN a visitor navigates to `/views/{id}`
- WHEN the public view component mounts
- THEN the frontend MUST request `GET /softwarecatalog/api/views/{viewId}`
- AND the request MUST include enrichment parameters based on active filter toggles

### Requirement: Frontend filter toggles MUST map to backend enrichment parameters
The frontend filter toggle state MUST be translated to the correct backend query parameters on each view fetch.

#### Scenario: Gebruik filter is enabled
- GIVEN the user enables the "Gebruik" filter toggle
- WHEN the view is fetched
- THEN the request MUST include `include_gebruik=true`
- AND the request MUST include `include_modules=true`

#### Scenario: Deelnames filter is enabled
- GIVEN the user enables the "Deelnames" filter toggle
- WHEN the view is fetched
- THEN the request MUST include `include_deelnames_gebruik=true`
- AND the request MUST include `include_modules=true`

#### Scenario: Product filter is enabled
- GIVEN the user enables the "Product" filter toggle
- WHEN the view is fetched
- THEN the request MUST include `include_products=true`

#### Scenario: No filters are enabled
- GIVEN all filter toggles are disabled
- WHEN the view is fetched
- THEN the request MUST NOT include any enrichment parameters
- AND the response MUST contain only the base GEMMA view (viewNodes and viewRelationships from the ArchiMate import)

### Requirement: Enrichment API MUST return standard viewNode format
The enrichment API MUST return module overlay nodes in the same format as base GEMMA viewNodes so the frontend rendering pipeline requires no structural changes.

#### Scenario: Enriched response contains module nodes
- GIVEN a view is requested with `include_modules=true`
- WHEN the active organization has modules linked to referentiecomponenten on this view
- THEN the response `viewNodes` array MUST contain additional entries for each module-referentiecomponent match
- AND each module node MUST have `viewNodeId`, `modelNodeId`, `name`, `type`, `x`, `y`, `width`, `height`, `parent`, `color`, `borderColor` fields
- AND each module node MUST have `parent` set to the matching referentiecomponent's `viewNodeId`
- AND each module node MUST have `_isModuleExpansion` set to `true`

#### Scenario: No matching modules exist
- GIVEN a view is requested with `include_modules=true`
- WHEN the active organization has no modules matching referentiecomponenten on this view
- THEN the response MUST contain only the base viewNodes
- AND no error MUST be returned

### Requirement: Endpoint constants MUST be updated
The frontend endpoint configuration MUST point to the softwarecatalog enrichment API.

#### Scenario: GEMMA VIEW endpoint is configured
- GIVEN the frontend endpoints constants file
- WHEN the GEMMA.VIEW endpoint is resolved
- THEN it MUST resolve to `/softwarecatalog/api/views/{id}`
- AND the GEMMA.VIEWS endpoint MUST resolve to `/softwarecatalog/api/views`

## MODIFIED Requirements

_None — this is a new capability._

## REMOVED Requirements

_None._
