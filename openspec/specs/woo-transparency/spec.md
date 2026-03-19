# woo-transparency Specification

## Purpose
WOO (Wet open overheid) / FOIA compliance features in OpenCatalogi: publication decision tracking, document redaction workflow, publication to a public reading room, and redaction audit trail. Builds on OpenCatalogi's existing Publication, Catalog, and Listing entities to add WOO-specific workflow and publication capabilities. Document-level operations (PDF generation, anonymization, entity detection) are delegated to Docudesk.

## Context
OpenCatalogi already serves as the "regieomgeving" (orchestration environment) for WOO publications: it manages catalogs, publications, listings, and public-facing catalog websites. This spec adds the WOO-specific workflow layer on top of those existing entities: managing a queue of documents that need assessment and redaction, tracking which redaction grounds (weigeringsgronden) apply, and publishing the final set of redacted documents through the existing publication infrastructure.

The document processing pipeline (entity detection, anonymization, PDF conversion) remains in Docudesk via the `anonymization` spec. OpenCatalogi orchestrates the WOO workflow and calls Docudesk for document-level operations. The case management side lives in Procest's `woo-case-type` spec.

**Relation to existing specs:**
- Docudesk `anonymization` spec: provides the entity detection and redaction engine (ANON-001 through ANON-056). OpenCatalogi delegates document processing to Docudesk but manages the WOO assessment and publication workflow.
- Procest `woo-case-type` spec: manages the WOO case lifecycle. This spec handles the publication workflow that Procest delegates to OpenCatalogi.

**Relation to existing OpenCatalogi entities:**
- **Publication**: WOO document packages are published as Publication objects with WOO-specific metadata (besluit, inventarislijst, weigeringsgronden).
- **Catalog**: WOO reading rooms are implemented as dedicated Catalogs, enabling public access through the existing catalog website infrastructure.
- **Listing**: Individual WOO documents (openbaar and redacted deels-openbaar) become Listings within the WOO publication Catalog.
- **Organization**: Links WOO publications to the responsible bestuursorgaan.

## ADDED Requirements

### Requirement: WOO document queue
The system MUST provide a document processing queue for WOO requests.

#### Scenario: Receive documents from Procest
- GIVEN a WOO case in Procest with 20 collected documents
- WHEN Procest sends the documents to OpenCatalogi for WOO processing
- THEN a WOO processing batch MUST be created in OpenCatalogi
- AND all 20 documents MUST appear in the processing queue
- AND each document MUST have initial status "Te beoordelen"

#### Scenario: Document assessment statuses
- GIVEN a document in the WOO queue
- THEN the following assessment statuses MUST be supported:
  - **Te beoordelen** -- not yet assessed
  - **Openbaar** -- fully disclosable, no redaction needed
  - **Deels openbaar** -- needs redaction before disclosure
  - **Niet openbaar** -- withheld entirely
- AND transitioning to "Niet openbaar" MUST require selecting weigeringsgrond(en)

#### Scenario: Bulk assessment
- GIVEN 20 documents in the queue
- WHEN the user selects 5 documents and sets status "Openbaar"
- THEN all 5 MUST be updated in one action
- AND the remaining 15 MUST retain their current status

### Requirement: Weigeringsgronden (refusal grounds)
The system MUST support tagging documents with legal grounds for withholding.

#### Scenario: Tag document with refusal ground
- GIVEN a document assessed as "Niet openbaar"
- WHEN the user selects weigeringsgronden
- THEN they MUST choose from WOO Article 5.1 and 5.2 grounds:
  - 5.1.1: Eenheid van de Kroon
  - 5.1.2: Veiligheid van de Staat
  - 5.1.2.e: Eerbiediging persoonlijke levenssfeer
  - 5.1.2.f: Belang van opsporing en vervolging
  - 5.2.e: Persoonlijke beleidsopvattingen
  - (complete list per WOO)
- AND multiple grounds MAY be selected per document
- AND each ground MUST be stored with the document assessment

