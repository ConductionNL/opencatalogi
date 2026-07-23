# Tasks: gemma-gegevenscatalogus

## Task 1: Data Schema Implementation
**Spec refs**: specs/gemma-gegevenscatalogus/spec.md (REQ-GEM-001)  
**Status**: todo

Create the five new database schemas in opencatalogi:
- [ ] Define `GemmaCatalogus` table with fields: versie, releaseDatum, status, taal, bron, importedAt, importedBy, aantalObjecttypen, aantalAttributen, aantalRelaties, wijzigingstype, voorganger (FK)
- [ ] Define `GemmaObjecttype` table with: naam, nameSpace, urn, definitie, domein (enum), subtype (enum), parent (FK), abstract, herkomst, geldigVan, geldigTot, vervangenDoor (FK), catalogus (FK)
- [ ] Define `GemmaAttribuut` table with: naam, definitie, datatype (enum), formaat, lengte, cardinaliteit (enum), autoriteit, herkomstWetgeving, kerngegeven, gevoeligheid (enum), voorbeelden (JSON), objecttype (FK)
- [ ] Define `GemmaRelatie` table with: naam, definitie, cardinaliteit, rol, omgekeerdeRol, aggregatieType (enum), vanObjecttype (FK), naarObjecttype (FK)
- [ ] Define `GemmaMapping` table with: gemmaObjecttype (FK), localSchema (FK openregister.Schema), mappingKwaliteit (enum), attribuutMappings (JSONB), relatieMappings (JSONB), validatieStatus (enum), gevalideerdOp, gevalideerdDoor (FK), opmerkingen, status (enum: active/vervallen), createdAt, updatedAt
- [ ] Add indices on: catalogus.versie, objecttype.domein, objecttype.urn, mapping.localSchema, mapping.gemmaObjecttype
- [ ] Add migrations with rollback support
- [ ] Run schema tests to verify structure matches spec
- [ ] Document schema version for API clients

## Task 2: SKOS/RDF Import Pipeline
**Spec refs**: specs/gemma-gegevenscatalogus/spec.md (REQ-GEM-001)  
**Status**: todo

Implement the async import job that parses GEMMA release files:
- [ ] Choose RDF parser library: evaluate rdf4j (Java-based, via PHP bridge), ARC2 (PHP-native), EasyRdf (PHP-native). Recommend: EasyRdf for PHP alignment
- [ ] Create `GemmaImportJob` class with methods: `parseSkos()`, `parseOWL()`, `transformToSchemas()`
- [ ] Implement parser to extract from Turtle/RDF:
  - [ ] All SKOS:Concept → GemmaObjecttype records
  - [ ] SKOS:hiddenLabel, SKOS:altLabel → synoniemen
  - [ ] SKOS:definition → definitie, SKOS:scopeNote → toelichting
  - [ ] SKOS:broader/narrower → parent hierarchy
  - [ ] RDF properties (dcterms:issued, rdfs:domain, custom GEMMA-vocab) → domein, subtype, herkomst, valid-dates
  - [ ] OWL Object/Datatype properties → GemmaRelatie and GemmaAttribuut
- [ ] Implement external ontology resolution:
  - [ ] Cache fetched ontologies locally (Redis or filesystem) with TTL
  - [ ] HTTP GET with retry logic (3 retries, exponential backoff)
  - [ ] Fallback: if ontology fetch fails, log warning and continue (do not block import)
- [ ] Implement bulk insert optimization:
  - [ ] Batch inserts in transactions (1,000 records per batch)
  - [ ] Measure and benchmark import time for GEMMA 3.0 (target: < 30 min)
  - [ ] Profile to identify bottlenecks (parsing vs. DB insertion)
- [ ] Implement idempotency check:
  - [ ] Before importing, detect if same GemmaCatalogus.versie already exists
  - [ ] If exists, allow re-import with update semantics (upsert)
  - [ ] Flag affected GemmaMappings with `validatieStatus="gewaarschuwd"`
- [ ] Add concurrency guard:
  - [ ] Mutual exclusion: only one import job can run at a time
  - [ ] Return 409 Conflict if second import is attempted
  - [ ] Log import job status to admin for visibility (start time, ETA, progress %)
