---
status: draft
---

# Specification: GEMMA Gegevenscatalogus Integratie

## Purpose

This specification defines how opencatalogi integrates the GEMMA Gegevenscatalogus (Dutch standard 600+ object type reference model) as a managed, navigable, compliance-trackable data model component. It covers import pipelines, user interfaces, mapping workflows, version tracking, and audit trails.

## Context

The GEMMA Gegevenscatalogus is maintained by VNG Realisatie and is published in SKOS Turtle, RDF/XML, and UML formats. Currently, municipalities have no standardized way to:
- Import and query the catalogus in a consumable format
- Map their own data models to GEMMA object types
- Track compliance with GEMMA standards across their registers
- Migrate mappings when GEMMA releases change

This spec moves GEMMA from a static, external HTML website into opencatalogi as an integrated, versioned, queryable component with first-class UI and API support.

## Requirements

### REQ-GEM-001: Import GEMMA Release from SKOS/OWL/RDF

The system MUST import a GEMMA release from VNG-provided SKOS Turtle or OWL/RDF files, creating GemmaCatalogus, GemmaObjecttype, GemmaAttribuut, and GemmaRelatie records.

#### Scenario: Admin uploads GEMMA 3.0 release file
- **GIVEN** an admin is in opencatalogi → Settings → GEMMA
- **WHEN** they upload `gemma-3.0.ttl` (Turtle RDF format)
- **THEN** the system MUST start an async import job
- **AND** the job MUST parse all RDF triples into the five schemas
- **AND** within 30 minutes, all 612 object types + 9,847 attributes + 3,421 relationships from GEMMA 3.0 MUST be stored in the database
- **AND** a GemmaCatalogus record MUST be created with `versie="3.0"`, `status="vastgesteld"`, `importedAt=<now>`, `importedBy=<admin-user-id>`
- **AND** the UI MUST show "Import in progress" with progress percentage

#### Scenario: Import job handles external ontology references
- **GIVEN** the RDF file references external ontologies (e.g., SKOS, PROV-O, DCAT)
- **WHEN** the parser encounters `@prefix skos: <http://www.w3.org/2004/02/skos/core#>`
- **THEN** the system MUST resolve external ontologies via HTTP GET or use a local cached copy
- **AND** if the HTTP request fails, the system MUST retry up to 3 times with exponential backoff
- **AND** if all retries fail, the system MUST log the error and continue parsing other triples
- **AND** the import job MUST NOT fail entirely due to network issues

#### Scenario: Concurrent import attempts are rejected
- **GIVEN** a GEMMA import job is already running
- **WHEN** an admin attempts to start a second import
- **THEN** the system MUST reject the second request with HTTP 409 Conflict
- **AND** the error message MUST be "Er loopt al een GEMMA-import. Wacht tot deze klaar is (ETA: X minuten)."

#### Scenario: Imported data includes all GEMMA metadata
- **GIVEN** a GemmaObjecttype is successfully parsed
- **WHEN** the record is stored
- **THEN** MUST include: `naam`, `urn`, `definitie`, `domein`, `subtype`, `parent` (if hierarchical), `abstract`, `herkomst`, `geldigVan`, `geldigTot`, `vervangenDoor`
- **AND** GemmaAttributen on that objecttype MUST include: `naam`, `datatype`, `cardinaliteit`, `autoriteit`, `kerngegeven`, `gevoeligheid`, `voorbeelden`
- **AND** GemmaRelaties MUST include: `vanObjecttype`, `naarObjecttype`, `naam`, `cardinaliteit`, `aggregatieType`

#### Scenario: Import overwrites previous release of same version (idempotent)
- **GIVEN** GEMMA 3.0 was previously imported
- **WHEN** an admin re-imports GEMMA 3.0 from a refreshed download
- **THEN** the system MUST detect that `versie="3.0"` already exists
- **AND** MUST allow the re-import
- **AND** MUST update all existing GemmaObjecttype/Attribuut/Relatie records with new data
- **AND** MUST preserve all GemmaMapping links pointing to objects being updated
- **AND** MUST set all affected GemmaMappings to `validatieStatus="gewaarschuwd"` with note "GEMMA version re-imported"

---

### REQ-GEM-002: Browse and Search GEMMA Catalogus