#### Scenario: Partial redaction with grounds
- GIVEN a document assessed as "Deels openbaar"
- WHEN the user marks specific entities for redaction
- THEN each redacted entity or section MUST be linkable to a weigeringsgrond
- AND the redaction mapping (entity -> ground) MUST be stored for the besluit

### Requirement: Redaction with WOO context
Document redaction MUST be coordinated through Docudesk's anonymization pipeline with WOO-specific context.

#### Scenario: Selective entity redaction
- GIVEN a document with 15 detected entities (detected by Docudesk)
- WHEN the user reviews the entities in the WOO redaction view
- THEN they MUST be able to select which entities to redact (not all-or-nothing)
- AND they MUST be able to add manual redaction regions (mark areas not detected by AI)
- AND each redaction MUST be linkable to a weigeringsgrond
- AND the redaction instructions MUST be sent to Docudesk for execution

#### Scenario: Redaction preview
- GIVEN a document with selected redactions
- WHEN the user clicks "Voorbeeld"
- THEN a preview MUST show the document with redacted areas blacked out
- AND the user MUST be able to approve or adjust before finalizing

#### Scenario: Redaction produces clean document
- GIVEN a finalized redaction
- WHEN Docudesk generates the anonymized document
- THEN redacted text MUST be irrecoverably removed (not just visually hidden)
- AND redacted areas MUST show black bars (standard WOO convention)
- AND the original document MUST be preserved unchanged

### Requirement: Inventarislijst generation
The system MUST generate a document inventory (inventarislijst) for the WOO decision.

#### Scenario: Generate inventarislijst
- GIVEN a WOO batch with 20 assessed documents
- WHEN the user requests the inventarislijst
- THEN a document MUST be generated listing all documents with:
  - Volgnummer (sequential number)
  - Document omschrijving (title/description)
  - Datum document (document date)
  - Beoordeling (openbaar/deels openbaar/niet openbaar)
  - Weigeringsgrond(en) (if applicable)
- AND the inventarislijst MUST be exportable as PDF and CSV

### Requirement: Reading room publication
The system MUST support publishing WOO documents to a public reading room using OpenCatalogi's existing Catalog and Publication infrastructure.

#### Scenario: Publish WOO package
- GIVEN a completed WOO batch with:
  - 10 documents marked "Openbaar"
  - 5 documents redacted (deels openbaar)
  - 5 documents withheld (niet openbaar)
  - Generated inventarislijst
  - Besluit document (from Procest)
- WHEN the user triggers publication
- THEN a Publication MUST be created in the designated WOO Catalog containing:
  - The besluit document
  - The inventarislijst
  - The 10 openbare documents (as Listings)
  - The 5 redacted (anonymized) versions of deels openbare documents (as Listings)
- AND the niet openbare documents MUST NOT be included
- AND the reading room MUST be accessible without authentication via the existing catalog website

#### Scenario: Reading room URL
- GIVEN a published WOO package
- THEN the reading room MUST have a permanent public URL through the catalog website
- AND the URL MUST be shareable with the verzoeker and the public

## Non-Requirements
- This spec does NOT cover the WOO case lifecycle (managed by Procest woo-case-type spec)
- This spec does NOT cover WOO decision registration (managed by Procest besluiten-management spec)
- This spec does NOT cover proactive WOO publication (actieve openbaarmaking) -- future spec
- This spec does NOT cover document-level PDF generation, anonymization, or entity detection (managed by Docudesk anonymization spec)

## Dependencies
- Docudesk anonymization pipeline (entity detection, redaction engine) -- called via API for document processing
- Procest woo-case-type spec (case management, document collection)
- OpenRegister for batch and assessment data storage
- OpenCatalogi Publication, Catalog, Listing, and Organization entities (existing infrastructure)

### Current Implementation Status
- **Not yet implemented**: This is an entirely planned spec. Zero WOO-specific implementation exists in the codebase.
  - No WOO-specific classes, controllers, or services exist in OpenCatalogi's `lib/`
  - No WOO-related Vue components exist in `src/`
  - No references to "woo", "transparency", "inventarislijst", "weigeringsgrond", or "reading room" exist in any PHP, Vue, or JS files
  - No WOO-specific routes exist in `appinfo/routes.php`
