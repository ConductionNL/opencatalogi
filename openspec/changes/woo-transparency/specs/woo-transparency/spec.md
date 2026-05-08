---
status: implemented
---

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

## Requirements

### Requirement: WOO document queue
The system MUST provide a document processing queue for WOO requests, enabling users to track and manage the assessment status of all documents in a WOO request.

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

#### Scenario: Queue displays progress summary
- GIVEN a WOO batch with 20 documents where 8 are assessed and 12 are "Te beoordelen"
- WHEN the user views the batch overview
- THEN the UI MUST show a progress bar or counter indicating 8/20 assessed
- AND the breakdown by status MUST be visible (e.g., 5 openbaar, 2 deels openbaar, 1 niet openbaar)

#### Scenario: Queue supports sorting and filtering
- GIVEN a WOO batch with 50 documents
- WHEN the user interacts with the queue table
- THEN they MUST be able to sort by document name, date, status, and file type
- AND they MUST be able to filter by assessment status
- AND they MUST be able to search by document name

### Requirement: Weigeringsgronden (refusal grounds)
The system MUST support tagging documents with legal grounds for withholding, covering all grounds specified in WOO Articles 5.1 and 5.2.

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

#### Scenario: Weigeringsgronden are displayed with article references
- GIVEN a document tagged with weigeringsgrond "Eerbiediging persoonlijke levenssfeer"
- WHEN the user views the document assessment
- THEN the ground MUST be displayed with its article reference: "Art. 5.1.2.e"
- AND a short description MUST be shown alongside the article number

#### Scenario: Weigeringsgronden selection supports search
- GIVEN the weigeringsgronden selection dialog
- WHEN the user types "persoonlijke"
- THEN the list MUST filter to show matching grounds (e.g., "5.1.2.e Eerbiediging persoonlijke levenssfeer" and "5.2.e Persoonlijke beleidsopvattingen")

#### Scenario: Changing assessment from niet-openbaar removes grounds requirement
- GIVEN a document assessed as "Niet openbaar" with 2 weigeringsgronden selected
- WHEN the user changes the assessment to "Openbaar"
- THEN the weigeringsgronden MUST be cleared
- AND the user MUST be warned that changing the assessment will remove the grounds

### Requirement: Redaction with WOO context
Document redaction MUST be coordinated through Docudesk's anonymization pipeline with WOO-specific context, allowing selective entity redaction with legal ground attribution.

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

#### Scenario: Redaction audit trail
- GIVEN a document with 5 redacted entities
- WHEN the redaction is finalized
- THEN an audit record MUST be created listing each redacted entity, its page/position, the weigeringsgrond applied, and the user who approved the redaction
- AND the audit record MUST be immutable after creation

#### Scenario: Redaction of multi-page document
- GIVEN a 50-page PDF document with entities detected on 12 pages
- WHEN the user reviews the redaction view
- THEN they MUST be able to navigate between pages with detected entities
- AND they MUST see entity highlights on each page
- AND page numbers with entities MUST be highlighted in the page navigation

### Requirement: WOO batch data model
The system MUST store WOO batch and document assessment data in OpenRegister using well-defined schemas.

#### Scenario: WOO batch schema fields
- GIVEN a new WOO processing batch
- THEN the batch object MUST contain at least:
  - `id`: unique batch identifier (UUID)
  - `caseReference`: reference to the Procest WOO case
  - `status`: overall batch status (in_progress, ready_for_review, published)
  - `documents`: array of document assessment references
  - `besluit`: reference to the WOO decision document
  - `inventarislijst`: reference to the generated inventory
  - `createdAt`: ISO 8601 creation timestamp
  - `updatedAt`: ISO 8601 last update timestamp
  - `createdBy`: user who created the batch

#### Scenario: Document assessment schema fields
- GIVEN a document in the WOO queue
- THEN the assessment object MUST contain at least:
  - `id`: unique assessment identifier (UUID)
  - `documentReference`: reference to the source document
  - `fileName`: original file name
  - `fileType`: MIME type of the document
  - `assessment`: enum (te_beoordelen, openbaar, deels_openbaar, niet_openbaar)
  - `weigeringsgronden`: array of article references
  - `redactionInstructions`: reference to Docudesk redaction specification
  - `anonymizedDocument`: reference to the redacted version (if deels_openbaar)
  - `assessedBy`: user who performed the assessment
  - `assessedAt`: ISO 8601 assessment timestamp