Users MUST navigate, filter, and search the GEMMA catalogus via an integrated web UI with facets and full-text search.

#### Scenario: User opens GEMMA browse UI
- **GIVEN** a logged-in user navigates to `/gemma-catalogus` in opencatalogi admin
- **WHEN** the page loads
- **THEN** the system MUST display the active GemmaCatalogus (latest by `versie`)
- **AND** a left sidebar MUST show facets: `domein` (BAG, BRP, BRK, etc.), `status` (concept, vastgesteld, vervallen), `subtype` (object, gegevenselement, etc.), `kerngegeven` (ja/nee)
- **AND** the main area MUST show a paginated list of object types (default: 25 per page, sorted A–Z)
- **AND** each list item MUST show: icon, name, domain tag, count of attributes

#### Scenario: User filters by domain
- **GIVEN** the browse page is open
- **WHEN** the user clicks the facet `domein: BRP`
- **THEN** the list MUST filter to show only object types where `domein="BRP"`
- **AND** the facet MUST show a count badge (e.g., "BRP (142)")
- **AND** the URL MUST update to `?domain=BRP`

#### Scenario: User searches by name
- **GIVEN** the browse page is open
- **WHEN** the user types "verblijf" in the search box and presses Enter
- **THEN** the system MUST search across: GemmaObjecttype.naam, GemmaObjecttype.synoniemen, GemmaObjecttype.definitie, all GemmaAttribuut.naam under those objects
- **AND** MUST return results sorted by relevance (exact name matches first, then definition matches)
- **AND** MUST highlight matching text in each result
- **AND** display "8 results for 'verblijf'" at the top

#### Scenario: User views object type detail page
- **GIVEN** the user clicks on "Verblijfsobject" in the browse list
- **WHEN** the detail page loads at `/gemma-catalogus/objecttype/uuid-objecttype-verblijfsobject`
- **THEN** MUST show: full definition, toelichting (if any), herkomst (legal source), geldigVan, geldigTot
- **AND** MUST show a table of all GemmaAttributen (naam, datatype, cardinaliteit, autoriteit, kerngegeven flag)
- **AND** MUST show a "Relaties" section with: incoming relations (other objects pointing to this), outgoing relations (this object pointing to others)
- **AND** MUST show links: "Open op GEMMA-online", "GitHub discussie", synoniemen
- **AND** if `vervangenDoor` is set, MUST show a deprecation banner: "⚠️ Vervallen — gebruik [Verblijfsobject (new version)]" with direct link

#### Scenario: User filters by multiple facets simultaneously
- **GIVEN** the user has filtered `domein=BRP`
- **WHEN** they also click `kerngegeven: ja`
- **THEN** the list MUST show only objects where `domein="BRP" AND any attribute has kerngegeven=true`
- **AND** URL MUST reflect both: `?domain=BRP&coreDataOnly=true`
- **AND** facet counts MUST update to reflect combined filter state

---

### REQ-GEM-003: Map Local Schema to GEMMA Object Type

Data architects MUST map openregister Schemas to GEMMA object types with per-attribute transformation rules and automatic validation.

#### Scenario: Architect initiates mapping from schema detail page
- **GIVEN** a data architect is viewing an openregister Schema "MijnPersoon" at `/openregister/schemas/mijnpersoon`
- **WHEN** they click "Koppel aan GEMMA-standaard"
- **THEN** a modal MUST open with search field "Zoeken naar GEMMA-objecttype"
- **AND** the system MUST suggest matching GEMMA object types based on schema name + existing properties
- **AND** suggestions MUST show match score (0–100%)
- **AND** for "MijnPersoon", suggestions MUST include: `Persoon` (98%), `Ingeschrevennatuurlijkpersoon` (85%), `Belanghebbende` (45%)

#### Scenario: Architect selects GEMMA object type and maps attributes
- **GIVEN** the architect selects `Persoon` from suggestions
- **WHEN** they click "Next"
- **THEN** a drag-and-drop canvas MUST open showing:
  - Left side: local schema properties (e.g., birthDate, givenName, surname, gender)
  - Right side: GEMMA object type attributes (geboortedatum, voornamen, achternaam, geslacht, etc.)
  - Pre-filled mappings for obviously matching pairs (datatype, name similarity)