- **Building blocks that exist in OpenCatalogi**:
  - `lib/Service/PublicationService.php` -- publication creation and management (foundation for WOO publication workflow)
  - `lib/Service/CatalogiService.php` -- catalog management (WOO reading rooms will be catalogs)
  - `lib/Service/FileService.php` -- file handling for publication attachments
  - `lib/Service/SearchService.php` -- search across publications and listings (enables reading room search)
  - `lib/Service/SitemapService.php` -- sitemap generation for public catalog pages
  - `lib/Service/DirectoryService.php` -- directory/listing management
  - Existing public-facing catalog website infrastructure (reading room foundation)
  - Publication, Catalog, Listing, Organization entities in OpenRegister
- **Building blocks in Docudesk (called via API)**:
  - `AnonymizationService.php` -- entity detection and document anonymization pipeline
  - `AnonymizationController.php` -- upload, extract, anonymize endpoints
  - Anonymization UI components -- drag-and-drop upload and processing
- **Key gaps**:
  - No WOO document processing queue or batch management
  - No weigeringsgronden (refusal grounds) data model or selection UI
  - No selective entity redaction coordination (current Docudesk anonymization is all-or-nothing per entity list)
  - No manual redaction region support
  - No redaction preview functionality
  - No inventarislijst generation
  - No WOO-specific reading room catalog type
  - No integration with Procest woo-case-type
  - No integration with Docudesk anonymization API from OpenCatalogi

### Standards & References
- **WOO (Wet open overheid)**: Primary law governing government transparency in the Netherlands (effective May 1, 2022, replacing WOB)
  - Article 4.1: Active disclosure obligation
  - Article 4.4: Information requester notification and objection period
  - Article 5.1: Absolute grounds for withholding (staatsgeheim, persoonlijke levenssfeer, etc.)
  - Article 5.2: Relative grounds for withholding (persoonlijke beleidsopvattingen, etc.)
- **GDPR/AVG**: Redaction of personal data before publication
- **Archiefwet 1995**: Document retention and archival requirements for WOO publications
- **PLOOI (Platform Open Overheidsinformatie)**: National platform for government transparency publications -- future integration target
- **KOOP (Kennis- en Exploitatiecentrum Officiele Overheidspublicaties)**: Standards for government publication metadata
- **DIWOO (Digitale Infrastructuur WOO)**: Technical infrastructure standards for WOO compliance
- **PDF/A (ISO 19005)**: Redacted documents should be PDF/A for archival

### Specificity Assessment
- **Specific enough to implement**: Partially. The workflow and scenarios are well-described, but lacks technical detail.
- **Missing/Ambiguous**:
  - No data model for WOO batch, document assessment, or weigeringsgrond records (should extend existing OpenRegister schemas)
  - No API endpoints defined for OpenCatalogi WOO routes
  - No UI wireframes for the WOO processing queue or redaction view
  - WOO reading room catalog type not yet defined (how does it differ from a standard catalog?)
  - Selective entity redaction coordination with Docudesk needs API contract definition
  - Manual redaction regions require PDF coordinate-based selection -- significant technical complexity (Docudesk responsibility)
  - Inventarislijst format not specified (PDF layout? CSV columns?)
  - Integration protocol with Procest not defined (API? events? shared OpenRegister objects?)
  - Integration protocol with Docudesk not defined (how does OpenCatalogi call Docudesk's anonymization pipeline?)
- **Open questions**:
  1. How will the WOO catalog type differ from a standard OpenCatalogi catalog? (custom metadata? different listing structure?)
  2. How will manual redaction regions be defined -- PDF coordinates? Page-level selections? Visual editor? (Docudesk implementation detail)
  3. Should the inventarislijst follow a standard template (many municipalities use a standard format)?
  4. How will PLOOI integration work for publishing to the national transparency platform?
  5. What is the MVP scope -- just the processing queue and assessment, or full reading room publication?
  6. What is the API contract between OpenCatalogi and Docudesk for triggering and receiving redaction results?