#### Scenario: Batch status transitions
- GIVEN a WOO batch in "in_progress" status
- WHEN all documents have been assessed (no "Te beoordelen" remaining)
- THEN the batch status MAY transition to "ready_for_review"
- AND transitioning to "published" MUST require explicit user action

### Requirement: Inventarislijst generation
The system MUST generate a document inventory (inventarislijst) for the WOO decision, conforming to the standard municipal format.

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

#### Scenario: Inventarislijst includes all documents regardless of assessment
- GIVEN a batch with 10 openbaar, 5 deels openbaar, and 5 niet openbaar documents
- WHEN the inventarislijst is generated
- THEN all 20 documents MUST appear in the inventory
- AND niet openbaar documents MUST show their weigeringsgronden
- AND the inventarislijst MUST clearly distinguish between the three categories

#### Scenario: Inventarislijst PDF follows standard format
- GIVEN a generated inventarislijst PDF
- THEN it MUST include:
  - A header with the bestuursorgaan name and WOO request reference
  - A table with columns: Nr., Document, Datum, Beoordeling, Weigeringsgrond(en)
  - A footer with the generation date and total document count
- AND the PDF MUST be PDF/A compliant for archival

#### Scenario: Inventarislijst CSV export
- GIVEN a generated inventarislijst
- WHEN exported as CSV
- THEN the CSV MUST use UTF-8 encoding with BOM
- AND column headers MUST be: "Volgnummer", "Document", "Datum", "Beoordeling", "Weigeringsgronden"
- AND multiple weigeringsgronden MUST be semicolon-separated within the field

#### Scenario: Inventarislijst updates when assessments change
- GIVEN a previously generated inventarislijst
- WHEN a document's assessment is changed
- THEN the inventarislijst MUST be regenerable with the updated assessments
- AND the previous version MUST be preserved (versioned)

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

#### Scenario: Reading room shows besluit and inventarislijst prominently
- GIVEN a visitor accesses the WOO reading room URL
- WHEN the page loads
- THEN the besluit document MUST be displayed at the top of the page
- AND the inventarislijst MUST be downloadable from the page header
- AND individual documents MUST be listed below, grouped by assessment status

#### Scenario: Reading room supports document search
- GIVEN a WOO reading room with 100 published documents
- WHEN a visitor uses the search function
- THEN the search MUST search across document titles and descriptions
- AND results MUST indicate whether each document is openbaar or deels openbaar
- AND results MUST be sortable by date, name, and document number

#### Scenario: Publication includes WOO-specific metadata
- GIVEN a WOO publication
- THEN the Publication object MUST include WOO-specific metadata:
  - `wooRequestDate`: date the WOO request was received
  - `wooDecisionDate`: date of the WOO decision (besluit)
  - `wooRequestReference`: reference number of the WOO request
  - `wooCategory`: category of the WOO request (verzoek, actieve openbaarmaking, etc.)
  - `documentCount`: total number of documents in the batch
  - `publishedCount`: number of published documents (openbaar + deels openbaar)

### Requirement: WOO API endpoints
The system MUST expose API endpoints for WOO batch management, document assessment, and publication.

#### Scenario: Create WOO batch endpoint
- GIVEN authenticated admin user
- WHEN `POST /api/woo/batches` is called with case reference and documents
- THEN a new WOO batch MUST be created
- AND the response MUST include the batch ID and initial document assessments

#### Scenario: Update document assessment endpoint
- GIVEN a WOO batch with document ID "doc-123"
- WHEN `PUT /api/woo/batches/{batchId}/documents/{docId}` is called with assessment data
- THEN the document assessment MUST be updated
- AND if the assessment is "niet_openbaar", weigeringsgronden MUST be required in the request body

#### Scenario: Get batch status endpoint
- GIVEN a WOO batch ID
- WHEN `GET /api/woo/batches/{batchId}` is called
- THEN the response MUST include:
  - Batch metadata (status, dates, case reference)
  - Document summary (count by assessment status)
  - Links to the inventarislijst and besluit if generated

#### Scenario: Generate inventarislijst endpoint
- GIVEN a WOO batch with all documents assessed
- WHEN `POST /api/woo/batches/{batchId}/inventarislijst` is called
- THEN the inventarislijst MUST be generated and stored
- AND the response MUST include a download link

