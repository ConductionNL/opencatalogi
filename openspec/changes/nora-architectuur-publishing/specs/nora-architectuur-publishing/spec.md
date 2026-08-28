---
status: draft
---

# NORA-Architectuur Publishing Specification

## Purpose

Define how Dutch government organizations publish, version, search, and manage reference architectures (NORA-style) as first-class OpenCatalogi catalogs. Enables stable URIs to architecture concepts, structured cross-organization mapping to national NORA/GEMMA/PETRA/WILMA/MARIJ/EAR families, full-text search, compliance tracking, and RDF/Turtle exports. This spec replaces static HTML wiki publishing with machine-readable, versioned, queryable architecture artifacts.

## Context

Dutch government maintains a hierarchy of reference architectures: NORA (national), then domain-specific copies (GEMMA for gemeenten, PETRA for provincies, WILMA for waterschappen, MARIJ/EAR for rijksdiensten), then organization-specific instances that adopt/profile/extend these upstream families. Today the architectures live in separate wiki sites (noraonline.nl, gemmaonline.nl, etc.) with hand-maintained crosslinks, embedded ArchiMate exports, and signed "vastgestelde versies" PDFs. They are:
- Hard to query ("which diensten depend on bouwsteen X?")
- Hard to version ("which version of the architecture was in effect on 2024-01-15?")
- Hard to compare ("what % of gemeente A's principles match NORA vs GEMMA vs local?")
- Hard to use as automated input (tenders cannot cite stable URIs that resolve to machine-readable definitions)
- Hard to audit (BIO, ENSIA, Forum Standaardisatie cannot see live compliance state)

This spec moves architectures into OpenCatalogi with five core schemas (NoraPrinciple, NoraBuildingBlock, NoraService, NoraStandard, NoraPattern), stable URIs, version history via OpenRegister, SKOS metadata, RDF/Turtle exports, and a mapping table linking local concepts to upstream national families.

**Relation to existing specs:**
- `openregister/openspec/...`: Schemas and CRUD patterns for all five item types
- `opencatalogi/openspec/federation`: Federation of NORA catalogs across municipalities
- `opencatalogi/org-archimate-export`: ArchiMate 3.1 export of building-block + service layers
- `opencatalogi/dcat-oai-pmh-harvesting`: NORA catalog exposed as DCAT-AP-NL dataset + OAI-PMH set

**Relation to existing OpenCatalogi entities:**
- NORA catalogs are typed OpenCatalogi catalogs (`catalogType: nora-architecture`)
- All items registered in OpenRegister with standard CRUD + audit trail
- Version history tracked via OpenRegister `AuditTrailService`
- Cross-schema mappings stored as OpenRegister Relations
- Full-text search via `IndexService`; faceted results via `FacetBuilder`

## Requirements

### REQ-NORA-001: Five Core Schemas with SKOS Metadata

The NORA catalog MUST define five core schemas in OpenRegister, each with stable URIs, SKOS labels, and DCAT-AP-NL provenance metadata.

#### Scenario: NoraPrinciple schema created with hierarchy support
- GIVEN the organization has not yet created a NORA catalog
- WHEN the nora-architectuur-publishing app is installed and repair step runs
- THEN the OpenRegister schema `nora-principle` MUST be created with properties:
  - `code` (string, required): Human-readable code (e.g., "AP-01", "GP-15")
  - `title` (string, required): Principle title
  - `statement` (text, required): Core principle statement
  - `rationale` (text, required): Why this principle exists
  - `implications` (array of string): Constraints/consequences
  - `domain` (enum, required): informatie|applicatie|technologie|governance
  - `parentId` (reference to nora-principle, optional): For hierarchy
  - `adoptsFromUri` (URI, optional): Link to national NORA principle
  - `profileOfUri` (URI, optional): Link to GEMMA/PETRA principle
  - `status` (enum, required): draft|vastgesteld|vervallen
  - `version` (string, required): Semantic version
  - `validFrom` (date, required): When this version took effect
  - `validTo` (date, optional): Expiry
  - `owner` (reference to Contact, optional): Responsible architect
  - `prefLabel` (string, required): SKOS localized label
  - `altLabel` (array of string, optional): SKOS alternative labels
  - `definition` (text, optional): SKOS definition
  - `scopeNote` (text, optional): SKOS scope note
  - `creator` (string, optional): DCAT-AP-NL creator
  - `publisher` (string, optional): DCAT-AP-NL publisher
  - `issued` (date, optional): DCAT-AP-NL issued date
  - `modified` (date, optional): DCAT-AP-NL modified date
