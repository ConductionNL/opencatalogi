# woo-transparency Specification

## Purpose
WOO (Wet open overheid) transparency in OpenCatalogi: a disclosure-batch workflow
from document inventory through assessment, redaction coordination, inventarislijst
generation, and publication to a public reading room. Per hydra ADR-022 the
document queue/board is the OpenRegister deck leaf, the publication review/sign-off
gate is an OpenRegister approval-workflow chain, notifications/deadlines are
workflow-integration triggers, and audit immutability is OpenRegister's audit
trail; only the WOO-specific domain (weigeringsgronden, redaction metadata,
inventarislijst, reading-room rendering) lives in OpenCatalogi.

## Requirements
### Requirement: WOO document queue (consumes the OpenRegister deck leaf)
The system MUST provide the WOO document processing queue by **consuming the OpenRegister deck leaf** (`nextcloud-entity-relations` `DeckCardService`, `openregister_deck_links`, `nl.openregister.object.deck.*` events) — NOT by building a bespoke queue table or kanban UI (hydra ADR-022). A disclosure batch is represented as a Deck board whose stacks are the assessment stages; each document is a Deck card linked to its OpenRegister assessment object. WOO contributes only the assessment status vocabulary and the per-document assessment metadata stored on the linked object; board/card mechanics (drag, bulk move, progress, sort/filter/search) are delivered by the leaf and surfaced as the deck widget on the batch object detail page (ADR-019 / ADR-024).

#### Scenario: Receive documents from Procest
- GIVEN a WOO case in Procest with 20 collected documents
- WHEN Procest sends the documents to OpenCatalogi for WOO processing
- THEN a WOO processing batch MUST be created in OpenCatalogi as a Deck board (via the deck leaf)
- AND a Deck card MUST be created for each of the 20 documents, linked to its assessment object via `POST /api/objects/{register}/{schema}/{id}/deck`
- AND each card MUST start in the "Te beoordelen" stack (initial status "Te beoordelen")

#### Scenario: Document assessment statuses map to deck stacks
- GIVEN a document card on the WOO Deck board
- THEN the assessment status MUST be represented as the Deck stack the card is in, supporting:
  - **Te beoordelen** -- not yet assessed
  - **Openbaar** -- fully disclosable, no redaction needed
  - **Deels openbaar** -- needs redaction before disclosure
  - **Niet openbaar** -- withheld entirely
- AND moving a card to the "Niet openbaar" stack MUST require selecting weigeringsgrond(en) on the linked assessment object before the move is accepted
- AND the assessment status on the linked OpenRegister object MUST stay in sync with the card's stack (the deck leaf supports stack-move-driven status changes)

#### Scenario: Bulk assessment via the deck board
- GIVEN 20 document cards in the "Te beoordelen" stack
- WHEN the user multi-selects 5 cards and moves them to the "Openbaar" stack
- THEN all 5 linked assessment objects MUST be updated to "Openbaar" in one action (via the deck leaf bulk-move)
- AND the remaining 15 MUST retain their current status

#### Scenario: Queue progress summary (deck widget)
- GIVEN a WOO Deck board with 20 cards where 8 are assessed and 12 are in "Te beoordelen"
- WHEN the user views the batch object detail page
- THEN the deck widget MUST show the per-stack counts (e.g., 5 openbaar, 2 deels openbaar, 1 niet openbaar, 12 te beoordelen)
- AND a progress indicator of 8/20 assessed MUST be derivable from those stack counts

#### Scenario: Queue sorting, filtering, and search (deck widget)
- GIVEN a WOO Deck board with 50 cards
- WHEN the user interacts with the deck widget
- THEN sorting, filtering by stack/status, and searching by card title MUST be provided by the deck leaf widget
- AND OpenCatalogi MUST NOT reimplement these as a bespoke table

#### Scenario: Deck app unavailable
- GIVEN the Nextcloud Deck app is not installed or disabled
- WHEN the WOO queue is requested
- THEN the deck leaf's graceful-degradation behaviour applies (HTTP 501 `APP_NOT_AVAILABLE`) and OpenCatalogi surfaces a clear "Deck integration required for the WOO queue" message — OpenCatalogi MUST NOT fall back to a bespoke queue implementation

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

#### Scenario: Redaction audit trail (via OpenRegister audit-trail abstraction)
- GIVEN a document with 5 redacted entities
- WHEN the redaction is finalized
- THEN an audit record MUST be created listing each redacted entity, its page/position, the weigeringsgrond applied, and the user who approved the redaction
- AND immutability MUST be provided by the OpenRegister immutable audit-trail abstraction on the assessment object (ADR-022) — NOT a bespoke immutable events table in OpenCatalogi
- AND the WOO-specific entity→ground→position payload is the in-app contribution recorded against that audit event

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