- **AND** the architect MUST be able to drag unmapped local properties onto GEMMA attributes
- **AND** for each mapping, a row MUST show: local property → transformation rule → GEMMA attribute
- **AND** when mapping `birthDate (string) → geboortedatum (datum)`, the system MUST suggest transformation: "Parse ISO 8601 string to date"

#### Scenario: System validates mapping quality
- **GIVEN** the architect has mapped 4 out of 8 verplichte attributes
- **WHEN** they click "Validate"
- **THEN** the system MUST calculate: 4/8 required mapped = 50% coverage
- **AND** MUST set `mappingKwaliteit="partieel"`
- **AND** MUST show a validation report: "4 of 8 required attributes mapped. Missing: achternaam, geslacht, nationaliteit, geboorteland. Auto-suggest? [Yes]"
- **AND** MUST NOT prevent save; allow partieel mapping with warning

#### Scenario: Complete mapping achieves "volledig" status
- **GIVEN** all 8 verplichte attributes on `Persoon` are mapped
- **WHEN** validation runs
- **THEN** MUST set `mappingKwaliteit="volledig"`
- **AND** the local Schema MUST receive badge/indicator "GEMMA-conform" in the UI
- **AND** openregister Schema.gemmaMappingKwaliteit MUST be updated to "volledig"

#### Scenario: Architect adds transformation rules
- **GIVEN** the mapping canvas is open
- **WHEN** the architect clicks the transformation cell for a mapping
- **THEN** a text editor MUST open showing the default transformation (e.g., "direct" or "Parse ISO 8601 string")
- **AND** the architect MUST be able to write custom logic (e.g., "If null, use '1970-01-01'")
- **AND** the system MUST validate transformation syntax (if applicable) and warn on issues

#### Scenario: Mapping is saved with audit record
- **GIVEN** the architect has configured all mappings and clicked "Save"
- **WHEN** the save completes
- **THEN** a GemmaMapping record MUST be created with `status="active"`
- **AND** an audit record MUST be created: `who=<architect-id>`, `when=<now>`, `what="GemmaMapping created"`, `why=<empty; optional comment field>`
- **AND** the schema list MUST show the updated mapping status immediately
- **AND** the architect MUST see confirmation: "✅ Mapping to Persoon saved (volledig)"

---

### REQ-GEM-004: Version Tracking and Migration Between GEMMA Releases

When a new GEMMA release is imported, the system MUST detect changes and flag affected mappings.

#### Scenario: GEMMA 3.0 is imported after 2.6 is active
- **GIVEN** GemmaCatalogus v2.6 is set as active (`status="vastgesteld"`)
- **WHEN** GEMMA 3.0 is imported
- **THEN** the system MUST compare: object types, attributes, relationships between 2.6 and 3.0
- **AND** MUST create a diff report with sections:
  - `objecttypenToegevoegd` (25 new, e.g., [Energieverbruik, OpenbaarVervoerKaart, ...])
  - `objecttypenVerwijderd` (3 deprecated, e.g., [OudZaaktype, ...])
  - `objecttypenGewijzigd` (80 changes, e.g., Zaak: attr owner → responsible, new attr ownerOrganization)
  - Per gewijzigd object: `attribuutMatching` showing fuzzy/URN-based rename detection

#### Scenario: System suggests automatic remapping for renamed attributes
- **GIVEN** GEMMA 3.0 diff shows `Zaak.eigenaar` (2.6) renamed to `Zaak.verantwoordelijke` (3.0)
- **WHEN** diff detection runs
- **THEN** the system MUST match by URN history: `urn:vng:gemma:attribute:zaak.eigenaar` → `urn:vng:gemma:attribute:zaak.verantwoordelijke`
- **AND** MUST create a suggestion: "Zaak.eigenaar → Zaak.verantwoordelijke (high confidence, URN match)"
- **AND** MUST allow architect to auto-accept the remapping via one click

