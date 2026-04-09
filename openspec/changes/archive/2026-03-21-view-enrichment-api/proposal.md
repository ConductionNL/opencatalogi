# View Enrichment API Specification

## Problem
Defines how the frontend obtains enriched view data (base GEMMA view + organization-specific modules and usage data) through the softwarecatalog enrichment API, replacing direct OpenRegister calls. This API acts as the single entry point for all GEMMA view data, aggregating base ArchiMate view data with organization-specific module mappings, gebruik, and deelnames into a unified response.

## Proposed Solution
Implement View Enrichment API Specification following the detailed specification. Key requirements include:
- Requirement: Frontend MUST call enrichment API for views
- Requirement: Frontend filter toggles MUST map to backend enrichment parameters
- Requirement: Enrichment API MUST return standard viewNode format
- Requirement: Enrichment API MUST support organization context
- Requirement: Endpoint constants MUST be updated

## Scope
This change covers all requirements defined in the view-enrichment-api specification.

## Success Criteria
- Beheer view loads with enrichment
- Public view loads with enrichment
- Direct OpenRegister calls are no longer used for views
- Enrichment API returns 404 for non-existent view
- Enrichment API handles server errors gracefully
