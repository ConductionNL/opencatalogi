# woo-transparency Specification

## Problem
WOO (Wet open overheid) / FOIA compliance features in OpenCatalogi: publication decision tracking, document redaction workflow, publication to a public reading room, and redaction audit trail. Builds on OpenCatalogi's existing Publication, Catalog, and Listing entities to add WOO-specific workflow and publication capabilities. Document-level operations (PDF generation, anonymization, entity detection) are delegated to Docudesk.

## Proposed Solution
Implement woo-transparency Specification following the detailed specification. Key requirements include:
- Requirement: WOO document queue
- Requirement: Weigeringsgronden (refusal grounds)
- Requirement: Redaction with WOO context
- Requirement: WOO batch data model
- Requirement: Inventarislijst generation

## Scope
This change covers all requirements defined in the woo-transparency specification.

## Success Criteria
- Receive documents from Procest
- Document assessment statuses
- Bulk assessment
- Queue displays progress summary
- Queue supports sorting and filtering
