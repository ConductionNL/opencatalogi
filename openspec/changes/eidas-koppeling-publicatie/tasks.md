# Tasks: eidas-koppeling-publicatie

## Phase 1: Data Model & Storage

### Task 1.1: Implement DienstPublicatie entity and register
- [ ] Define DienstPublicatie schema in openregister (uuid, serviceDienstUuid, scope, status, publicatieDoelen, publicatieDatum, ingetrokkenDatum, laatsteWijziging)
- [ ] Create REST endpoints: POST/PUT/GET /publicaties/{uuid}
- [ ] Implement scope validation (lokaal, regionaal, nationaal, grensoverschrijdend)
- [ ] Implement status state machine (concept → intern → gepubliceerd → ingetrokken)
- [ ] Add RBAC: only service owner + admin can edit
- [ ] Spec ref: REQ-005
- **Acceptance criteria**: DienstPublicatie can be created, retrieved, and status-transitioned via API

### Task 1.2: Implement EidasMetadata entity and register
- [ ] Define EidasMetadata schema in openregister (uuid, dienstPublicatieUuid, sdgBijlage, proceduureCategorie, minimumAssuranceNiveau, grensoverschrijdend, talen)
- [ ] Create CRUD endpoints: POST/PUT/GET /publicaties/{uuid}/eidas-metadata
- [ ] Implement validation: SDG bijlage + categorie REQUIRED if publicatieDoelen contains "your-europe"
- [ ] Implement assurance-level enum (laag, substantief, hoog)
- [ ] Spec ref: REQ-001, REQ-003
- **Acceptance criteria**: EidasMetadata saved per DienstPublicatie; validation blocks publication without SDG fields for your-europe

### Task 1.3: Implement SamlMetadata entity and register
- [ ] Define SamlMetadata schema (uuid, dienstPublicatieUuid, spEntityId, assertionConsumerServiceUrl, singleLogoutServiceUrl, signingCertificate, encryptionCertificate, supportedAttributes, certificateExpiryDate)
- [ ] Create CRUD endpoints: POST/PUT/GET /publicaties/{uuid}/saml-metadata
- [ ] Add certificate file upload / paste endpoints
- [ ] Validate certificate format (PEM) and expiry date
- [ ] Spec ref: REQ-004
- **Acceptance criteria**: SAML certs can be uploaded; cert expiry tracked

### Task 1.4: Implement TaalVariant entity and register
- [ ] Define TaalVariant schema (uuid, dienstPublicatieUuid, taal, titel, beschrijving, procedurestappen, vertaalbron, status, aanmaakDatum, goedkeuringsDatum)
- [ ] Create CRUD endpoints: POST/PUT/GET /publicaties/{uuid}/taal-varianten/{taal}
- [ ] Implement vertaalbron enum (handmatig, eTranslation, professioneel)
- [ ] Implement status enum (concept, gepubliceerd)
- [ ] Spec ref: REQ-002, REQ-006
- **Acceptance criteria**: TaalVarianten per language can be stored; status transitions work

### Task 1.5: Implement CrossBorderProces entity and register
- [ ] Define CrossBorderProces schema (uuid, dienstPublicatieUuid, oatsEvidenceTypes, oatsEvidenceBrokerEndpoint, supportedInputCredentials, vertaalStatus, ondersteuningVia)
- [ ] Create CRUD endpoints: POST/PUT/GET /publicaties/{uuid}/cross-border-proces
- [ ] Validate OOTS evidence types against registry (or accept URN format)
- [ ] Spec ref: REQ-007
- **Acceptance criteria**: CrossBorderProces linked to DienstPublicatie