#### Scenario: Batch status transitions (gated by approval-workflow)
- GIVEN a WOO batch in "in_progress" status
- WHEN all documents have been assessed (no "Te beoordelen" remaining)
- THEN the batch status MAY transition to "ready_for_review"
- AND the transition from "ready_for_review" to "published" MUST be gated by an OpenRegister **approval-workflow** role-gated approval chain (consumed, not a bespoke state machine — ADR-022)
- AND the approval decision MUST be persisted to the approval-workflow execution history (providing the legally required decision trail)
- AND OpenCatalogi MUST NOT implement its own transition-authorization or approval-step machinery

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
The system MUST expose API endpoints for WOO batch management, document assessment, and publication. Queue/board mechanics MUST be delegated to the deck leaf and workflow gating to approval-workflow / workflow-integration (ADR-022); the WOO endpoints orchestrate those leaves and own only the WOO-specific metadata.

#### Scenario: Create WOO batch endpoint (provisions a deck board)
- GIVEN authenticated admin user
- WHEN `POST /api/woo/batches` is called with case reference and documents
- THEN a new WOO batch MUST be created and a corresponding Deck board provisioned via the deck leaf
- AND a Deck card MUST be created and linked for each document
- AND the response MUST include the batch ID, the deck board reference, and initial document assessments

#### Scenario: Update document assessment endpoint (moves the deck card)
- GIVEN a WOO batch with document ID "doc-123"
- WHEN `PUT /api/woo/batches/{batchId}/documents/{docId}` is called with assessment data
- THEN the document assessment MUST be updated and the linked Deck card moved to the matching stack via the deck leaf
- AND if the assessment is "niet_openbaar", weigeringsgronden MUST be required in the request body
- AND OpenCatalogi MUST NOT maintain a parallel queue-state store separate from the deck links + assessment object

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
The system MUST provide Vue components for the WOO-specific parts of the workflow in the OpenCatalogi admin interface. The queue/board view itself MUST be the deck leaf widget surfaced on the batch object detail page (ADR-019 / ADR-024), not a bespoke queue table; OpenCatalogi builds only the WOO-specific surfaces (weigeringsgronden, redaction review, inventarislijst).

#### Scenario: WOO batch list view
- GIVEN an admin user navigates to the WOO section
- WHEN the batch list loads
- THEN all WOO batches MUST be displayed in a table
- AND each row MUST show: case reference, status, document count, progress, creation date
- AND the user MUST be able to click a batch to view its details

#### Scenario: WOO document queue view (deck widget)
- GIVEN an admin user opens a WOO batch
- WHEN the batch object detail page loads
- THEN the document queue MUST be rendered by the OpenRegister deck leaf widget (board + stacks + cards) surfaced via the app manifest
- AND the user MUST be able to move cards between stacks (changing assessment) and multi-select for bulk moves through that widget
- AND a document preview MUST be available from a card
- AND OpenCatalogi MUST NOT build a bespoke assessment table to duplicate the deck widget

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

### Requirement: Notification and communication (consumes workflow-integration / flow)
The system MUST support notifications for WOO workflow events by **consuming the OpenRegister `workflow-integration` (flow / n8n) leaf** — configuring workflow triggers on OpenRegister register events / schema hooks — NOT by coding bespoke notification listeners in OpenCatalogi (hydra ADR-022). OpenCatalogi provides the WOO-specific trigger configuration and message templates; dispatch, scheduling, and delivery are owned by the workflow leaf.

#### Scenario: Notification when batch is ready for review
- GIVEN a WOO batch where all documents have been assessed
- WHEN the batch transitions to "ready_for_review"
- THEN a `workflow-integration` trigger on that status-change event MUST notify the responsible reviewer
- AND the notification MUST link to the batch review page
- AND OpenCatalogi MUST NOT register a bespoke event listener for this

#### Scenario: Notification when batch is published
- GIVEN a WOO batch that has been published
- WHEN publication completes
- THEN a `workflow-integration` trigger MUST notify the batch creator and reviewer
- AND the notification MUST include the public reading room URL

#### Scenario: Notification on assessment deadline approaching
- GIVEN a WOO batch with a configured besluit deadline (typically 4+2 weeks per WOO)
- WHEN the deadline is 5 days away and assessment is incomplete
- THEN a scheduled `workflow-integration` (flow / n8n) workflow MUST send a warning to assigned users
- AND the notification MUST indicate how many documents remain unassessed
- AND the deadline-timer logic MUST live in the workflow leaf, not as bespoke in-app cron code