#### Scenario: Affected mappings are flagged
- **GIVEN** a GemmaMapping exists: `MijnZaak` → `Zaak` (v2.6)
- **WHEN** GEMMA 3.0 is imported and `Zaak` has significant changes
- **THEN** the GemmaMapping MUST be set to `validatieStatus="gewaarschuwd"`
- **AND** a notification MUST be sent to the architect who created/owns the mapping
- **AND** the schema listing MUST show a ⚠️ icon on "MijnZaak"
- **AND** the notification text MUST explain: "The target GEMMA object type `Zaak` has changed in GEMMA 3.0. Your mapping may need review. Changes: [list of attr modifications]."

#### Scenario: Mapping conflicts due to deprecated object type
- **GIVEN** GemmaMappings exist pointing to object types that are deprecated in 3.0
- **WHEN** the new release is imported and the old objects have `geldigTot < now` and `vervangenDoor=<new-object>`
- **THEN** those mappings MUST be set to `validatieStatus="conflict"`
- **AND** the system MUST suggest: "This object type is deprecated. Suggested replacement: [new object type]. Auto-migrate? [Yes/No]"

#### Scenario: Release switch triggers automatic compliance re-check
- **GIVEN** GEMMA 3.0 has been imported and the active catalogus is switched from 2.6 to 3.0
- **WHEN** the switch occurs
- **THEN** all Compliance Reports MUST be invalidated
- **AND** all cached Compliance exports MUST be cleared
- **AND** a background job MUST re-compute all Register compliance percentages against 3.0
- **AND** a notification MUST be sent to CIOs: "GEMMA catalogus switched to v3.0. Compliance reports updated. 3 schemas now have conflicts — review needed."

---

### REQ-GEM-005: Compliance Reporting Per Register

The system MUST generate real-time compliance reports showing GEMMA conformity percentage per Register.

#### Scenario: Informatiemanager generates compliance report
- **GIVEN** a Register "Burgers" has 16 Schemas, of which: 12 have volledig GEMMA mappings, 2 have partieel, 1 has none, 1 is not mapped
- **WHEN** the informatiemanager opens opencatalogi → Compliance Reports → select "Burgers"
- **THEN** the system MUST compute:
  - Total schemas: 16
  - GEMMA-compliant (volledig): 12 (75%)
  - Partially compliant (partieel): 2 (12.5%)
  - Non-compliant (geen/unmapped): 2 (12.5%)
  - Compliance percentage: 75%
- **AND** the report MUST display: bar chart, per-schema breakdown table, missing-schemas list

#### Scenario: Report shows per-schema details
- **GIVEN** the compliance report is open
- **WHEN** the user clicks "Details" on a schema with partieel mapping
- **THEN** the system MUST show:
  - Mapped attributes (list with ✓ checkmarks)
  - Unmapped required attributes (list with ✗, sorted by importance)
  - Suggested next steps: "Map [attr1], [attr2] to achieve volledig status"
  - Last validation date and who validated it

#### Scenario: Report is exported as PDF
- **GIVEN** the compliance report is complete
- **WHEN** the user clicks "Export as PDF"
- **THEN** the system MUST call docudesk API to render the report with:
  - Official letterhead (gemeente logo)
  - Title: "GEMMA Compliance Report: Burgers Register — GEMMA 3.0"
  - Date, Register name, compliance percentage, table, recommendations
  - Timestamp and signature field (for archiving)
- **AND** MUST return a downloadable PDF file

#### Scenario: Report is exported as CSV
- **GIVEN** the compliance report is complete
- **WHEN** the user clicks "Export as CSV"
- **THEN** the system MUST generate a CSV with columns: Schema Name, GEMMA Object Type, Mapping Quality, Mapped Attrs, Total Attrs, Compliance %
- **AND** MUST be suitable for import into Excel/Sheets for further analysis

#### Scenario: Compliance report is cached for performance
- **GIVEN** a compliance report is generated for Register "Burgers"
- **WHEN** the report is computed
- **THEN** the system MUST cache the result in Redis with TTL 1 hour
- **AND** subsequent requests within 1 hour MUST return the cached result (< 200ms response time)
- **AND** when a GemmaMapping on a schema in "Burgers" is updated, the cache MUST be invalidated

#### Scenario: Monthly compliance report is generated via cron
- **GIVEN** a cron job is configured to run monthly on the first of each month at 00:30 UTC
- **WHEN** the job runs
- **THEN** for each Register, the system MUST generate a compliance report
- **AND** if the compliance percentage has changed by ≥ 5% since the previous month, send notification to CIO: "[Register] compliance changed from X% to Y%"
- **AND** store each report in an audit table for historical tracking