- AND NoraBuildingBlock schema with properties:
  - `code`, `title`, `description` (required)
  - `capabilityArea` (enum): zaak|document|identity|integration|data|klantcontact|basisregistratie
  - `realizesIds` (array of reference to nora-principle): Which principles this building block realizes
  - `composedOfIds` (array of reference to nora-building-block): Composition hierarchy
  - `standardIds` (array of reference to nora-standard): Which standards apply
  - `maturityLevel` (enum): initial|repeatable|defined|managed|optimized
  - `owner` (reference to Contact, optional)
  - `lifecycleStatus` (enum): planned|in-use|deprecated|retired
  - `prefLabel`, `altLabel`, `definition`, `scopeNote`, `creator`, `publisher`, `issued`, `modified` (SKOS + DCAT-AP-NL)
- AND NoraService schema with properties:
  - `code`, `title`, `description` (required)
  - `deliversIds` (array of reference to nora-building-block): What building blocks this service delivers
  - `serviceLevel` (enum): best-effort|bronze|silver|gold|platinum
  - `consumerTypes` (array of enum): gemeente|provincie|waterschap|rijksorgaan|andere
  - `provider` (reference to Contact, optional): Service provider
  - `technicalEndpoint` (URI, optional): API base URL
  - `governanceContact` (reference to Contact, optional): Escalation contact
  - `slaUri` (URI, optional): SLA document link
  - `prefLabel`, `altLabel`, `definition`, `scopeNote`, `creator`, `publisher`, `issued`, `modified`
- AND NoraStandard schema with properties:
  - `code`, `title` (required)
  - `issuer` (enum): Forum-Standaardisatie|NEN|ISO|W3C|ETSI|OpenGroup|andere
  - `type` (enum): open-standaard|norm|guideline|specification
  - `status` (enum): verplicht|aanbevolen|in-onderzoek|afgeraden
  - `forumStandaardisatieUri` (URI, optional): Link to forum standardization entry
  - `complianceStatus` (enum): toegepast|niet-toegepast
  - `complianceReasoning` (text, optional): Justify non-adoption (comply-or-explain)
  - `complianceExpiryDate` (date, optional): Re-evaluation deadline for "niet-toegepast"
  - `alternativeStandardIds` (array of reference to nora-standard): Standards used instead
  - `prefLabel`, `altLabel`, `definition`, `scopeNote`, `creator`, `publisher`, `issued`, `modified`
- AND NoraPattern schema with properties:
  - `code`, `title`, `problem`, `solution` (required)
  - `consequences` (text, required)
  - `appliesToIds` (array of reference to nora-building-block): Where this pattern applies
  - `examples` (array of object with exampleTitle, exampleUri): Usage examples
  - `prefLabel`, `altLabel`, `definition`, `scopeNote`, `creator`, `publisher`, `issued`, `modified`
- AND all schemas registered as `x-openregister.type: "application"` in `lib/Settings/nora-architectuur-publishing_register.json`

#### Scenario: SKOS metadata is accessible in search and detail views
- GIVEN an organization has published a principle "Open by Default" with altLabel "Default Open"
- WHEN a user searches the NORA catalog for "default open"
- THEN the search MUST find this principle and highlight that it matched via altLabel
- AND when viewing the principle detail, altLabel/definition/scopeNote MUST be displayed alongside the primary title

### REQ-NORA-002: Stable URI Scheme and Content Negotiation

Every NORA item MUST have a stable URI under `{organization-domain}/architectuur/{type}/{code}` resolvable to JSON-LD, HTML, RDF-XML, or Turtle based on Accept header.

