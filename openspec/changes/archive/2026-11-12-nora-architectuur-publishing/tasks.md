# Tasks: nora-architectuur-publishing

## Task 1: Create OpenRegister Schema Definitions
- **Spec ref**: specs/nora-architectuur-publishing/spec.md — REQ-NORA-001
- **Description**: Define five core schemas (NoraPrinciple, NoraBuildingBlock, NoraService, NoraStandard, NoraPattern) plus NoraMapping in `lib/Settings/nora-architectuur-publishing_register.json`
- **Acceptance criteria**:
  - [ ] All five schemas defined with all required + optional properties per spec
  - [ ] All properties use PascalCase, schema.org vocabulary where applicable
  - [ ] SKOS metadata fields (prefLabel, altLabel, definition, scopeNote) present on all item types
  - [ ] DCAT-AP-NL metadata fields (creator, publisher, issued, modified) present on all item types
  - [ ] Enum fields validated with allowable values matching spec
  - [ ] References to Contact schema use proper vCard-compatible structure
  - [ ] Register template marked as `x-openregister.type: "application"`
  - [ ] All five schemas successfully imported via ConfigurationService on app install

## Task 2: Implement Content Negotiation (JSON-LD, HTML, RDF-XML, Turtle)
- **Spec ref**: specs/nora-architectuur-publishing/spec.md — REQ-NORA-002
- **Description**: Implement stable URIs for all NORA items resolvable to JSON-LD, HTML, RDF-XML, and Turtle formats based on Accept header
- **Acceptance criteria**:
  - [ ] Stable URI pattern `{organization}/architectuur/{type}/{code}` implemented
  - [ ] SerializerFactory pattern integrated to dispatch responses by Content-Type
  - [ ] JSON-LD responses include @context (SKOS + DCAT-AP-NL) and @id for all items
  - [ ] HTML responses render semantic structure with `<header>`, `<main>`, `<nav>`
  - [ ] HTML includes JSON-LD `<script>` tag in `<head>` for metadata
  - [ ] RDF/XML responses valid and parseable by Raptor/Jena validators
  - [ ] Turtle responses valid and parseable by Turtle validators
  - [ ] Browser default (Accept: text/html) returns HTML, not JSON-LD
  - [ ] Content-Type and Content-Disposition headers set correctly for each format
  - [ ] All relationships (adoptsFrom, profileOf, composedOf, etc.) included in RDF exports

## Task 3: Implement Full-Text Search with Type-Faceted Results
- **Spec ref**: specs/nora-architectuur-publishing/spec.md — REQ-NORA-007
- **Description**: Build search UI using IndexService with FacetBuilder for type-faceted results
- **Acceptance criteria**:
  - [ ] Search box on main page and searchable across all five item types
  - [ ] Search matches title, description, statement, rationale, altLabel, definition, scopeNote fields
  - [ ] Results ranked by relevance (exact title matches first, partial matches second)
  - [ ] Type facets displayed with item counts (Principle (5), Building Block (3), etc.)
  - [ ] Clicking facet filters results to that type only
  - [ ] Mapping relationships searchable (local principle appears when searching for upstream NORA URI)
  - [ ] Search snippet highlights matched fields
  - [ ] No authentication required for search (public access)

## Task 4: Implement Principle Hierarchy Tree Navigation
- **Spec ref**: specs/nora-architectuur-publishing/spec.md — REQ-NORA-008
- **Description**: Build tree UI for principle parent/child hierarchy with expand/collapse controls
- **Acceptance criteria**:
  - [ ] Tree renders all principles with parentId relationships
  - [ ] Root principles (parentId: null) appear at top level
  - [ ] Child principles nested under parents with visual indentation
  - [ ] Expand/collapse controls (▼/▶) work at all nesting levels
  - [ ] Unlimited nesting depth supported (lazy-loading for performance)
  - [ ] Clicking principle name navigates to detail view
  - [ ] Clicking parent node highlights all descendants
  - [ ] Standalone principles (no parent, no children) labeled as "root principle" or "standalone"
  - [ ] Tree available on main Principles page and principle detail sidebar

