# Proposal: gemma-gegevenscatalogus

## Summary
Integrate the GEMMA Gegevenscatalogus (Dutch standard data model containing ~600 standardized object types) as a first-class citizen in opencatalogi, enabling municipalities to import GEMMA releases, map local schemas to standardized object types, and generate compliance reports demonstrating alignment with national standards.

## Motivation
Dutch municipalities currently lack a shared, navigable, standards-based representation of the GEMMA Gegevenscatalogus. GEMMA is published by VNG Realisatie in multiple formats (HTML, SKOS, UML), but none are directly consumable in modern software stacks. Each municipality and software vendor builds proprietary mappings, losing cross-organizational comparability. When GEMMA releases change (e.g., 2.6 → 3.0), all existing mappings must be manually re-validated. This spec makes GEMMA a managed, queryable, audit-logged component within opencatalogi, with transparent compliance visibility and reusable schema mappings across organizations.

## Features

### Feature: SKOS/OWL/RDF Import
**Demand**: 10/10 (Critical)  
The system imports GEMMA releases from VNG-provided SKOS Turtle or OWL/RDF files, parsing all object types, attributes, relationships, and metadata into the GEMMA schema set. Supports parallel catalogus versions and handles network resilience.

### Feature: Browse UI with Facets and Search
**Demand**: 10/10 (Critical)  
Users navigate the GEMMA catalogus via an integrated UI with sidebar facets (domain, status, subtype, kerngegeven), full-text search across names/synonyms/definitions, and detail pages showing definitions, attribute tables, relationships, and regulatory sources.

### Feature: Schema Mapping UI
**Demand**: 9/10 (High)  
Data architects map local openregister schemas to GEMMA object types via drag-and-drop, define per-attribute transformations, and receive automatic validation feedback (volledig/partieel/geen mapping status). Provides real-time mapping-quality assessment.

### Feature: Version Tracking and Migration
**Demand**: 9/10 (High)  
When a new GEMMA release is imported, the system detects changes (objects added/removed/modified) and flags existing mappings for potential re-validation. Provides fuzzy-matching suggestions for renamed attributes and automated impact analysis.

### Feature: Compliance Reporting
**Demand**: 9/10 (High)  
Generates per-Register compliance reports showing percentage of GEMMA-conformant schemas, mapping status breakdown, and recommended remediation steps. Exportable as PDF/CSV and trackable via audit trail.

### Feature: API Validation against Mappings
**Demand**: 7/10 (Medium)  
When an openregister object is created/updated and the schema has an active GEMMA mapping, optionally validates against GEMMA-level constraints (strict/warn/off modes). Provides error messaging for GEMMA-required attributes.

### Feature: Bidirectional Linking with GEMMA-online
**Demand**: 6/10 (Medium)  
Object type detail pages include direct links to the canonical gemmaonline.nl documentation, GitHub issue tracker for VNG feedback, and deprecation notices with migration guidance.

### Feature: Domain Export as JSON-LD
**Demand**: 6/10 (Medium)  
Users export domain-specific subsets (e.g., BAG, BRP) of the GEMMA catalogus as JSON-LD 1.1, enabling interoperable consumption in external applications. Caching ensures fast re-delivery of unchanged exports.

### Feature: Attribute Suggestions on Schema Creation
**Demand**: 5/10 (Low)  
When creating a new schema in openregister, the system suggests matching GEMMA object types and auto-populates attributes with correct datatypes, cardinality, and mappings based on name matching and initial property definitions.

### Feature: Audit Trail on Mappings
**Demand**: 7/10 (Medium)  
Every GemmaMapping modification is logged with who/when/what/why metadata, enabling compliance audits and compliance-mandated PDF exports with cryptographic signing.

## Stakeholders

- **Data Architects (Gemeenten)** — Primary users; need GEMMA compliance capability for interoperability.
- **Informatiemanagers** — Use compliance reports for steering and board reporting.
- **CIO/CISO** — Require GEMMA conformity for BIO-audits and InfoSec standards.
- **Software Vendors** (Conduction, etc.) — Claim GEMMA conformity for product schemas.
- **VNG Realisatie** — Receives feedback on which object types are used and mapping pain points.
- **Policy Makers** — Use catalogus as an authoritative vocabulary for policy documents.
- **External Developers & Civic Tech** — Consume JSON-LD exports for interoperable civic apps.
- **Auditors** (NOREA, ARK) — Verify automated GEMMA compliance assertions in reports.
- **Common Ground Community** — Enables reusable datalaag services across municipalities.

## Scope

- Five new schemas: `GemmaCatalogus`, `GemmaObjecttype`, `GemmaAttribuut`, `GemmaRelatie`, `GemmaMapping`
- Extensions to existing `Catalogus` and `Schema` (openregister) with GEMMA-specific fields
- SKOS/OWL/RDF import pipeline with performance optimization (30 min for 600 objects + relations)
- React-based browse/search UI integrated into opencatalogi admin
- Mapping UI with drag-and-drop and automatic validation
- Compliance report generation and export (PDF, CSV)
- API-level validation hooks (strict/warn/off modes)
- Audit trail system with diff tracking and cryptographic signing
- JSON-LD export per domain with caching
- Integration hooks with openregister schema creation flow (attribute suggestions)

Out of scope for v1: Writing back to GEMMA-online, automatic OpenAPI spec generation, domain-specific BAG/BRP validation rules, ERD visualization, cross-domain impact analysis, provincial/waterschap model integration.

## Cross-app Integration

- **opencatalogi** (owner) — All new GEMMA schemas and browse UI
- **openregister** — Schema objects reference GEMMA object types; validation plugin for GEMMA checks
- **softwarecatalog** — Software claims GEMMA conformity; mappings provide proof
- **openconnector** — Can use GEMMA mappings as transformation layer for external system integration
- **docudesk** — Generates compliance reports as branded PDFs
- **mydash** — Surfaces GEMMA compliance KPIs (compliance %, unconfirmed schemas, avg mapping quality)
- **decidesk** — Auto-categorizes governance documents by referenced GEMMA object types
- **forum-standaardisatie** (future) — Links GEMMA catalogus to NEN 2660 / "pas toe of leg uit" registrations

## Success Criteria

1. GEMMA 3.0 (600 object types) imports in under 30 minutes with 100% fidelity
2. Data architects can map any openregister schema to GEMMA in under 10 minutes (first mapping) with drag-drop and auto-suggestions
3. Compliance reports are generated on-demand and reflect real-time mapping state
4. Audit trail captures all mapping mutations and supports PDF export for legal review
5. Common Ground-aligned schemas are demonstrably reusable across ≥2 municipalities within pilot phase
6. VNG Realisatie receives actionable feedback on GEMMA feature usage and mapping gaps (via dashboard)