- [ ] Implement error handling:
  - [ ] Parse errors: log line number, error detail, continue (don't fail entire import)
  - [ ] Missing required fields: warn and skip that record
  - [ ] Email admin on import completion with summary: objects created, attributes parsed, time elapsed, any warnings
- [ ] Create CLI command: `bin/console gemma:import <file-path>` for manual trigger and cron-based scheduling
- [ ] Unit tests:
  - [ ] Test parsing minimal SKOS file (5 objects, 10 attrs)
  - [ ] Test idempotency (re-import same version)
  - [ ] Test external ontology fallback (mock HTTP failure)
  - [ ] Test concurrent import rejection
  - [ ] Benchmark test: import GEMMA 3.0 fixture, assert < 30 min

## Task 3: Browse UI for GEMMA Catalogus
**Spec refs**: specs/gemma-gegevenscatalogus/spec.md (REQ-GEM-002)  
**Status**: todo

Build the frontend interface for browsing and searching:
- [ ] Create route `/gemma-catalogus` in opencatalogi admin
- [ ] Create main browse component:
  - [ ] Left sidebar with facets (domein, status, subtype, kerngegeven) with count badges
  - [ ] Facet filtering via query parameters (`?domain=BAG&coreDataOnly=true`)
  - [ ] Main content area with paginated list (default 25/page, sorting A–Z)
  - [ ] List item card showing: object type name, domain tag, attribute count
- [ ] Implement search bar:
  - [ ] Full-text search across naam, synoniemen, definitie, attribute names
  - [ ] Results ranked by relevance (exact name matches first)
  - [ ] Highlight matching text in results
  - [ ] Display result count
  - [ ] Search must complete in < 500ms (use database indices)
- [ ] Create object type detail page:
  - [ ] Route: `/gemma-catalogus/objecttype/:id` (by UUID or URN)
  - [ ] Display: naam, definitie, toelichting, domein, herkomst, geldigVan/geldigTot
  - [ ] Attributes table: naam, datatype, cardinaliteit, autoriteit, kerngegeven badge, voorbeelden
  - [ ] Relationships section: incoming (other objects → this) and outgoing (this → other objects)
  - [ ] Links: "Open op GEMMA-online" (construct gemmaonline.nl URL), "GitHub discussie"
  - [ ] Synoniemen list
  - [ ] If deprecated (geldigTot < now): show banner with vervangenDoor link
  - [ ] "See also" suggestions (related object types by domain/relationship)
- [ ] Implement multi-facet filtering logic:
  - [ ] URL sync: facet state ↔ query parameters (use browser History API)
  - [ ] Facet counts update dynamically as filters are applied
  - [ ] "Clear filters" button
- [ ] Integrate with existing opencatalogi design system (use Nextcloud components: NcCard, NcButton, NcSelect)
- [ ] Responsive design: mobile-friendly detail pages, collapsible facets on mobile
- [ ] Accessibility (WCAG 2.1 AA):
  - [ ] Keyboard navigation (Tab, Enter on facets)
  - [ ] ARIA labels on interactive elements
  - [ ] Color-not-only indicators for badges (use icons + text)
- [ ] Performance:
  - [ ] Lazy-load attribute tables on detail page (scroll pagination)
  - [ ] Cache search results in localStorage/sessionStorage for UX (clear on new search)
  - [ ] Use database query optimization (indices on domein, naam)
- [ ] Unit/integration tests:
  - [ ] Test facet filtering (single + multi)
  - [ ] Test search (exact matches, fuzzy, no results)
  - [ ] Test detail page loading
  - [ ] Test responsive layout

## Task 4: Schema Mapping UI
**Spec refs**: specs/gemma-gegevenscatalogus/spec.md (REQ-GEM-003)  
**Status**: todo

Build the drag-and-drop mapping interface:
- [ ] Add "Koppel aan GEMMA-standaard" button on openregister Schema detail pages
- [ ] Create modal: GEMMA object type selection
  - [ ] Search field with autocomplete
  - [ ] Suggestion engine: match schema name + properties to GEMMA object types
  - [ ] Rank suggestions by match score (0–100%)
  - [ ] Show top 5 suggestions with description
- [ ] Create mapping canvas component:
  - [ ] Left pane: local schema properties (sortable, searchable)
  - [ ] Right pane: GEMMA object type attributes (filterable by cardinality/required)
  - [ ] Center: drag-and-drop area with connection lines
  - [ ] Pre-fill obvious mappings (name similarity, datatype match)
  - [ ] Support: 1:1, 1:N, and unmapped properties
- [ ] Create transformation rule editor:
  - [ ] Modal/inline editor for each mapping
  - [ ] Input: source datatype, target datatype
  - [ ] Provide templates: "direct", "Parse ISO 8601", "Trim whitespace", etc.
  - [ ] Allow freeform rule text with syntax highlighting (JEXL or simple DSL)
  - [ ] Validate transformation syntax (warn on obvious errors)
  - [ ] Show example: "Input: '2000-05-15' → Output: Date(2000, 5, 15)"
- [ ] Implement validation engine:
  - [ ] Count required attributes (cardinaliteit: 1..)
  - [ ] Check coverage: mapped count vs. required count
  - [ ] Auto-calculate mappingKwaliteit: volledig (100%), partieel (< 100%), geen (0%)
  - [ ] Show validation report: "4/8 required mapped. Missing: [list]"
  - [ ] Suggest auto-fix for obvious unmapped attributes
- [ ] Create mapping save flow:
  - [ ] Allow save even with partieel mapping (but show warning)
  - [ ] Create GemmaMapping record in DB
  - [ ] Set `status="active"`, `validatieStatus="gevalideerd"` (if volledig)
  - [ ] Create audit record in audit table
  - [ ] Show success: "✅ Mapping saved"
  - [ ] Return to schema detail, show GEMMA badge/link
- [ ] Implement edit flow:
  - [ ] Allow architect to re-open mapping for editing
  - [ ] Show current mapping state (pre-populated canvas)
  - [ ] Allow add/remove/modify mappings
  - [ ] Create new audit record on save (with diff)
- [ ] UI/UX:
  - [ ] Drag-and-drop with visual feedback (highlight drop zones)
  - [ ] Validation feedback inline (as user maps)
  - [ ] Helpful tooltips explaining cardinaliteit, datatype, required vs. optional
  - [ ] Responsive: map canvas should work on tablets (touch-friendly)
- [ ] Tests:
  - [ ] Test suggestion engine (schema name matching)
  - [ ] Test drag-drop interaction
  - [ ] Test validation logic (volledig/partieel/geen determination)
  - [ ] Test transformation rule parsing

## Task 5: API Endpoints for Mapping & Compliance
**Spec refs**: specs/gemma-gegevenscatalogus/spec.md (REQ-GEM-003, REQ-GEM-005, REQ-GEM-006)  
**Status**: todo

Implement backend APIs for mapping and compliance operations:
- [ ] Create API endpoints:
  - [ ] `POST /api/gemma-mappings` — Create new mapping
  - [ ] `PUT /api/gemma-mappings/:id` — Update existing mapping
  - [ ] `DELETE /api/gemma-mappings/:id` — Soft-delete (set status=vervallen)
  - [ ] `POST /api/gemma-mappings/:id/validate` — Run validation, return quality + details
  - [ ] `GET /api/registers/:registerId/compliance-report` — Generate compliance report
  - [ ] `POST /api/registers/:registerId/compliance-report/export` — Export as PDF/CSV
  - [ ] `GET /api/gemma-catalogus/:id/diff/:prevId` — Get diff between two versions
- [ ] Implement mapping CRUD service:
  - [ ] Validation: only schema owner or admin can modify
  - [ ] Create/update triggers audit record creation
  - [ ] Track createdAt, updatedAt on GemmaMapping
  - [ ] Soft-delete: preserve record, set status=vervallen
  - [ ] Retrieve mapping by schema or by objecttype
- [ ] Implement compliance report generation:
  - [ ] Input: Register ID
  - [ ] Logic: count all Schemas in Register, aggregate GemmaMapping.mappingKwaliteit
  - [ ] Calculate: volledig %, partieel %, no mapping %
  - [ ] Output: JSON with aggregates + per-schema breakdown
  - [ ] Cache in Redis (TTL 1 hour); invalidate on mapping change
  - [ ] Include last-generated timestamp
- [ ] Implement PDF export (via docudesk):
  - [ ] Call docudesk API with report data + template
  - [ ] Include letterhead, timestamp, signature field
  - [ ] Return downloadable PDF file
  - [ ] Gracefully handle docudesk unavailability (fallback to HTML view)
- [ ] Implement CSV export:
  - [ ] Flatten report to CSV rows (Schema Name, GEMMA Object Type, Quality, Mapped Attrs, Total, %)
  - [ ] Include header row
  - [ ] Return Content-Type: text/csv
- [ ] Implement version diff detection:
  - [ ] Compare two GemmaCatalogus versions by URN matching
  - [ ] Detect: objecttypenToegevoegd, objecttypenVerwijderd, objecttypenGewijzigd
  - [ ] For gewijzigd: show attr-level changes (attr added, removed, renamed)
  - [ ] Flag affected GemmaMappings with validatieStatus=gewaarschuwd
  - [ ] Send notifications to mapping owners
- [ ] Implement GEMMA validation hook (REQ-GEM-006):
  - [ ] Register hook in openregister object validation pipeline
  - [ ] Check: if object's schema has `gemmaObjecttype` and `gemmaMappingKwaliteit=volledig`
  - [ ] If Register `gemmaValidationMode=strict`: reject (HTTP 422) missing required GEMMA attributes
  - [ ] If `mode=warn`: succeed with X-GEMMA-Validation-Warnings header
  - [ ] If `mode=off`: skip validation
  - [ ] Validate datatype, cardinality, enumeration constraints per mapping
- [ ] Error handling:
  - [ ] 400 Bad Request for invalid mapping data
  - [ ] 404 Not Found for non-existent mapping/schema
  - [ ] 409 Conflict if concurrent modify
  - [ ] 422 Unprocessable Entity for GEMMA validation failures
  - [ ] Return descriptive error messages
- [ ] Tests:
  - [ ] Test create/read/update/delete mapping
  - [ ] Test compliance report calculation
  - [ ] Test version diff detection
  - [ ] Test GEMMA validation (strict/warn/off modes)
  - [ ] Test permission checks (non-owner cannot modify)
  - [ ] Integration test: create schema → create mapping → validate → check compliance report

## Task 6: Audit Trail System
**Spec refs**: specs/gemma-gegevenscatalogus/spec.md (REQ-GEM-010)  
**Status**: todo

Implement immutable audit logging for all mapping changes:
- [ ] Create `gemma_mapping_audit` table:
  - [ ] `id` (PK), `gemmaMapping` (FK), `timestamp`, `who` (FK User), `action` (enum: CREATE, UPDATE, DELETE, VALIDATE), `details` (JSONB), `why` (text), `signature` (optional HMAC/cert)
  - [ ] Add index on `gemmaMapping`, `timestamp`, `who`
  - [ ] Make table append-only (no UPDATE, only INSERT; enforce in code)
- [ ] Create audit event emitters:
  - [ ] On GemmaMapping create: emit CREATE event
  - [ ] On GemmaMapping update: emit UPDATE event with before/after diff
  - [ ] On GemmaMapping soft-delete: emit DELETE event
  - [ ] On GemmaMapping validate: emit VALIDATE event with result (quality, details)
  - [ ] Capture: who (User ID), when (timestamp), what (details JSON), why (optional comment field in UI)
- [ ] Implement audit API:
  - [ ] `GET /api/gemma-mappings/:id/audit` — Retrieve full audit history
  - [ ] `GET /api/audit/gemma-mappings?filter=schema:MijnPersoon` — Query audit by mapping/schema
  - [ ] Support filtering by: gemmaMapping, dateRange, action, who
  - [ ] Return paginated results (default 50/page)
- [ ] Implement audit export:
  - [ ] `POST /api/audit/export?format=pdf` — Export as PDF
  - [ ] Include: full history, timestamps, actors, actions, changes
  - [ ] Add signature (HMAC or certificate) to PDF for authenticity
  - [ ] Include disclaimer: "This audit log is immutable and legally significant"
  - [ ] Suitable for board/auditor submission
  - [ ] `POST /api/audit/export?format=json` — Export as JSON (for programmatic use)
- [ ] Security:
  - [ ] Only audit admins or schema owners can view audit trail for their schemas
  - [ ] Audit table itself is read-only by application (no app-side deletes)
  - [ ] Consider database-level row-level security (if using PostgreSQL)
  - [ ] Signature/HMAC ensures tampering is detectable
- [ ] Tests:
  - [ ] Test audit record creation on each action (create, update, delete, validate)
  - [ ] Test audit immutability (can't edit/delete old records)
  - [ ] Test audit query filtering
  - [ ] Test audit export (PDF, JSON)

## Task 7: Domain Export as JSON-LD
**Spec refs**: specs/gemma-gegevenscatalogus/spec.md (REQ-GEM-008)  
**Status**: todo

Implement JSON-LD export for domain-specific subsets:
- [ ] Create export service:
  - [ ] Input: domain name (BAG, BRP, etc.), optional GemmaCatalogus version (default: active)
  - [ ] Logic: query all GemmaObjecttypen where domein=<domain>, fetch related attributes/relations
  - [ ] Build JSON-LD graph with proper context (W3C JSON-LD 1.1)
  - [ ] Include PROV-O metadata: source GEMMA version, export date, curator
  - [ ] Include optional @mapping section for objects with active GemmaMappings
- [ ] Create export caching:
  - [ ] Cache exported JSON-LD in Redis/filesystem with TTL 7 days
  - [ ] Cache key: `gemma_export_{domain}_{version}`
  - [ ] Invalidate cache on: active GemmaCatalogus change, GemmaMappings update
- [ ] Implement export API:
  - [ ] `GET /api/gemma-catalogus/domains/:domainName/export.jsonld` — Download JSON-LD file
  - [ ] `GET /api/gemma-catalogus/domains/:domainName/export.jsonld?version=3.0` — Specific version
  - [ ] Return Content-Type: application/ld+json
  - [ ] Return Content-Disposition: attachment (force download)
- [ ] Add export button to browse UI:
  - [ ] When viewing domain-filtered results, show "Exporteer als JSON-LD" button
  - [ ] Trigger download of domain-specific export
  - [ ] Show info: "Contains X object types, Y attributes, Z relationships from GEMMA {version}"
- [ ] JSON-LD structure:
  - [ ] `@context`: includes GEMMA vocab, PROV-O, standard RDF terms
  - [ ] `@id`: URI for export package
  - [ ] `@type`: `gemma:DomainExport`
  - [ ] `prov:wasGeneratedBy`, `prov:generatedAtTime`: provenance
  - [ ] Array of objects: each GemmaObjecttype with nested attributes/relations
  - [ ] Example for BAG: 150+ object types (Verblijfsobject, Pand, Adres, etc.)
- [ ] Tests:
  - [ ] Test export generation for each domain
  - [ ] Test JSON-LD validity (can be parsed by jsonld.org processors)
  - [ ] Test caching (re-export returns cached version)
  - [ ] Test cache invalidation (new mappings clear cache)
  - [ ] Test performance (BAG export < 5 sec)

## Task 8: Attribute Suggestions on Schema Creation
**Spec refs**: specs/gemma-gegevenscatalogus/spec.md (REQ-GEM-009)  
**Status**: todo

Implement smart attribute suggestion when creating openregister schemas:
- [ ] Integrate with openregister schema creation workflow:
  - [ ] Hook into schema POST endpoint
  - [ ] After schema name and initial properties are submitted, invoke suggestion engine
  - [ ] Display suggestions modal before confirming schema creation
- [ ] Create suggestion engine:
  - [ ] Input: schema name, initial property list
  - [ ] Query GEMMA objecttypes: match by name (fuzzy, case-insensitive)
  - [ ] Rank matches by name similarity score (Levenshtein distance or similar)
  - [ ] Return top 3 suggestions with confidence scores
  - [ ] Example: schema "Inwoner" → suggestions: Persoon (95%), Ingeschrevennatuurlijkpersoon (80%)
- [ ] Create attribute suggestion UI:
  - [ ] Modal showing suggested GEMMA object types
  - [ ] User selects one (e.g., "Persoon")
  - [ ] For each user-provided property, query GEMMA attributes on that objecttype
  - [ ] Match property name to GEMMA attribute name (fuzzy)
  - [ ] Show suggestions with: GEMMA attr name, datatype, cardinality, examples
  - [ ] User can accept/skip each suggestion
- [ ] Implement auto-mapping on acceptance:
  - [ ] When user accepts attribute suggestion (e.g., "birthDate" → `geboortedatum`), create schema property with:
    - [ ] name: user-chosen (e.g., "birthDate")
    - [ ] title: from GEMMA (e.g., "Geboortedatum")
    - [ ] type/datatype: from GEMMA (e.g., "date")
    - [ ] cardinality: from GEMMA (e.g., "0..1")
    - [ ] description: from GEMMA (e.g., definition)
  - [ ] On schema save: auto-create GemmaMapping with mappingKwaliteit=volledig (if all properties mapped)
- [ ] Fuzzy matching logic:
  - [ ] Support partial matches: "geboerte" → [geboortedatum, geboorteplaats, geboorteland]
  - [ ] Case-insensitive
  - [ ] Levenshtein distance threshold: suggest if distance < 40% of longer string length
- [ ] Tests:
  - [ ] Test object type suggestion (exact name, fuzzy, multi-word)
  - [ ] Test attribute suggestion (single/multi property schemas)
  - [ ] Test auto-mapping creation (verify GemmaMapping.volledig)
  - [ ] Test fuzzy matching thresholds

## Task 9: Version Tracking and Migration
**Spec refs**: specs/gemma-gegevenscatalogus/spec.md (REQ-GEM-004)  
**Status**: todo

Implement version diff detection and migration support:
- [ ] Implement version comparison service:
  - [ ] Input: two GemmaCatalogus versions (e.g., 2.6 → 3.0)
  - [ ] Match objecttypes by URN (canonical identifier, stable across versions)
  - [ ] Compare attributes/relations by URN
  - [ ] Detect: objecttypenToegevoegd (new URNs), objecttypenVerwijderd (missing URNs), objecttypenGewijzigd (attribute changes)
  - [ ] For gewijzigd, show per-attribute changes: attr renamed, removed, new, datatype changed, cardinality changed
  - [ ] Output: structured diff report
- [ ] Implement fuzzy remapping suggestions:
  - [ ] For renamed attributes (e.g., `eigenaar` → `verantwoordelijke`), match by URN history
  - [ ] If URN match unavailable, use fuzzy name matching (Levenshtein) with confidence score
  - [ ] Suggest auto-remapping if confidence > 80%
  - [ ] Include: old attr, new attr, confidence, user can accept/reject
- [ ] Flag affected mappings:
  - [ ] After version import, query GemmaMappings pointing to changed objecttypes
  - [ ] Set `validatieStatus="gewaarschuwd"` if target objecttype was modified
  - [ ] Set `validatieStatus="conflict"` if target objecttype deprecated (geldigTot < now)
  - [ ] For deprecated objects, suggest `vervangenDoor` object as replacement
  - [ ] Create audit record for each flagged mapping
- [ ] Implement notification system:
  - [ ] Send email to mapping owners (data architects): "GEMMA version updated. Review your mappings: [link to affected mappings]"
  - [ ] Include summary: X mappings require review, Y have conflicts, Z are OK
  - [ ] Provide direct action link to mapping editor
- [ ] Implement version switch:
  - [ ] Allow admin to set a new GemmaCatalogus as active (`status="vastgesteld"`)
  - [ ] On switch: invalidate all cached compliance reports and JSON-LD exports
  - [ ] Trigger background job to re-compute all Register compliance percentages
  - [ ] Send notification to CIO: "GEMMA catalogus switched to v3.0. Compliance reports updated. [X mappings require action]"
- [ ] Create migration report UI:
  - [ ] Route: `/gemma-catalogus/migration/3.0` (for a specific target version)
  - [ ] Show: diff summary, affected mappings, suggested remappings, status per mapping
  - [ ] Allow bulk accept of remapping suggestions
  - [ ] Track migration progress (X mappings reviewed, Y accepted, Z pending)
- [ ] Tests:
  - [ ] Test diff detection (added/removed/modified objects)
  - [ ] Test URN-based matching (renamed attributes)
  - [ ] Test fuzzy matching (fallback if no URN)
  - [ ] Test mapping flagging (gewaarschuwd/conflict logic)
  - [ ] Test migration report generation

## Task 10: Integration with openregister
**Spec refs**: specs/gemma-gegevenscatalogus/spec.md (REQ-GEM-003, REQ-GEM-006)  
**Status**: todo

Integrate GEMMA features into openregister workflows:
- [ ] Extend openregister Schema entity:
  - [ ] Add `gemmaObjecttype` (FK) field — canonical GEMMA reference
  - [ ] Add `gemmaMappingKwaliteit` (enum: volledig/partieel/geen) — denormalized from latest GemmaMapping
  - [ ] Migrate existing Schemas to null (no GEMMA reference initially)
  - [ ] Create UI to display these fields in schema admin
- [ ] Add "Koppel aan GEMMA" button to Schema detail pages:
  - [ ] In schema admin, add action button "Map to GEMMA"
  - [ ] Open mapping UI (Task 4)
  - [ ] After mapping save, update Schema.gemmaObjecttype and gemmaMappingKwaliteit
- [ ] Integrate GEMMA attribute suggestion (Task 8):
  - [ ] On schema creation POST, invoke suggestion engine
  - [ ] Display modal with GEMMA matches before finalizing schema
  - [ ] Allow user to select suggested object type and auto-populate attributes
- [ ] Implement GEMMA validation hook (Task 5, REQ-GEM-006):
  - [ ] Register listener in openregister object validation pipeline
  - [ ] Before object create/update, check if schema has `gemmaObjecttype` + active mapping
  - [ ] If Register `gemmaValidationMode=strict`: validate GEMMA-required attributes and datatypes
  - [ ] If `mode=warn`: allow create with warning headers
  - [ ] If `mode=off`: skip validation
  - [ ] Return clear error messages referencing GEMMA object type and attributes
- [ ] Add Register-level settings:
  - [ ] New field: `gemmaValidationMode` (enum: strict, warn, off; default: off)
  - [ ] UI: admin can toggle per-Register
  - [ ] Documented: when/why to use each mode (strict for production, warn for migration, off for non-standard)
- [ ] Tests:
  - [ ] Test Schema.gemmaObjecttype display
  - [ ] Test mapping button integration
  - [ ] Test attribute suggestion on schema create
  - [ ] Test GEMMA validation hook (strict/warn/off)
  - [ ] Test permission checks (only schema owner can map)

## Task 11: Compliance Reporting and Export
**Spec refs**: specs/gemma-gegevenscatalogus/spec.md (REQ-GEM-005)  
**Status**: todo

Implement real-time compliance reporting with caching and export:
- [ ] Create compliance report generation service:
  - [ ] Input: Register ID
  - [ ] Logic: for each Schema in Register, fetch latest GemmaMapping
  - [ ] Aggregate mapping qualities: count volledig, partieel, none
  - [ ] Calculate: compliance % = (volledig count / total schemas) * 100
  - [ ] Include per-schema details: name, GEMMA objecttype, mapping quality, mapped attrs, total attrs, missing attrs list
  - [ ] Sort by compliance (lowest first, to show problem areas)
- [ ] Implement report caching:
  - [ ] Cache report in Redis with TTL 1 hour (configurable)
  - [ ] Cache key: `compliance_report_{registerId}`
  - [ ] On cache hit: return cached report (< 200ms)
  - [ ] On cache miss: generate fresh report (< 15 sec for typical Register)
  - [ ] Invalidate cache when: GemmaMapping updated, GemmaCatalogus switched, cron expires TTL
- [ ] Create compliance report UI:
  - [ ] Route: `/compliance-reports`
  - [ ] Select Register from dropdown
  - [ ] Display: register name, active GEMMA version, compliance % (large, prominent)
  - [ ] Show bar chart: volledig %, partieel %, none %
  - [ ] Show per-schema table: schema name, GEMMA objecttype, quality, progress bar, action links
  - [ ] Action links per schema: "View mapping", "Edit mapping", "Details"
  - [ ] Summary section: "X schemas ready for use. Y need completion. Z not mapped."
  - [ ] Last generated timestamp and "Regenerate now" button
- [ ] Implement PDF export:
  - [ ] Call docudesk API with report data
  - [ ] Template: official letterhead, timestamp, compliance data, recommendations
  - [ ] Include signature field (for signing before board submission)
  - [ ] Return downloadable PDF: `compliance-{registername}-{date}.pdf`
  - [ ] Graceful fallback if docudesk unavailable (generate HTML + print-friendly CSS)
- [ ] Implement CSV export:
  - [ ] Flatten to rows: RegisterName, SchemaName, GemmaObjecttype, Quality, MappedAttrs, TotalRequired, CompliancePercent
  - [ ] Include header row with explanation
  - [ ] Return Content-Type: text/csv with filename
- [ ] Implement monthly cron job:
  - [ ] Schedule: first of each month, 00:30 UTC
  - [ ] Generate compliance report for each Register
  - [ ] Compare to previous month: if compliance % changed by ≥ 5%, send notification to CIO
  - [ ] Store report in audit table for historical tracking
  - [ ] Log execution (success/failure) for monitoring
- [ ] Tests:
  - [ ] Test compliance calculation (various volledig/partieel/none combinations)
  - [ ] Test caching (hit/miss/invalidation)
  - [ ] Test report generation performance (< 15 sec)
  - [ ] Test export (PDF, CSV) generation
  - [ ] Test monthly cron (mock time, verify generated correctly)
  - [ ] Test notification logic (% change threshold)

## Task 12: Integration with Existing Apps
**Spec refs**: specs/gemma-gegevenscatalogus/spec.md (cross-app section)  
**Status**: todo

Implement integrations with dependent apps:
- [ ] Integration with softwarecatalog:
  - [ ] Add "GEMMA Compliant" claim/badge to software products
  - [ ] Link software product to GemmaMapping(s) as proof
  - [ ] Display compliance level (volledig/partieel) on product page
  - [ ] API: `/softwarecatalog/api/software/:softwareId/gemma-compliance` returns linked mappings
- [ ] Integration with mydash:
  - [ ] Add KPI widgets: GEMMA Compliance %, Unmapped Schemas, Avg Mapping Age
  - [ ] Plot compliance over time (monthly trend)
  - [ ] Alert if compliance drops below threshold
  - [ ] Expose via mydash API for dashboard consumption
- [ ] Integration with docudesk:
  - [ ] Use docudesk for PDF rendering (compliance reports, audit exports)
  - [ ] Template design: official letterhead, compliance table, signature field
  - [ ] Support for signing/archiving (integrate with docudesk signing API if available)
- [ ] Integration with decidesk (future):
  - [ ] Tag governance documents by referenced GEMMA object types
  - [ ] Example: raadsbesluit about "Verblijfsobject" auto-tagged with that objecttype
  - [ ] Enables impact analysis ("which decisions mention this objecttype?")
- [ ] Integration with openconnector:
  - [ ] Use GemmaMapping transformations as mapping layer for external system integration
  - [ ] Example: connector can use mapping rules to transform external data to local schema
- [ ] API documentation:
  - [ ] Document all GEMMA APIs in OpenAPI/Swagger format
  - [ ] Include examples: create mapping, fetch compliance, export JSON-LD
  - [ ] Publish to API docs portal (if exists)
- [ ] Tests:
  - [ ] Test softwarecatalog linking (create software → link to GemmaMapping)
  - [ ] Test mydash KPI fetch
  - [ ] Test docudesk PDF generation
  - [ ] Integration test: end-to-end flow (create schema → map → generate compliance → export → share)

## Task 13: Documentation and User Guides
**Spec refs**: specs/gemma-gegevenscatalogus/spec.md  
**Status**: todo

Create comprehensive documentation:
- [ ] Architecture documentation:
  - [ ] System design overview (how GEMMA data flows through opencatalogi)
  - [ ] Data model diagram (entity relationships)
  - [ ] API reference (all endpoints with examples)
  - [ ] Deployment guide (how to import GEMMA releases, configure validation modes)
- [ ] User guides:
  - [ ] Data architect guide: "How to Map Your Schema to GEMMA"
  - [ ] Informatiemanager guide: "Understanding Compliance Reports"
  - [ ] Admin guide: "Importing GEMMA Releases and Managing Versions"
  - [ ] Auditor guide: "Reviewing and Exporting Audit Trails"
- [ ] Video tutorials (optional):
  - [ ] 5-min: Browse GEMMA catalogus
  - [ ] 10-min: Create and map a schema to GEMMA
  - [ ] 5-min: Generate and export compliance report
- [ ] FAQ:
  - [ ] What does "volledig" vs "partieel" mapping mean?
  - [ ] How do I migrate my mapping to a new GEMMA version?
  - [ ] What's the difference between strict/warn/off validation?
  - [ ] Why is my compliance score lower than expected?
- [ ] Glossary:
  - [ ] GEMMA terms: objecttype, attribuut, relatie, domein, kerngegeven, etc.
  - [ ] System terms: mapping quality, compliance percentage, validation mode, etc.
- [ ] Link documentation from UI:
  - [ ] Help icons on mapping canvas ("What is cardinaliteit?")
  - [ ] Links to user guides from each major feature
- [ ] Tests:
  - [ ] Documentation links are valid (no 404s)
  - [ ] API examples are executable (syntax correct)

## Task 14: Testing and Quality Assurance
**Spec refs**: specs/gemma-gegevenscatalogus/spec.md  
**Status**: todo

Comprehensive testing across all features:
- [ ] Unit tests (target: 80%+ coverage on core logic):
  - [ ] Import parser (SKOS/RDF parsing)
  - [ ] Compliance calculator
  - [ ] Validation engine
  - [ ] Diff detector (version comparison)
  - [ ] Suggestion engine (fuzzy matching)
  - [ ] Audit trail creation
- [ ] Integration tests:
  - [ ] Import GEMMA → browse catalogus → search → view details
  - [ ] Create schema → auto-suggest GEMMA → map → validate → check compliance
  - [ ] Version switch → flag mappings → generate migration report
  - [ ] Modify mapping → create audit record → export audit trail
- [ ] Performance tests:
  - [ ] Import GEMMA 3.0: < 30 min
  - [ ] Browse page load: < 1 sec (p99)
  - [ ] Search 10K attributes: < 500 ms
  - [ ] Compliance report: < 2 sec (cached), < 15 sec (uncached)
  - [ ] JSON-LD export: < 5 sec
- [ ] End-to-end tests:
  - [ ] Full workflow: admin imports GEMMA → architect maps schema → manager reviews compliance → auditor exports report
  - [ ] Migration workflow: import new GEMMA version → detect changes → flag mappings → architect remaps → compliance updates
- [ ] Security tests:
  - [ ] Unauthorized user cannot modify mapping
  - [ ] Audit trail cannot be edited/deleted
  - [ ] PDF exports are signed and verifiable
  - [ ] API validation enforces GEMMA constraints correctly
- [ ] Accessibility tests (WCAG 2.1 AA):
  - [ ] Keyboard navigation through UI
  - [ ] Screen reader compatibility (ARIA labels)
  - [ ] Color contrast (badges, status indicators)
  - [ ] Responsive layout (mobile, tablet, desktop)
- [ ] Usability testing (with real data architects):
  - [ ] Can they map a schema in < 10 minutes?
  - [ ] Is the suggestion engine helpful?
  - [ ] Are error messages clear?
  - [ ] Gather feedback for UX improvements
- [ ] Browser compatibility:
  - [ ] Chrome, Firefox, Safari, Edge (latest versions)
  - [ ] Mobile browsers (iOS Safari, Chrome Android)
- [ ] CI/CD integration:
  - [ ] Run all tests on every PR
  - [ ] Code coverage gate (minimum 80%)
  - [ ] Performance benchmarks (alert if regressions > 10%)
  - [ ] Accessibility audit (axe-core or similar)
  - [ ] API lint (OpenAPI validation)

## Task 15: Deployment and Launch
**Spec refs**: specs/gemma-gegevenscatalogus/spec.md  
**Status**: todo

Prepare for production deployment:
- [ ] Database:
  - [ ] Create migrations (forward + rollback)
  - [ ] Run migrations in staging environment
  - [ ] Verify data integrity (no orphaned FKs, counts match)
  - [ ] Plan backup strategy for immutable audit table
- [ ] Environment configuration:
  - [ ] Document environment variables (API endpoints, cache TTLs, feature flags)
  - [ ] Create .env.example file
  - [ ] Document how to configure validation mode per Register
- [ ] Monitoring and alerting:
  - [ ] Monitor import job success/failure (alert on 3+ consecutive failures)
  - [ ] Monitor API response times (alert if > 5 sec)
  - [ ] Monitor cache hit rate (track caching effectiveness)
  - [ ] Monitor compliance report generation (alert if > 15 sec)
  - [ ] Create dashboard in monitoring tool (Prometheus, Datadog, etc.)
- [ ] Deployment checklist:
  - [ ] All tests passing (unit, integration, E2E)
  - [ ] Performance benchmarks met
  - [ ] Accessibility audit passed
  - [ ] Documentation reviewed and published
  - [ ] Security review completed (no OWASP top 10 issues)
  - [ ] Staging environment validated
  - [ ] Backup/rollback plan documented
- [ ] Phased rollout:
  - [ ] Phase 1: pilot with 1 municipality (test full workflow)
  - [ ] Phase 2: expand to 5 municipalities (verify scalability)
  - [ ] Phase 3: general availability
  - [ ] Monitor metrics at each phase
- [ ] Post-launch:
  - [ ] Gather user feedback (surveys, support tickets)
  - [ ] Monitor performance and errors (log aggregation, error tracking)
  - [ ] Plan first patch release (bug fixes discovered in production)
  - [ ] Schedule post-mortem 2 weeks after launch
- [ ] Communication:
  - [ ] Send announcement to target users (data architects, info managers)
  - [ ] Offer training sessions (webinars, in-person workshops)
  - [ ] Set up support channel (Slack, email, issue tracker)

---

## Success Criteria

All tasks must be completed for the spec to be marked "implemented":
1. All five schemas created and tested in production environment
2. GEMMA 3.0 (600+ objects) imports successfully in < 30 minutes
3. Browse UI is live and searchable (< 500ms for any query)
4. Data architects can map schemas in < 10 minutes (first mapping)
5. Compliance reports generated on-demand and cached
6. Audit trail is immutable and exportable
7. All 10 requirements (REQ-GEM-001 through REQ-GEM-010) scenarios pass
8. ≥ 80% code coverage on core modules
9. WCAG 2.1 AA accessibility compliant
10. Documentation published and user-tested
