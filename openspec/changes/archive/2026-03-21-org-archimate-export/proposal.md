# Organization-Specific ArchiMate Export Specification

## Problem
Defines how the softwarecatalog app exports an organization-enriched ArchiMate (AMEFF) XML file that includes the base GEMMA model plus the organization's applications plotted on referentiecomponenten, with proper folder structure, naming, and metadata. Supports toggling data layers (modules, deelnames, gebruik) via query parameters and organises output into typed folders. The exported file is designed to import cleanly into Archi (the open-source ArchiMate modelling tool) and other AMEFF-compatible tools.

## Proposed Solution
Implement Organization-Specific ArchiMate Export Specification following the detailed specification. Key requirements include:
- Requirement: Export MUST produce valid ArchiMate XML with organization applications
- Requirement: Application elements MUST be ApplicationComponent type with Bron property
- Requirement: SpecializationRelationship MUST link applications to referentiecomponenten
- Requirement: Views MUST be copied with applications plotted inside referentiecomponenten
- Requirement: View copies MUST use Titel view SWC property for naming

## Scope
This change covers all requirements defined in the org-archimate-export specification.

## Success Criteria
- Organization with mapped applications exports successfully
- Organization with no mapped applications
- Export preserves all base GEMMA data
- Export XML is well-formed and schema-valid
- Large organization export completes within timeout
