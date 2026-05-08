# Design: woo-transparency

## Context

WOO (Wet open overheid) / FOIA compliance features in OpenCatalogi: publication decision tracking, document redaction workflow, publication to a public reading room, and redaction audit trail. Builds on existing Publication, Catalog, and Listing entities with WOO-specific workflow. Document-level operations delegated to Docudesk.

## Goals / Non-Goals

**Goals:**
- WOO document queue for batch processing
- Weigeringsgronden (refusal grounds) tracking
- Redaction with WOO context
- WOO batch data model
- Inventarislijst generation

**Non-Goals:**
- PDF generation (delegated to Docudesk)
- Entity detection/anonymization (delegated to Docudesk)
- Court case management

## Decisions

1. WOO workflow uses Publication status transitions (draft -> review -> published/refused)
2. Weigeringsgronden stored as structured metadata on publications
3. Inventarislijst generated as structured export (CSV/PDF)
4. Integration with Docudesk via n8n workflows for document processing

## File Changes

- Publication schema — WOO-specific fields (weigeringsgronden, besluit, batch)
- Frontend — WOO queue view, batch processing UI
- Backend — inventarislijst generation endpoint