#### Scenario: Principle URI resolves to JSON-LD
- GIVEN Gemeente Amsterdam has published principle AP-01 (Open by Default)
- WHEN a client sends `GET https://amsterdam.nl/architectuur/principe/AP-01` with `Accept: application/ld+json`
- THEN the response MUST be HTTP 200 with `Content-Type: application/ld+json` and include:
  - `@context`: SKOS + DCAT-AP-NL context
  - `@id`: Stable principle URI
  - `skos:prefLabel`: "Open by Default" (with language tag @nl)
  - `skos:definition`: Definition text
  - `dcat:issued`, `dcat:modified`: Dates
  - `dcat:creator`, `dcat:publisher`: Responsible organizations
  - All properties from the NoraPrinciple schema

#### Scenario: Building block URI resolves to HTML
- GIVEN Gemeente Amsterdam has published building block BS-ZK (Zaak Management)
- WHEN a client sends `GET https://amsterdam.nl/architectuur/bouwsteen/BS-ZK` with `Accept: text/html`
- THEN the response MUST be HTTP 200 with `Content-Type: text/html` and render:
  - HTML5 page with accessible structure (semantic tags: `<header>`, `<main>`, `<nav>`)
  - Building block title, description, capability area
  - List of realized principles with links to their URIs
  - List of standards with `complianceStatus` (toegepast/niet-toegepast)
  - Related services that deliver this building block (reverse link)
  - Change history (version selector, last modified date)
  - JSON-LD `<script>` tag in `<head>` for embedded metadata

#### Scenario: Standard URI resolves to RDF/XML
- GIVEN Gemeente Amsterdam has published standard STUF-ZKN-0310
- WHEN a client sends `GET https://amsterdam.nl/architectuur/standaard/STUF-ZKN-0310` with `Accept: application/rdf+xml`
- THEN the response MUST be HTTP 200 with `Content-Type: application/rdf+xml` and include:
  - RDF resources for the standard, its issuer, issuer contact info
  - SKOS + DCAT-AP-NL triples
  - Typed references to any related principles/building blocks/services

#### Scenario: Service URI resolves to Turtle
- GIVEN Gemeente Amsterdam has published service SRV-12
- WHEN a client sends `GET https://amsterdam.nl/architectuur/dienst/SRV-12` with `Accept: text/turtle`
- THEN the response MUST be HTTP 200 with `Content-Type: text/turtle` and include Turtle RDF with full metadata and relationships

#### Scenario: Default content type for browser is HTML
- GIVEN a user clicks a link to a NORA item from another page
- WHEN the browser requests the URI without explicit Accept header (or with `Accept: text/html, */*`)
- THEN the response MUST be HTML (human-readable) not JSON-LD or RDF

### REQ-NORA-003: Version History and Audit Trail

Every NORA item MUST track version history with before/after snapshots, change author, timestamp, and must be restorable to any prior version.

#### Scenario: Principle version history visible on detail page
- GIVEN Gemeente Amsterdam published principle AP-01 v1.0 on 2024-01-01
- AND updated it to v1.1 on 2024-06-15 (added an implication, modified rationale)
- WHEN viewing the principle detail page
- THEN a "Version History" section MUST display:
  - v1.1 (Current) — modified 2024-06-15 by Bert de Architect
  - v1.0 — created 2024-01-01 by Bert de Architect
- AND clicking on v1.0 MUST show a diff view (added/removed/changed fields highlighted)
- AND a "Restore to v1.0" button MUST be available (requires architect role)

#### Scenario: Audit trail captures full change record
- GIVEN a building block is created, then modified twice, then deprecated
- WHEN the OpenRegister audit log is queried for this building block
- THEN it MUST return four entries:
  1. created: Timestamp, author, full object snapshot
  2. modified: Timestamp, author, before/after snapshots of changed fields
  3. modified: Timestamp, author, before/after snapshots
  4. modified (status=deprecated): Timestamp, author, before/after snapshots
- AND each entry MUST include full metadata (creator, publisher, issued, modified)

#### Scenario: Version is semantically versioned
- GIVEN a principle is published at 1.0.0
- WHEN the next update is backwards-compatible (new implication added, not removed)
- THEN the version MUST increment to 1.1.0 (minor bump)
- WHEN a subsequent update removes an implication or changes the statement
- THEN the version MUST increment to 2.0.0 (major bump)
- AND the spec MUST use semver rules: major.minor.patch

### REQ-NORA-004: Mapping Table Linking to National Families