#### Scenario: Publish batch endpoint
- GIVEN a completed WOO batch ready for publication
- WHEN `POST /api/woo/batches/{batchId}/publish` is called
- THEN the WOO reading room MUST be created
- AND the response MUST include the public reading room URL

### Requirement: WOO frontend components
The system MUST provide Vue components for the WOO workflow in the OpenCatalogi admin interface.

#### Scenario: WOO batch list view
- GIVEN an admin user navigates to the WOO section
- WHEN the batch list loads
- THEN all WOO batches MUST be displayed in a table
- AND each row MUST show: case reference, status, document count, progress, creation date
- AND the user MUST be able to click a batch to view its details

#### Scenario: WOO document assessment view
- GIVEN an admin user opens a WOO batch
- WHEN the document queue loads
- THEN all documents MUST be listed with their current assessment status
- AND the user MUST be able to select documents and change their assessment
- AND a document preview MUST be available by clicking on a document

#### Scenario: WOO redaction view
- GIVEN a document assessed as "Deels openbaar"
- WHEN the user opens the redaction view
- THEN the document MUST be displayed with detected entities highlighted
- AND the user MUST be able to toggle individual entities for redaction
- AND a weigeringsgrond selector MUST be available for each redaction
- AND a preview button MUST show the redacted result

### Requirement: WOO catalog type
WOO reading rooms MUST be implemented as a special catalog type within OpenCatalogi's existing catalog infrastructure.

#### Scenario: WOO catalog has distinct type
- GIVEN a catalog created for WOO publications
- THEN the catalog MUST have `type: "woo_reading_room"` (or equivalent discriminator)
- AND it MUST be filtered separately from regular publication catalogs in the admin UI

#### Scenario: WOO catalog has WOO-specific configuration
- GIVEN a WOO reading room catalog
- THEN it MUST support configuration for:
  - Default sort order (by volgnummer)
  - Grouping by assessment status
  - Custom page template with besluit and inventarislijst sections
  - Bestuursorgaan attribution

#### Scenario: WOO catalogs appear in sitemap
- GIVEN a published WOO reading room
- WHEN the sitemap is generated
- THEN the WOO reading room URL MUST be included in the sitemap
- AND individual published documents MUST have their own sitemap entries

### Requirement: Notification and communication
The system MUST support notifications for WOO workflow events.

#### Scenario: Notification when batch is ready for review
- GIVEN a WOO batch where all documents have been assessed
- WHEN the batch transitions to "ready_for_review"
- THEN the responsible reviewer MUST receive a Nextcloud notification
- AND the notification MUST link to the batch review page

#### Scenario: Notification when batch is published
- GIVEN a WOO batch that has been published
- WHEN publication completes
- THEN the batch creator and reviewer MUST receive notifications
- AND the notification MUST include the public reading room URL

#### Scenario: Notification on assessment deadline approaching
- GIVEN a WOO batch with a configured besluit deadline (typically 4+2 weeks per WOO)
- WHEN the deadline is 5 days away and assessment is incomplete
- THEN a warning notification MUST be sent to assigned users
- AND the notification MUST indicate how many documents remain unassessed

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
- OpenCatalogi SitemapService (for WOO reading room sitemap inclusion)
- OpenCatalogi SearchService (for reading room document search)

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
  - `lib/Controller/PublicationsController.php` -- publication CRUD endpoints
  - `lib/Controller/ListingsController.php` -- listing CRUD endpoints
  - `lib/Controller/CatalogiController.php` -- catalog CRUD endpoints
  - `lib/Controller/SearchController.php` -- search endpoint
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
  - No WOO-specific API endpoints
  - No WOO frontend components
  - No integration with Procest woo-case-type
  - No integration with Docudesk anonymization API from OpenCatalogi
  - No WOO notification workflow

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
- **Specific enough to implement**: Yes, for the core workflow (batch management, assessment, inventarislijst, reading room publication). The WOO batch data model, API endpoints, and frontend components are well-defined.
- **Remaining open questions**:
  1. How will manual redaction regions be defined -- PDF coordinates? Page-level selections? Visual editor? (Docudesk implementation detail)
  2. Should the inventarislijst follow a specific municipal standard template?
  3. How will PLOOI integration work for publishing to the national transparency platform?
  4. What is the API contract between OpenCatalogi and Docudesk for triggering and receiving redaction results?