### Task 1.6: Implement PublicatieLog entity and register
- [ ] Define PublicatieLog schema (uuid, dienstPublicatieUuid, doel, payloadHash, responseStatus, responseBody, tijdstip, retryAttempt, fouten)
- [ ] Create append-only endpoint: POST /publicaties/{uuid}/publicatie-logs (internal only)
- [ ] Create read endpoint: GET /publicaties/{uuid}/publicatie-logs with filtering by doel, tijdstip range
- [ ] Spec ref: REQ-005
- **Acceptance criteria**: Sync attempts logged per target with response codes

## Phase 2: SDG Validation & Assurance-Level Gating

### Task 2.1: Implement SDG classification validation gate
- [ ] In DienstPublicatie.status transition to "gepubliceerd": check if publicatieDoelen includes "your-europe"
- [ ] If yes, validate that EidasMetadata.sdgBijlage and EidasMetadata.proceduureCategorie are non-null
- [ ] If no, return REQ-001-E001 error with hint text
- [ ] Add unit tests: valid SDG, missing sdgBijlage, missing categorie, non-your-europe publication
- [ ] Spec ref: REQ-001
- **Acceptance criteria**: Publication blocked without SDG fields when targeting your-europe

### Task 2.2: Implement assurance-level validation gate
- [ ] In DienstPublicatie.status transition to "gepubliceerd": check EidasMetadata.minimumAssuranceNiveau
- [ ] Fetch SamlMetadata.supportedAttributes
- [ ] Map assurance level to eIDAS LOA:
  - "laag" → any eIDAS-notified means
  - "substantief" → loa:substantial minimum
  - "hoog" → loa:high minimum (e.g., DigiD Hoog, eIDAS eID notified)
- [ ] If mismatch, return REQ-003-E001 error: "Assurance level not supported by endpoint"
- [ ] Add unit tests: supported assurance, unsupported assurance, endpoint without loa declaration
- [ ] Spec ref: REQ-003
- **Acceptance criteria**: Publication blocked if endpoint assurance < declared minimum

### Task 2.3: Implement English requirement for cross-border services
- [ ] In DienstPublicatie.status transition to "gepubliceerd": check if scope=grensoverschrijdend
- [ ] If yes, check if TaalVariant with taal=en exists
- [ ] If no, return REQ-002-E001: "English variant required for cross-border service; offer eTranslation"
- [ ] Add option for editor to accept auto-translation proposal
- [ ] Spec ref: REQ-002
- **Acceptance criteria**: Cross-border publications require English; eTranslation offered if missing

## Phase 3: SAML Metadata Generation & Publication

### Task 3.1: Implement SAML 2.0 SP metadata XML generation
- [ ] Create service: `SamlMetadataGenerator::generateSpMetadataXml(SamlMetadata $saml)`
- [ ] Generate XML structure:
  - `<EntityDescriptor entityID="{spEntityId}">`
  - `<SPSSODescriptor ...>` with AssertionConsumerServiceURL, SingleLogoutServiceURL
  - `<KeyDescriptor use="signing">` + `<KeyDescriptor use="encryption">` with X.509 certs
  - `<NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified</NameIDFormat>`
  - `<RequestedAttribute Name="...">` for each supported attribute (BSN, eIDAS-MDS attributes)
  - `</SPSSODescriptor>`
- [ ] Validate XML against SAML 2.0 schema + eIDAS profile
- [ ] Add unit tests: valid SP metadata, missing certs, invalid attributes
- [ ] Spec ref: REQ-004
- **Acceptance criteria**: Generated SAML XML conforms to eIDAS profile

### Task 3.2: Implement public SAML metadata endpoint
- [ ] Create public REST endpoint: GET `/catalog/saml/sp-metadata/{dienstPublicatieUuid}.xml`
- [ ] No authentication required
- [ ] Return Content-Type: application/xml
- [ ] Return HTTP 404 if service status = ingetrokken (withdrawn)
- [ ] Add caching headers: Cache-Control: max-age=3600
- [ ] Add unit tests: valid request, withdrawn service, non-existent service
- [ ] Spec ref: REQ-004
- **Acceptance criteria**: SAML metadata publicly accessible; Logius can fetch it