Every local concept MAY be linked to an upstream national NORA-family concept via a NoraMapping record with explicit relationship type and justification.

#### Scenario: Principle adopts national NORA principle
- GIVEN Gemeente Amsterdam principle AP-01 (Open by Default) is verbatim adoption of NORA principle AP-01
- WHEN viewing the Amsterdam principle detail
- THEN a "Relationship to NORA" section MUST display:
  - "Adopts" badge
  - Link to https://noraonline.nl/pages/AP01
  - Justification: "Amsterdam fully adopts NORA AP-01 without modifications"
  - Mapping date: 2024-01-01
  - Mapping author: Bert de Architect

#### Scenario: Building block profiles upstream GEMMA bouwsteen
- GIVEN Gemeente Amsterdam's zaak management building block BS-ZK profiles (extends) GEMMA's zaken bouwsteen
- WHEN viewing BS-ZK detail
- THEN a "Relationship to GEMMA" section MUST display:
  - "Profiles" badge (with explanation: "adopts with local extensions")
  - Link to GEMMA bouwsteen
  - Justification: "Implements via OpenZaak (Zaken-API 1.5 compliant); Amsterdam adds SLA monitoring and Amsterdam-specific document types"
  - Mapping date and author

#### Scenario: Local-only principle with no upstream mapping
- GIVEN Gemeente Amsterdam principle GP-15 (Data Sovereignty) has no national NORA counterpart
- WHEN viewing GP-15 detail
- THEN no "Relationship to National Architecture" section MUST appear
- AND a note MUST state: "This is an organization-specific principle"

#### Scenario: Principle contradicts upstream with justification
- GIVEN organization Y's principle contradicts NORA principle (e.g., "Data stored in NL only" vs NORA's "cloud-agnostic")
- WHEN viewing this principle
- THEN a "Relationship to NORA" section MUST display:
  - "Contradicts" badge (with warning color)
  - Link to NORA principle
  - Justification: "Regional waterschap law mandates data residency in NL; NORA's cloud-agnostic stance conflicts with this legal requirement"
  - Approval status: "Approved by CIO 2024-02-15" (timestamp of decision)

#### Scenario: Mapping table is queryable
- GIVEN an architect wants to find "which 10 gemeenten have mapped their architecture to NORA AP-04?"
- WHEN querying the NoraMapping table with filters:
  - `upstreamUri = "https://noraonline.nl/pages/AP04"`
  - `mappingType in (adopts, profiles, extends)`
- THEN the query MUST return all matching local principles with their organization domains

#### Scenario: Reverse mapping queries work
- GIVEN a NORA principle AP-04
- WHEN querying "which local organizations map to AP-04?"
- THEN the response MUST include all NoraMapping records with that upstreamUri
- AND results MUST be grouped by organization for dashboard use (e.g., "Municipality A adopts; Municipality B profiles; Municipality C contradicts")

### REQ-NORA-005: RDF/Turtle Export of Full Catalog

The entire NORA catalog MUST be exportable as a single RDF/Turtle file using SKOS + DCAT-AP-NL vocabulary.

#### Scenario: Full catalog export available
- GIVEN an organization has a NORA catalog with 5 principles, 3 building blocks, 2 services, 4 standards, 1 pattern
- WHEN a user navigates to `/apps/nora-architectuur-publishing/export?format=turtle`
- THEN the system MUST return HTTP 200 with:
  - `Content-Type: text/turtle; charset=utf-8`
  - `Content-Disposition: attachment; filename="amsterdam-architectuur-2024-06-15.ttl"`
  - Complete Turtle RDF with:
    - `@prefix` declarations for skos, dcat, dcterms, rdf, rdfs
    - One resource per principle/building block/service/standard/pattern with full SKOS + DCAT metadata
    - All relationships (adoptsFrom, profileOf, composedOf, realizes, deliversIds, etc.) as RDF triples
    - Mapping records as SKOS-style relations
- AND the file MUST be valid Turtle syntax (parseable by Raptor, Jena, Turtle validators)

#### Scenario: Export includes full provenance chain
- GIVEN a principle was created by architect A on 2024-01-01, then modified by architect B on 2024-06-15
- WHEN exported to Turtle
- THEN the resource MUST include both:
  - `dcat:creator "Architect A"` (original)
  - `dcat:modified "2024-06-15"` with author context
  - OR use PROV-O triples for richer change tracking