## Task 5: Implement Version History and Diff View
- **Spec ref**: specs/nora-architectuur-publishing/spec.md — REQ-NORA-003
- **Description**: Display version history with before/after snapshots and restore capability
- **Acceptance criteria**:
  - [ ] AuditTrailService queried for all changes to each item
  - [ ] Version History section on detail page lists all versions with timestamp and author
  - [ ] Clicking on version displays full before/after diff with added/removed/changed fields highlighted
  - [ ] "Current" badge marks latest version
  - [ ] "Restore to version X" button available (requires architect role) creates new version entry
  - [ ] Semantic versioning enforced (major.minor.patch)
  - [ ] Backward-compatible changes bump minor version
  - [ ] Breaking changes bump major version
  - [ ] All version changes appear in audit trail with full snapshots

## Task 6: Implement Mapping Table and Reverse-Lookups
- **Spec ref**: specs/nora-architectuur-publishing/spec.md — REQ-NORA-004
- **Description**: Store NoraMapping records and enable bidirectional relationship queries
- **Acceptance criteria**:
  - [ ] NoraMapping schema created with localUri, upstreamUri, mappingType, justification, mappingAuthor, mappingDate
  - [ ] Mapping types (adopts, profiles, extends, replaces, contradicts) validated on create
  - [ ] Mappings stored as OpenRegister Relations for fast reverse-lookups
  - [ ] "Relationship to NORA/GEMMA/PETRA/WILMA/MARIJ/EAR" section displayed on detail pages
  - [ ] Mapping relationship visible (badge + link to upstream URI + justification)
  - [ ] Local principles appear in search when searching for upstream URI they map to
  - [ ] Query "find all organizations mapping to NORA AP-04" returns all matching local principles grouped by organization
  - [ ] Contradicts mappings display with warning color and highlight
  - [ ] Mapping date and author tracked in audit trail

## Task 7: Implement Comply-or-Explain Workflow
- **Spec ref**: specs/nora-architectuur-publishing/spec.md — REQ-NORA-013
- **Description**: Track compliance status for verplicht standards with required justification for non-adoption
- **Acceptance criteria**:
  - [ ] complianceStatus field on NoraStandard: "toegepast" or "niet-toegepast"
  - [ ] Verplicht standards with "toegepast" show green badge and list implementing building blocks/services
  - [ ] Verplicht standards with "niet-toegepast" show orange badge and require complianceReasoning text + complianceExpiryDate
  - [ ] Compliance dashboard shows summary (X/Y standards adopted, %)
  - [ ] Dashboard lists all non-compliant standards with expiry dates
  - [ ] Expired non-compliance (expiryDate passed) flagged in red: "REVIEW REQUIRED"
  - [ ] Architect can mark non-compliant standard as "Adopted" (creates audit entry)
  - [ ] Architect can re-file "niet-toegepast" with new reasoning and expiry date
  - [ ] Compliance history visible in version timeline
  - [ ] Trend graph shows % compliance growth over time

## Task 8: Implement Public Read / Architect-Only Edit Authorization
- **Spec ref**: specs/nora-architectuur-publishing/spec.md — REQ-NORA-016
- **Description**: Configure RBAC so "vastgesteld" items are publicly readable; all modifications require "NORA Architect" role
- **Acceptance criteria**:
  - [ ] Custom authorization handler created (PropertyRbacHandler or custom middleware)
  - [ ] Anonymous users can read "vastgesteld" items (HTTP 200)
  - [ ] Draft items return HTTP 403/404 to anonymous users; not in public search
  - [ ] Edit button appears only for authenticated "NORA Architect" role users
  - [ ] Create form only accessible to authenticated "NORA Architect" role users
  - [ ] Unauthenticated create/edit/delete attempts return HTTP 403
  - [ ] Authenticated non-architect users get HTTP 403 "NORA Architect role required"
  - [ ] Create action sets status: "draft" (requires explicit publish to "vastgesteld")
  - [ ] Publish action (draft → vastgesteld) creates status-change audit entry
  - [ ] Deprecated status controlled same as draft (not publicly visible)