### Task 3.3: Implement certificate refresh notification
- [ ] On SamlMetadata.signingCertificate or encryptionCertificate update:
  - Regenerate SAML metadata XML
  - Trigger POST to Logius metadata-refresh-endpoint (configured in settings)
  - Include new metadata URL and refresh timestamp
  - Log notification attempt in PublicatieLog
- [ ] Implement retry with exponential backoff if Logius endpoint returns 5xx
- [ ] Add unit tests: cert update triggers refresh, Logius success, Logius failure
- [ ] Spec ref: REQ-004
- **Acceptance criteria**: Certificate changes trigger refresh notification to Logius

### Task 3.4: Implement metadata revocation on service withdrawal
- [ ] On DienstPublicatie.status = ingetrokken:
  - Mark SamlMetadata as revoked
  - SAML endpoint returns HTTP 404
  - Send revocation notice to Logius endpoint
- [ ] Add unit tests: status change to withdrawn, endpoint returns 404
- [ ] Spec ref: REQ-004
- **Acceptance criteria**: Withdrawn services' metadata returns 404

## Phase 4: Publication Fan-Out

### Task 4.1: Implement openconnector adapter interface
- [ ] Define PublicationTarget interface in openconnector:
  - `publish(DienstPublicatie $dienst): PublicationResult`
  - `retrieve(string $id): PublicationStatus` (for polling)
  - `supported(): array` (metadata about supported fields)
- [ ] Create adapter implementations:
  - WebsiteAdapter (local REST PUT)
  - OverheidNlAdapter (SOAP)
  - YourEuropeAdapter (OData)
  - EidasAdapter (SAML metadata → Logius)
- [ ] Each adapter serializes metadata according to target spec
- [ ] Spec ref: REQ-005
- **Acceptance criteria**: Adapters implement interface; each can serialize metadata

### Task 4.2: Implement publication fan-out orchestration
- [ ] Create PublicationService in opencatalogi:
  - `publish(DienstPublicatie $dienst): PublicationResult`
- [ ] For each doel in publicatieDoelen:
  1. Serialize metadata to target format
  2. Get adapter for target
  3. Call adapter.publish() in parallel (or sequential with timeout)
  4. Collect responses
- [ ] Wait for all targets to complete (or timeout after 30 seconds)
- [ ] Create PublicatieLog entries per target
- [ ] Determine overall status:
  - All succeed → status=gepubliceerd
  - Some fail → status=gepubliceerd_met_waarschuwing
  - All fail → status stays concept; return error
- [ ] Spec ref: REQ-005
- **Acceptance criteria**: Multi-target publication works; partial failures tracked; idempotent by payload hash

### Task 4.3: Implement publication status UI
- [ ] Add publication history tab to DienstPublicatie detail view
- [ ] Show timeline of sync attempts per target (doel)
- [ ] Display success/failure per doel
- [ ] Show error details for failed targets
- [ ] Add "Retry {doel}" button for individual failed targets
- [ ] Add warning indicator if status=gepubliceerd_met_waarschuwing
- [ ] Spec ref: REQ-005
- **Acceptance criteria**: UI shows sync history and retry options

## Phase 5: eTranslation Integration

### Task 5.1: Integrate CEF eTranslation API client
- [ ] Add eTranslation REST client to openconnector
- [ ] Methods:
  - `submitTranslationJob(sourceLanguage, targetLanguages, content): jobId`
  - `pollJobStatus(jobId): status & translations`
  - `cancelJob(jobId): bool`
- [ ] Implement async polling with exponential backoff (max 5 minutes)
- [ ] Handle rate limiting (queue multiple jobs if needed)
- [ ] Add unit tests: successful translation, API error, timeout
- [ ] Spec ref: REQ-006
- **Acceptance criteria**: eTranslation API client works