---

### REQ-GEM-006: API Validation Against GEMMA Mapping

When an openregister object is created or updated and the schema has an active GEMMA mapping, optionally validate at GEMMA level.

#### Scenario: Validation mode is strict and required GEMMA attribute is missing
- **GIVEN** Schema `MijnPersoon` has `gemmaObjecttype=Persoon` and `gemmaMappingKwaliteit=volledig`
- **AND** Register settings `gemmaValidationMode=strict`
- **WHEN** a POST /objects request creates a new object with missing `bsn` (required by GEMMA)
- **THEN** the system MUST reject the create with HTTP 422 Unprocessable Entity
- **AND** the response body MUST be:
  ```json
  {
    "errors": [
      {
        "property": "bsn",
        "message": "GEMMA-validatie: verplicht attribuut 'inp.bsn' (Persoon objecttype) ontbreekt",
        "code": "GEMMA_REQUIRED_FIELD"
      }
    ]
  }
  ```

#### Scenario: Validation mode is warn for missing required attribute
- **GIVEN** same setup as above (strict GEMMA mapping, missing required attr)
- **WHEN** Register settings `gemmaValidationMode=warn`
- **THEN** the POST /objects request MUST succeed (HTTP 201 Created)
- **AND** the response MUST include a warning header: `X-GEMMA-Validation-Warnings: inp.bsn is required by GEMMA Persoon objecttype`
- **AND** the created object MUST be tagged `gemmaValidationWarnings=[...]` in the database for later audit

#### Scenario: Validation mode is off
- **GIVEN** Register settings `gemmaValidationMode=off`
- **WHEN** a POST /objects request is made with missing GEMMA-required attributes
- **THEN** the system MUST NOT perform any GEMMA validation
- **AND** the object MUST be created without warnings (HTTP 201)

#### Scenario: Validation checks datatype conformance
- **GIVEN** Schema property is mapped to GEMMA attribute with datatype=datum
- **WHEN** a POST /objects includes the property with value "not-a-date"
- **AND** Register `gemmaValidationMode=strict`
- **THEN** the system MUST reject with HTTP 422
- **AND** error: "GEMMA-validatie: property 'birthDate' must be a date (YYYY-MM-DD), got 'not-a-date'"

#### Scenario: Validation respects cardinalität
- **GIVEN** GEMMA attribute `geboortedatum` has `cardinaliteit=0..1` (optional, single value)
- **WHEN** a POST /objects includes `geboortedatum` as an array [date1, date2]
- **AND** Register `gemmaValidationMode=strict`
- **THEN** the system MUST reject with HTTP 422
- **AND** error: "GEMMA-validatie: 'geboortedatum' (Persoon) expects single value, got array"

---

### REQ-GEM-007: Bidirectional Linking with GEMMA-online

Users MUST be able to navigate between opencatalogi and gemmaonline.nl, and receive deprecation notices.

#### Scenario: User navigates to GEMMA-online from object detail
- **GIVEN** a user is viewing GemmaObjecttype detail page for `Verblijfsobject`
- **WHEN** they click the button "Open op GEMMA-online"
- **THEN** the system MUST construct the URL: `https://gemmaonline.nl/index.php/Objecttype:Verblijfsobject`
- **AND** open it in a new browser tab

#### Scenario: User accesses GitHub discussion for object type
- **GIVEN** GemmaObjecttype detail page for `Persoon`
- **WHEN** user clicks "Bekijk discussies op GitHub"
- **THEN** the system MUST open: `https://github.com/VNG-Realisatie/Gemeentelijk-Gegevensmodel/issues?q=label:Persoon`
- **AND** display recent GitHub issues labeled with the object type name

#### Scenario: Deprecated object type shows migration banner
- **GIVEN** GemmaObjecttype has `geldigTot < today()` and `vervangenDoor=<new-objecttype-id>`
- **WHEN** the detail page loads
- **THEN** a banner MUST be displayed at the top:
  ```
  ⚠️ Vervallen — gebruik Verblijfsobject-v2
  Dit objecttype is vervallen sinds [date]. Gebruik in plaats daarvan: [link]
  ```