#### Scenario: Export with filtering
- GIVEN an organization wants to export only principles (not building blocks/services/standards/patterns)
- WHEN using `?format=turtle&types=principle`
- THEN the export MUST contain only NoraPrinciple resources
- AND related mappings to upstream principles MUST be included

### REQ-NORA-007: Full-Text Search with Type-Faceted Results

The browser UI MUST provide full-text search across all five item types with faceted filtering by type.

#### Scenario: Search finds all item types
- GIVEN a NORA catalog with principles, building blocks, services, standards, patterns
- WHEN a user enters "zaak" in the search box
- THEN results MUST include:
  - Principle: "Zaak handling" (matched in title/statement)
  - Building block: "Zaak Management" (exact match in title)
  - Service: "Zaak Workflow Service" (matched in description)
  - Standard: "STUF-ZKN-0310" (matched in definition scopeNote)
- AND results MUST be ranked by relevance (title matches above description, exact matches above partial)

#### Scenario: Type-faceted filtering
- GIVEN the search results above
- WHEN the user clicks "Building Block" facet
- THEN results MUST show only building blocks matching "zaak"
- AND the facet MUST display count: "Building Block (1)"

#### Scenario: Multi-field search
- GIVEN searching for "open standards"
- WHEN the search query is parsed
- THEN it MUST match:
  - Principles with "open" in title and "standards" in implications
  - Standards with "open" in issuer or title
  - Building blocks with "open" in description and "standards" in related standards list

#### Scenario: Search includes mapping relationships
- GIVEN searching for "NORA AP-01"
- WHEN a local principle adopts/profiles this NORA principle
- THEN the local principle MUST appear in search results
- AND search snippet MUST highlight the mapping relationship: "profiles NORA AP-01"

### REQ-NORA-008: Principle Hierarchy Tree Navigation

The browser UI MUST visualize the principle parent/child hierarchy as a tree with expandable nodes.

#### Scenario: Hierarchy tree renders with parent-child relationships
- GIVEN a NORA catalog where:
  - AP (Architecture) is root
  - AP-01 through AP-12 are children of AP
  - AP-01-A and AP-01-B are children of AP-01
- WHEN viewing the Principles page
- THEN a tree MUST display:
  ```
  ▼ AP (Architecture Principles)
    ▶ AP-01 (Open by Default)
      ▶ AP-01-A (Open Standards)
      ▶ AP-01-B (Open Data)
    ▶ AP-02 (...)
    ...
  ```
- AND clicking a parent node MUST highlight all children
- AND clicking a principle name MUST navigate to detail view

#### Scenario: Hierarchy depth is unlimited
- GIVEN principles nested 5 levels deep (AP > AP-1 > AP-1-A > AP-1-A-I > AP-1-A-I-α)
- WHEN rendering the tree
- THEN all levels MUST be visible (with lazy-loading for performance)
- AND expand/collapse controls MUST work at all levels

#### Scenario: Orphan principles (no parent)
- GIVEN a principle with `parentId: null`
- WHEN rendering the tree
- THEN it MUST appear at the root level
- AND a note MUST indicate it is a "root principle" if it has children, or "standalone" if it has none

### REQ-NORA-013: Comply-or-Explain Workflow per Standard

Every standard with status "verplicht" (mandatory) MUST have a `complianceStatus` field: either "toegepast" (adopted) or "niet-toegepast" (not adopted with justification).

#### Scenario: Verplicht standard marked as adopted
- GIVEN a standard marked `status: verplicht` (e.g., STUF-ZKN-0310)
- AND complianceStatus: "toegepast"
- WHEN viewing the standard detail
- THEN it MUST display:
  - Green checkmark badge: "TOEGEPAST (Adopted)"
  - Implementing building blocks or services listed below
  - "No justification needed" message
  - Last compliance verification date