### Task 5.2: Implement "translate to EU languages" workflow
- [ ] Add button to TaalVariant management UI: "Auto-translate Dutch to [checkboxes for 24 EU languages]"
- [ ] On click:
  - Serialize Dutch TaalVariant (titel, beschrijving, procedurestappen)
  - Submit eTranslation job via openconnector
  - Show polling UI: "Translation in progress..."
- [ ] When complete:
  - Create provisional TaalVariant entries (status=concept, vertaalbron=eTranslation)
  - Display side-by-side review interface (Dutch vs. translated)
  - Show potential issues (untranslated tokens, character encoding)
- [ ] Editor can: Accept, Edit, or Reject per language
- [ ] On Accept: status → gepubliceerd; translation ready for publication
- [ ] On Reject: delete proposed TaalVariant; offer manual entry option
- [ ] Spec ref: REQ-006
- **Acceptance criteria**: End-to-end eTranslation workflow works

### Task 5.3: Implement professional translation fallback
- [ ] If eTranslation fails or quality is poor, offer "Request professional translation"
- [ ] Create workflow task for external translator
- [ ] Allow upload of professionally-translated text
- [ ] Create TaalVariant with vertaalbron=professioneel
- [ ] Spec ref: REQ-006
- **Acceptance criteria**: Professional translation path available

## Phase 6: Your-Europe Feedback Integration

### Task 6.1: Implement Your-Europe feedback API client
- [ ] Add Your-Europe feedback REST client to openconnector
- [ ] Methods:
  - `fetchFeedback(serviceId, since: datetime): array[FeedbackRecord]`
  - `markFeedbackProcessed(feedbackId): bool`
- [ ] Implement pagination for large result sets
- [ ] Handle authentication per Your-Europe API spec
- [ ] Spec ref: REQ-008
- **Acceptance criteria**: Feedback API client works

### Task 6.2: Implement daily feedback harvest cron job
- [ ] Create scheduled job: `PublicationFeedbackHarvest` running daily at 02:00 UTC
- [ ] For each published DienstPublicatie (scope includes grensoverschrijdend or your-europe):
  1. Call Your-Europe feedback API (since last harvest)
  2. Fetch new feedback (ratings, comments, region, timestamp)
  3. Create FeedbackRecord linked to DienstPublicatie
  4. Parse sentiment (positive, neutral, negative) if available
  5. Mark as processed on Your-Europe side
- [ ] Log harvest results: count new feedback, errors
- [ ] Notify service owners of significant negative feedback (rating < 2 or urgent flag)
- [ ] Spec ref: REQ-008
- **Acceptance criteria**: Daily harvest runs; feedback stored and tracked

### Task 6.3: Implement feedback review UI
- [ ] Add "Feedback" tab to DienstPublicatie detail page
- [ ] Show:
  - Average rating (e.g., 4.3/5) from last 30 days
  - Feedback timeline (newest first)
  - Per-feedback: rating, comment, region, date
  - Sentiment indicator (positive/neutral/negative)
  - Link to Your-Europe portal feedback thread
- [ ] Allow service owner to:
  - Add internal note to feedback
  - Mark as "addressed" (after content fix)
  - Create task from feedback: "Fix unclear procedure steps per feedback"
- [ ] Show task link in feedback if created
- [ ] Spec ref: REQ-008
- **Acceptance criteria**: Feedback review UI usable by service owner

## Phase 7: OOTS Evidence Integration

### Task 7.1: Implement OOTS evidence type registry lookup
- [ ] Create OOTS client in openconnector
- [ ] Methods:
  - `validateEvidenceType(urn: string): bool`
  - `getEvidenceTypeMetadata(urn: string): object` (description, required fields, EU provider endpoint)
- [ ] Cache evidence registry locally (refresh daily)
- [ ] Spec ref: REQ-007
- **Acceptance criteria**: OOTS evidence types can be validated