- **AND** the banner MUST not obscure the content but be dismissible

---

### REQ-GEM-008: Domain Export as JSON-LD

Users MUST export domain-specific subsets of the catalogus as JSON-LD for external use.

#### Scenario: User exports BAG domain as JSON-LD
- **GIVEN** user is viewing browse UI with `domein=BAG` filter applied
- **WHEN** they click "Exporteer als JSON-LD"
- **THEN** the system MUST generate a JSON-LD document containing:
  - All GemmaObjecttypen where `domein="BAG"`
  - All GemmaAttributen of those object types
  - All GemmaRelaties where both endpoints are in BAG
  - Standard JSON-LD context conforming to JSON-LD 1.1 spec
  - PROV-O metadata indicating source GEMMA version, export date, curator
- **AND** MUST return a downloadable file: `gemma-bag-3.0.jsonld`

#### Scenario: JSON-LD export is cached
- **GIVEN** a JSON-LD export for BAG domain on GEMMA 3.0 is generated
- **WHEN** the export is computed
- **THEN** the system MUST cache it in Redis with TTL 7 days
- **AND** subsequent requests within 7 days MUST return the cached file (< 200ms latency)
- **AND** if GemmaCatalogus is switched to a new version, all cached exports MUST be cleared

#### Scenario: JSON-LD export includes transformation instructions
- **GIVEN** a domain export is requested
- **WHEN** the JSON-LD is generated
- **THEN** for each GemmaObjecttype with active GemmaMappings, MUST include an optional `@mapping` section:
  ```json
  {
    "@id": "urn:vng:gemma:objecttype:verblijfsobject",
    "@type": "gemma:Objecttype",
    "name": "Verblijfsobject",
    "@mapping": {
      "localSchema": "MijnVerblijf",
      "mappingQuality": "volledig",
      "attributeMappings": [...]
    }
  }
  ```

---

### REQ-GEM-009: Attribute Suggestions on Schema Creation

When a user creates a new schema in openregister, the system MUST suggest GEMMA object types and attributes.

#### Scenario: User creates schema and receives GEMMA suggestions
- **GIVEN** a user is creating a new schema in openregister at POST /schemas
- **WHEN** they submit `name="Inwoner"` with initial properties [name, email, birthDate]
- **THEN** the system MUST:
  - Query GEMMA object types for matches by name: "Inwoner" matches `Persoon` (95%), `Ingeschrevennatuurlijkpersoon` (80%), `Belanghebbende` (50%)
  - Suggest top 3 matches in a modal: "Did you mean? Persoon [95%]"
  - Allow user to select `Persoon`
- **AND** if selected, MUST show attribute suggestions:
  - User entered "email" → suggest `emailAdres` (GEMMA attribute on Persoon)
  - User entered "birthDate" → suggest `geboortedatum`
  - User entered "name" → suggest `voornamen` + `achternaam`
- **AND** for each suggestion, show: GEMMA attribute name, datatype, cardinaliteit, example values