## Task 9: Build NORA Catalog UI — Detail Pages and Forms
- **Spec ref**: specs/nora-architectuur-publishing/spec.md
- **Description**: Implement Vue components for viewing and editing each of the five item types
- **Acceptance criteria**:
  - [ ] Detail page template used for all five item types (principle, building block, service, standard, pattern)
  - [ ] Detail page includes:
    - Header: Code, title, status badge, last modified, author
    - Body: All properties rendered per item type (statement, description, etc.)
    - Relationships section (realizes/realized-by, composedOf, deliversIds, etc.) with links
    - Mapping section (if present): Adopts/profiles/extends upstream with justification
    - Version History section with full audit trail
    - Change History timeline
    - Related items (reverse links to principles realized by building blocks, etc.)
  - [ ] Create/edit form uses schema-driven generation via CnAdvancedFormDialog
  - [ ] Form validation on required fields (code, title, status)
  - [ ] Form includes SKOS metadata fields (prefLabel, altLabel, definition, scopeNote)
  - [ ] Form includes DCAT-AP-NL fields (creator, publisher, issued, modified)
  - [ ] Enum fields render as dropdowns (domain, status, capabilityArea, etc.)
  - [ ] Reference fields (parentId, owner, governanceContact) render as searchable select (Contact picker)
  - [ ] Array fields (implications, examples, realizesIds) allow add/remove items
  - [ ] Save action creates new version entry with audit trail
  - [ ] Unsaved changes warning on page leave

## Task 10: Implement Full Catalog Export (RDF/Turtle)
- **Spec ref**: specs/nora-architectuur-publishing/spec.md — REQ-NORA-005
- **Description**: Export entire NORA catalog to RDF/Turtle format with SKOS + DCAT-AP-NL vocabulary
- **Acceptance criteria**:
  - [ ] Export endpoint: `/apps/nora-architectuur-publishing/export?format=turtle`
  - [ ] Returns HTTP 200 with `Content-Type: text/turtle; charset=utf-8`
  - [ ] Response includes `Content-Disposition: attachment; filename="...ttl"`
  - [ ] File contains all principles, building blocks, services, standards, patterns with full metadata
  - [ ] All relationships included as RDF triples
  - [ ] Mapping records included as SKOS-style relations
  - [ ] @prefix declarations for skos, dcat, dcterms, rdf, rdfs
  - [ ] File is valid Turtle (parseable by Raptor, Jena, validators)
  - [ ] Provenance chain included (dcat:creator, dcat:modified with author context)
  - [ ] Optional filtering: `?format=turtle&types=principle` exports only principles with related mappings

## Task 11: Seed Data Generation
- **Spec ref**: specs/nora-architectuur-publishing/spec.md — REQ-NORA-001
- **Description**: Create seed data (3-5 example items per schema) for dev/test in register template
- **Acceptance criteria**:
  - [ ] 3-5 realistic NoraPrinciple objects with varied domains (governance, informatie, etc.)
  - [ ] 3-5 realistic NoraBuildingBlock objects with capabilityArea coverage
  - [ ] 2-3 realistic NoraService objects with varied serviceLevel
  - [ ] 3-5 realistic NoraStandard objects with varied issuer/type/status and compliance states
  - [ ] 1-2 realistic NoraPattern objects
  - [ ] 5-10 realistic NoraMapping objects demonstrating adopts/profiles/extends/contradicts patterns
  - [ ] All items use Dutch values (gemeente/organization names, Dutch street names, valid postcodes, realistic KVK codes)
  - [ ] Seed data distinguishable from real (fictional but realistic)
  - [ ] Hierarchies demonstrable (principle with children, building blocks composed of others, etc.)
  - [ ] @self envelope applied to all objects for idempotent import
  - [ ] Seed data loaded on install alongside schemas via ConfigurationService
  - [ ] Re-import skips duplicates (matched by slug)