### Task 7.2: Implement CrossBorderProces UI for OOTS configuration
- [ ] Add form in DienstPublicatie detail:
  - Dropdown for evidence type (with autocomplete)
  - Evidence broker endpoint URL input (pre-filled if EU-wide registry available)
  - List of supported input credentials (eIDAS eID, EUDI Wallet, etc.)
  - Localization option: "Document available via OOTS" vs. "Manual upload" vs. "Human contact"
- [ ] On save: validate evidence type + endpoint
- [ ] On save: generate evidence request form reference
- [ ] Spec ref: REQ-007
- **Acceptance criteria**: OOTS configuration UI works

### Task 7.3: Implement OOTS evidence request integration (future phase)
- [ ] **Note**: This is post-launch. Document the integration point:
  - Service form displays: "Click to retrieve {evidence_type} from {country}"
  - Redirects to OOTS evidence broker
  - Evidence broker delivers PDF to service endpoint
  - Service automatically ingests document
- [ ] Spec ref: REQ-007
- **Acceptance criteria**: Integration point documented; can be implemented in follow-up phase

## Phase 8: Integration & Testing

### Task 8.1: End-to-end test: local publication
- [ ] Create test scenario: "Publication service to local website only"
- [ ] Steps:
  1. Create DienstPublicatie (scope=lokaal, publicatieDoelen=[website])
  2. Create EidasMetadata (laag assurance, talen=[nl])
  3. Create TaalVariant (nl only)
  4. Publish → status → gepubliceerd
  5. Verify PublicatieLog entry created with success
- [ ] Acceptance: publication succeeds without Your-Europe setup
- **Acceptance criteria**: Local publication works without cross-border overhead

### Task 8.2: End-to-end test: your-europe publication
- [ ] Create test scenario: "Publication to Your-Europe with SDG + English"
- [ ] Steps:
  1. Create DienstPublicatie (scope=nationaal, publicatieDoelen=[website, your-europe])
  2. Create EidasMetadata (sdgBijlage=I, proceduureCategorie=registratie, substantief assurance, talen=[nl, en])
  3. Create TaalVariant (nl + en)
  4. Publish → status → gepubliceerd
  5. Verify PublicatieLog entries for both targets
- [ ] Acceptance: Your-Europe sync succeeds with SDG classification
- **Acceptance criteria**: Your-Europe publication works with proper classification

### Task 8.3: End-to-end test: cross-border with SAML
- [ ] Create test scenario: "Cross-border service with eIDAS SAML"
- [ ] Steps:
  1. Create DienstPublicatie (scope=grensoverschrijdend, publicatieDoelen=[website, your-europe, eidas])
  2. Create EidasMetadata (hoog assurance, talen=[nl, en, de])
  3. Create SamlMetadata (DigiD Hoog, signing + encryption certs)
  4. Create TaalVariant (nl + en + de with eTranslation)
  5. Publish → status → gepubliceerd
  6. Verify SAML metadata accessible at public endpoint
  7. Verify all three targets have PublicatieLog entries
- [ ] Acceptance: Full cross-border setup works
- **Acceptance criteria**: Multi-target publication + SAML metadata works

### Task 8.4: Persona testing with redacteur
- [ ] Test with Catalogus-redacteur persona
- [ ] Workflow: Create service → classify for Your-Europe → translate to English → auto-translate to German → publish
- [ ] Verify: all validation gates work, SDG required, English proposed, eTranslation offered
- [ ] Persona: redacteur (content manager with no technical background)
- **Acceptance criteria**: Workflow intuitive for redacteur

### Task 8.5: Persona testing with diensteigenaar
- [ ] Test with Dienst-eigenaar persona
- [ ] Workflow: View published service → check Your-Europe feedback → update procedure steps → republish
- [ ] Verify: feedback displayed, task creation works, republication succeeds
- [ ] Persona: diensteigenaar (service owner, not technical)
- **Acceptance criteria**: Service owner can manage feedback and updates