#### Scenario: Verplicht standard marked as not adopted with explanation
- GIVEN a standard marked `status: verplicht` (e.g., NEN-3610 for geographic data)
- AND complianceStatus: "niet-toegepast"
- AND complianceReasoning: "NEN-3610 applies to geographic data and base registries. Amsterdam's geographic data is published via PDOK (national clearing house) which handles NEN-3610 compliance. Amsterdam's internal systems use geographic references via PDOK API rather than direct NEN-3610 implementation."
- AND complianceExpiryDate: "2025-12-31"
- WHEN viewing the standard detail
- THEN it MUST display:
  - Orange "NIET TOEGEPAST (Not Adopted — Justified)" badge
  - Full reasoning text
  - Re-evaluation deadline: "2025-12-31"
  - "Mark as Adopted" button (for architects)

#### Scenario: Compliance dashboard shows backlog
- GIVEN an organization has 50 verplicht standards: 45 toegepast, 5 niet-toegepast
- WHEN viewing the compliance dashboard
- THEN a summary MUST show:
  - "45/50 standards adopted (90%)"
  - List of 5 non-compliant standards with expiry dates
  - Expired non-compliance items flagged for action
  - Trend graph (over time, % compliance growth)

#### Scenario: Expired non-compliance requires action
- GIVEN a standard with complianceExpiryDate: "2024-01-01" (today is 2024-06-15)
- WHEN the compliance dashboard is viewed
- THEN the standard MUST be highlighted in red: "REVIEW REQUIRED — Deadline passed"
- AND an architect MUST be able to either:
  1. Mark as "Adopted" (if compliance achieved)
  2. File a new "niet-toegepast" with fresh reasoning and new expiry date
- AND the action MUST create an audit trail entry

### REQ-NORA-016: Public Read Endpoints; Editing Requires Architect Role

All NORA items MUST be publicly readable (no authentication required); creating/editing/deleting MUST require an authenticated user with the "NORA Architect" role.

#### Scenario: Anonymous user can read public principles
- GIVEN a principle is published with status "vastgesteld"
- WHEN an unauthenticated user requests the principle URI
- THEN HTTP 200 MUST be returned with full content
- AND no login prompt MUST appear

#### Scenario: Search is publicly accessible
- GIVEN the search interface
- WHEN an unauthenticated user performs a search
- THEN results MUST be returned without login
- AND facets and filters MUST work normally

#### Scenario: Draft principle is not publicly visible
- GIVEN a principle with status "draft"
- WHEN an unauthenticated user requests its URI
- THEN HTTP 403 Forbidden or HTTP 404 Not Found MUST be returned
- AND the principle MUST NOT appear in public search results

#### Scenario: Edit UI requires authentication
- GIVEN a user is viewing a principle detail page
- WHEN the user clicks "Edit" button
- THEN either:
  1. A login form appears (if not authenticated), OR
  2. A 403 Forbidden message appears (if authenticated but lacking architect role)
- AND the principle detail page itself remains readable

#### Scenario: Create new principle requires architect role
- GIVEN an authenticated user without "NORA Architect" role
- WHEN attempting to create a new principle via the form or API
- THEN HTTP 403 Forbidden MUST be returned with message: "NORA Architect role required"
- AND the form MUST not be accessible

#### Scenario: Architect role can create/edit/delete
- GIVEN an authenticated user with "NORA Architect" role
- WHEN creating a new principle with all required fields
- THEN HTTP 201 Created MUST be returned with the new principle URI
- AND the principle MUST be created with status "draft" (requires explicit publish to "vastgesteld")
- AND when editing, changes MUST create a new version and audit trail entry

## Implementation Notes

- All five schemas are registered in `lib/Settings/nora-architectuur-publishing_register.json` as OpenRegister schemas
- Seed data (3-5 example principles, building blocks, services, standards, patterns, and mappings per organization) MUST be included in the register template
- Content negotiation (JSON-LD, HTML, RDF-XML, Turtle) implemented via `SerializerFactory` pattern used by OpenRegister
- Search via `IndexService` with `FacetBuilder` for type facets
- Version history via OpenRegister `AuditTrailService`
- Mapping relationships stored as OpenRegister Relations for fast reverse-lookups via `RelationService`
- NORA catalogs are typed OpenCatalogi catalogs with `catalogType: nora-architecture`
- Status transitions (draft → vastgesteld → vervallen) use OpenRegister's standard state-machine
- Public read access is controlled via a custom authorization handler that allows anonymous reads for "vastgesteld" items but requires "NORA Architect" role for modifications