#### Scenario: User accepts attribute suggestion
- **GIVEN** suggestions are shown for "birthDate" → `geboortedatum`
- **WHEN** user clicks "Accept suggestion"
- **THEN** the schema property MUST be created with:
  - `name: "birthDate"` (user's chosen name)
  - `title: "Geboortedatum"` (from GEMMA)
  - `datatype: "date"` (from GEMMA.datatype=datum)
  - `cardinality: "0..1"` (from GEMMA.cardinaliteit)
  - A reference marking this as a GEMMA mapping candidate
- **AND** when the schema is saved, a GemmaMapping MUST be pre-created with `mappingKwaliteit=volledig` (if all properties mapped)

#### Scenario: Suggestions are case-insensitive and fuzzy-matched
- **GIVEN** user enters property "geboerte" (partial match)
- **WHEN** attribute suggestions are generated
- **THEN** MUST match `geboortedatum`, `geboorteplaats`, `geboorteland` with confidence scores
- **AND** show top matches ranked by relevance

---

### REQ-GEM-010: Audit Trail on Mappings

Every GemmaMapping mutation MUST be logged for compliance audits.

#### Scenario: Architect modifies a mapping attribute
- **GIVEN** a GemmaMapping exists for `MijnPersoon` → `Persoon`
- **WHEN** the architect changes the transformation rule for `birthDate`: "direct" → "Parse ISO 8601 string"
- **THEN** the system MUST:
  - Save the new transformation
  - Create an audit record:
    ```json
    {
      "timestamp": "2025-02-15T14:30:00Z",
      "who": "architect-user-id",
      "action": "UPDATE_MAPPING_ATTRIBUTE",
      "gemmaMapping": "uuid-mapping-mijnpersoon",
      "details": {
        "attribute": "geboortedatum",
        "oldTransformation": "direct",
        "newTransformation": "Parse ISO 8601 string"
      },
      "why": "" // optional comment from user
    }
    ```
  - Store in audit table (never deleted, only appended)

#### Scenario: Mapping is logically deleted (soft-delete)
- **GIVEN** a GemmaMapping exists
- **WHEN** an architect or admin deletes it (via UI "Remove mapping")
- **THEN** the system MUST NOT hard-delete the record
- **AND** MUST set `status="vervallen"` and `updatedAt=<now>`
- **AND** create an audit record: `{action: "DELETE_MAPPING", who: ..., when: ..., why: ...}`
- **AND** the mapping MUST remain queryable in the audit history

#### Scenario: Auditor exports mapping history as PDF
- **GIVEN** an auditor opens opencatalogi → Audit → Mappings
- **WHEN** they filter by Schema "MijnPersoon" and click "Export as PDF"
- **THEN** the system MUST:
  - Retrieve full audit trail for all GemmaMappings linked to `MijnPersoon`
  - Render as PDF with columns: Timestamp, Who, Action, Before, After, Why
  - Include cryptographic signature (hash or cert) indicating authentic export
  - Make suitable for board/audit submission
- **AND** return downloadable file: `mapping-audit-mijnpersoon-2025-02-15.pdf`

#### Scenario: Mapping validation is recorded in audit
- **GIVEN** a GemmaMapping is validated
- **WHEN** validation completes
- **THEN** MUST create an audit record:
  ```json
  {
    "action": "VALIDATE_MAPPING",
    "mapping": "uuid-mapping-mijnpersoon",
    "result": {
      "quality": "partieel",
      "mappedAttrs": 4,
      "totalRequired": 8,
      "details": "Missing: achternaam, geslacht, nationaliteit, geboorteland"
    },
    "timestamp": "2025-02-15T14:35:00Z",
    "who": "system" // or architect if manual validation
  }
  ```

---

## Non-Functional Requirements

### Performance
- GEMMA 3.0 (612 object types, 9,847 attributes, 3,421 relations) MUST import in under 30 minutes
- Browse page (list + facets) MUST render in under 1 second (p99)
- Search across 600 object types and 10K attributes MUST return results in under 500ms
- Compliance report generation MUST complete in under 2 seconds (cached; uncached < 15 sec)
- JSON-LD export MUST generate in under 5 seconds

### Availability
- Browse UI MUST have 99.5% uptime
- Import job failures MUST retry with exponential backoff and log failures for manual review
- Network errors during import MUST be handled gracefully (retry external ontology fetches)

### Security
- GemmaMapping modifications MUST only be authorized to schema owner or admin
- Audit trail MUST be immutable (append-only, no edit/delete capability)
- PDF exports of audit trails MUST be signed with a certificate or HMAC signature
- API validation (REQ-GEM-006) MUST not expose sensitive GEMMA metadata to unauthenticated users

### Maintainability
- All import logic MUST be in a separate service/module (not inline)
- SKOS/RDF parsing library MUST be a well-maintained external dependency (rdf4j, ARC2, EasyRdf)
- Code MUST include tests for: import job (happy path + edge cases), validation logic, compliance calculation

---

## Related Specifications

- **openregister API & Schema Management** — Where local Schemas are created and managed
- **opencatalogi Publication & Catalogus Management** — Base layer for this spec
- **softwarecatalog Product Mappings** — Consumes GemmaMapping for conformity claims
- **GEMMA 3.0 Specification** — Published by VNG Realisatie at gemmaonline.nl
- **JSON-LD 1.1** (W3C Recommendation 2020) — Format for domain exports
- **SKOS & OWL 2** (W3C) — Vocabularies used in GEMMA SKOS Turtle source