## Task 12: Integration Testing
- **Spec ref**: specs/nora-architectuur-publishing/spec.md
- **Description**: Test all features end-to-end: schema, CRUD, search, versioning, mappings, authorization, exports
- **Acceptance criteria**:
  - [ ] CRUD operations (create, read, update, delete) work for all five item types
  - [ ] Stable URIs resolve correctly to all formats (JSON-LD, HTML, RDF-XML, Turtle)
  - [ ] Search finds all item types with correct relevance ranking
  - [ ] Type facets filter correctly and show accurate counts
  - [ ] Principle hierarchy tree renders with unlimited nesting
  - [ ] Version history tracks all changes with before/after snapshots
  - [ ] Diff view highlights changes correctly
  - [ ] Mappings stored and queryable (reverse-lookup works)
  - [ ] Comply-or-explain workflow enforces required fields for niet-toegepast
  - [ ] Authorization: public read, architect-only edit, draft not visible to public
  - [ ] Full catalog export produces valid Turtle with all items
  - [ ] Seed data loads without duplicates on re-install
  - [ ] All audit trail entries created with timestamps and authors

## Task 13: Documentation
- **Spec ref**: specs/nora-architectuur-publishing/spec.md
- **Description**: Write user and developer documentation
- **Acceptance criteria**:
  - [ ] User guide: How to create/edit principles, building blocks, services, standards, patterns
  - [ ] User guide: How to map local concepts to national NORA-family
  - [ ] User guide: How to track compliance via comply-or-explain workflow
  - [ ] User guide: How to search, filter, view hierarchy, inspect version history
  - [ ] User guide: How to export catalog to RDF/Turtle
  - [ ] Developer guide: Schema definitions and property descriptions
  - [ ] Developer guide: API endpoints for all five item types (CRUD)
  - [ ] Developer guide: Content negotiation (Accept headers)
  - [ ] Developer guide: Authorization model (public read, architect-only edit)
  - [ ] Developer guide: Mapping table and reverse-lookups
  - [ ] Architecture decision record: Why five separate schemas vs. one polymorphic schema
  - [ ] ADR: Content negotiation implementation strategy (SerializerFactory)
  - [ ] ADR: Why Turtle/RDF-XML exports use SKOS + DCAT-AP-NL (not other ontologies)

## Task 14: Reuse Analysis (Deduplication Check)
- **Spec ref**: specs/nora-architectuur-publishing/spec.md — ADR-001
- **Description**: Verify no overlap with existing OpenRegister services or @conduction/nextcloud-vue components
- **Acceptance criteria**:
  - [ ] ObjectService used for all CRUD (no custom Entity/Mapper layer)
  - [ ] CnDetailPage + CnDetailGrid used for detail views (no custom detail component)
  - [ ] CnFormDialog / CnAdvancedFormDialog used for create/edit forms (no custom form builder)
  - [ ] IndexService used for search, FacetBuilder for facets (no custom search endpoint)
  - [ ] AuditTrailService used for version history (no custom change-tracking)
  - [ ] FileService used if exporting to files (no custom upload/download handlers)
  - [ ] RelationService used for mapping relationships (no foreign keys)
  - [ ] SerializerFactory pattern used for content negotiation (no custom JSON/RDF converters)
  - [ ] No overlap found or justified if some custom logic added
  - [ ] Document findings in task comment even if "no overlap found"

## Task 15: Forum Standaardisatie Integration (Optional)
- **Spec ref**: specs/nora-architectuur-publishing/spec.md — REQ-NORA-017 (Should)
- **Description**: Nightly sync of Forum Standaardisatie pas-toe-of-leg-uit mandatory standards list into NoraStandard records
- **Acceptance criteria**:
  - [ ] OpenConnector connector configured for forumstandaardisatie.nl JSON feed
  - [ ] Scheduled job (nightly, 2 AM) pulls latest mandatory standards list
  - [ ] For each standard in feed: create or update NoraStandard record if not present
  - [ ] Standard status set to "verplicht" per feed; complianceStatus defaults to "niet-toegepast"
  - [ ] complianceReasoning and complianceExpiryDate default to null (architect must fill in)
  - [ ] Dashboard flags missing complianceReasoning for any verplicht standard: "Action Required"
  - [ ] No manual intervention required; architectural decisions (adopted vs. not-adopted) remain manual
  - [ ] Audit log captures sync operations and any conflicts resolved
  - [ ] Feature toggleable in app settings (nightly sync on/off)