### Task 8.6: Persona testing with eIDAS-beheerder
- [ ] Test with eIDAS-functioneel beheerder persona
- [ ] Workflow: Upload SAML certs → view SAML metadata endpoint → update cert → verify refresh notification
- [ ] Verify: cert upload works, metadata updated, Logius notified
- [ ] Persona: eIDAS-beheerder (technical, certificate management background)
- **Acceptance criteria**: Certificate lifecycle works for eIDAS operator

### Task 8.7: Cross-app integration testing
- [ ] Test openconnector adapters (Your-Europe, Logius, eTranslation, OOTS)
- [ ] Test openregister schema integration for DienstPublicatie + metadata entities
- [ ] Test docudesk archival of published versions (snapshot metadata at publication time)
- [ ] Test softwarecatalog CPSV-AP uplink (service linked to software components)
- [ ] **Acceptance criteria**: All cross-app dependencies verified

## Phase 9: Documentation & Deployment

### Task 9.1: API documentation
- [ ] Generate OpenAPI spec for all DienstPublicatie endpoints
- [ ] Document publication state machine and validation gates
- [ ] Document SAML metadata endpoint and consumption by Logius
- [ ] Provide curl examples for each major workflow
- [ ] **Acceptance criteria**: API fully documented

### Task 9.2: Administrator setup guide
- [ ] Document Logius eIDAS node configuration (metadata URL registration, cert management)
- [ ] Document Your-Europe API credentials setup
- [ ] Document eTranslation API token provisioning
- [ ] Document OOTS evidence broker registration
- [ ] Provide troubleshooting section (cert expiry, sync failures, feedback harvesting)
- [ ] **Acceptance criteria**: Admins can set up all external integrations

### Task 9.3: User guide for redacteurs
- [ ] Step-by-step: create service → classify for Your-Europe → request English translation
- [ ] Screenshots of SDG classification form
- [ ] Screenshots of eTranslation review interface
- [ ] FAQ: "Why is English required?", "What if my service is not cross-border?", "How do I add German?"
- [ ] **Acceptance criteria**: Redacteurs can follow guide end-to-end

### Task 9.4: User guide for diensteigenaren
- [ ] How to view Your-Europe feedback
- [ ] How to create improvement tasks from feedback
- [ ] How to republish after content changes
- [ ] FAQ: "How often is feedback harvested?", "Can I reply to feedback on Your-Europe?"
- [ ] **Acceptance criteria**: Service owners understand feedback workflow

### Task 9.5: Prepare deployment plan
- [ ] Database migrations (new entities + tables)
- [ ] Config secrets (Logius endpoint URL, Your-Europe API key, eTranslation token)
- [ ] Feature flag if launching incrementally (local only → your-europe → cross-border → SAML)
- [ ] Monitoring: publication success rate per target, eTranslation job completion, feedback harvest lag
- [ ] **Acceptance criteria**: Deployment can be executed safely

---

## Summary of Spec References
- **REQ-001**: SDG classification mandatory for Your-Europe (Tasks 2.1, 8.2, 8.4)
- **REQ-002**: English required for cross-border (Tasks 2.3, 8.3, 8.4)
- **REQ-003**: Assurance level validation (Tasks 2.2, 8.3, 8.6)
- **REQ-004**: SAML metadata generation + publication (Tasks 3.1, 3.2, 3.3, 3.4, 8.3, 8.6)
- **REQ-005**: Publication fan-out to multiple targets (Tasks 4.1, 4.2, 4.3, 8.2, 8.3)
- **REQ-006**: eTranslation for 24 languages (Tasks 5.1, 5.2, 5.3, 8.3, 8.4)
- **REQ-007**: OOTS evidence integration (Tasks 7.1, 7.2, 7.3)
- **REQ-008**: Your-Europe feedback integration (Tasks 6.1, 6.2, 6.3, 8.5)
