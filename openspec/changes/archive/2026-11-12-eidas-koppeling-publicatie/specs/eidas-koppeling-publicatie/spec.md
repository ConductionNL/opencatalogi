---
status: draft
---

# eIDAS Publication Specification

## Purpose
Enable opencatalogi to publish public service metadata to the EU Single Digital Gateway (SDG), Your-Europe portal, and the Dutch eIDAS node (Logius) in compliance with eIDAS (EU 910/2014), SDG (EU 2018/1724), and OOTS (EU 2022/1463) regulations. Services can be configured with language variants, authentication requirements, cross-border scope, and automatic metadata sync across multiple publication targets.

## Context
Dutch municipalities serve both domestic and EU-wide audiences. The eIDAS and SDG regulations mandate that essential public services be:
1. Machine-readable and discoverable on EU catalogs (Your-Europe portal)
2. Accessible cross-border for EU citizens and businesses
3. Authenticated according to eIDAS assurance levels
4. Published with standardized metadata (CPSV-AP)
5. Available in at least English for cross-border users

This spec defines how opencatalogi stores, validates, and syncs service publication metadata across these requirements.

**Related specs:**
- OpenRegister `register-i18n`: Language-tagged field storage and Accept-Language negotiation
- OpenCatalogi `i18n-opencatalogi`: UI string translation (not service content)
- OpenConnector: Adapter framework for Your-Europe, Logius, OOTS, eTranslation APIs

**Related entities in opencatalogi:**
- Service (existing): name, summary, description, contact
- DienstPublicatie (new): publication status, scope, targets, dates
- EidasMetadata (new): SDG classification, assurance level, languages
- SamlMetadata (new): SAML federation with Logius
- CrossBorderProces (new): OOTS, credentials, service localization
- TaalVariant (new): per-language description + procedure steps
- PublicatieLog (new): sync history per target

## Requirements

### Requirement: SDG classification is mandatory for Your-Europe publication
Service metadata MUST be classified according to SDG Annex (I, II, or III) and procedure category before publication to the Your-Europe portal. Without this classification, the service cannot enter published status.

#### Scenario: Redactor marks service for Your-Europe publication
- GIVEN a service eligible for SDG (e.g., "building permit application")
- WHEN a content editor sets `publicatieDoelen` to include "your-europe"
- THEN opencatalogi MUST prevent advancement to status=published until both:
  - `sdgBijlage` is set to one of [I, II, III]
  - `proceduureCategorie` is set to one of [registratie, identificatie, document, betaling]
- AND attempting to publish without these values MUST return error REQ-001-E001 with hint text

#### Scenario: User saves service with incomplete SDG metadata
- GIVEN `publicatieDoelen` includes "your-europe" but `sdgBijlage` is null
- WHEN user clicks "Publish"
- THEN the form MUST highlight the missing fields in red
- AND the save MUST fail with message "SDG classification required for Your-Europe publication"

#### Scenario: Service can be published to website without SDG metadata
- GIVEN a service with `publicatieDoelen=[website]` but no SDG classification
- WHEN user clicks "Publish"
- THEN the service MUST enter status=published successfully
- AND no error MUST be raised (SDG only mandatory for your-europe target)

### Requirement: English language variant is mandatory for cross-border services
When a service is marked with `scope=grensoverschrijdend` (cross-border) and publication is initiated, a TaalVariant in English must exist for title, description, and procedure steps. If missing, the system proposes auto-translation via eTranslation API for manual review.

#### Scenario: Service marked cross-border without English variant
- GIVEN a service with `scope=grensoverschrijdend` and TaalVariant.taal=[nl]
- WHEN publication is initiated
- THEN opencatalogi MUST detect the missing English variant
- AND MUST offer auto-translation via eTranslation for these fields:
  - titel (title)
  - beschrijving (description)
  - procedurestappen (procedure steps)
- AND MUST present proposed translation for editor review before finalizing publication

#### Scenario: Editor accepts eTranslation proposal
- GIVEN an auto-translation proposal from eTranslation
- WHEN editor clicks "Accept translation" after review
- THEN a new TaalVariant with taal=en and vertaalbron=eTranslation MUST be created
- AND the TaalVariant.status MUST be set to "concept"
- AND publication MAY proceed (with warning that translation is AI-generated)

#### Scenario: Editor rejects auto-translation and provides manual English version
- GIVEN an eTranslation proposal for a cross-border service
- WHEN editor clicks "Reject" and manually pastes English text
- THEN a new TaalVariant with taal=en and vertaalbron=handmatig MUST be created
- AND status MUST be "gepubliceerd"
- AND no further translation is needed

#### Scenario: Cross-border service already has English variant
- GIVEN a service with `scope=grensoverschrijdend` and TaalVariant with taal=en
- WHEN publication is initiated
- THEN no translation proposal MUST be made
- AND publication MAY proceed immediately (if other requirements also met)

### Requirement: Assurance level MUST match authentication backend capabilities
When a DienstPublicatie is configured with `minimumAssuranceNiveau=high`, the underlying authentication endpoint MUST be capable of enforcing eIDAS-high authentication (e.g., DigiD Hoog or notified eID from another member state). Publication is blocked if capabilities don't match.

#### Scenario: Service requires high assurance but endpoint only supports substantive
- GIVEN a service with EidasMetadata.minimumAssuranceNiveau="hoog"
- AND SamlMetadata.supportedAttributeList does not include high-assurance attributes (e.g., lacks loa:high)
- WHEN publication is initiated
- THEN opencatalogi MUST validate SamlMetadata against eIDAS profile
- AND MUST return error REQ-003-E001: "Assurance level 'high' unsupported by authentication endpoint"
- AND publication MUST be blocked

#### Scenario: DigiD Hoog endpoint published with high assurance
- GIVEN a service endpoint integrated with DigiD Hoog (eIDAS loa:high)
- AND SamlMetadata correctly declares high-assurance support
- WHEN publication is initiated with minimumAssuranceNiveau="hoog"
- THEN validation MUST pass
- AND publication MUST proceed

#### Scenario: Endpoint supports multiple assurance levels
- GIVEN a SAML endpoint supporting low, substantive, and high authentication
- AND the service EidasMetadata specifies minimumAssuranceNiveau="substantief"
- WHEN publication is initiated
- THEN validation MUST pass (substantive is supported)
- AND publication MUST proceed

### Requirement: SAML metadata MUST be generated and published for eIDAS node registration
When a DienstPublicatie is marked for eIDAS publication and SamlMetadata is configured, opencatalogi MUST auto-generate a SAML 2.0 SP metadata XML document and publish it at a fixed public URL. The Logius eIDAS node will consume this URL for automated registration.

#### Scenario: SAML metadata XML generation
- GIVEN a service with:
  - SamlMetadata.spEntityId = "urn:nl:gemeente-amsterdam:sp:diensten"
  - SamlMetadata.serviceName = "Building Permits"
  - SamlMetadata.supportedAttributes = ["BSN", "eIDAS-MDS-naturalperson"]
  - SamlMetadata.signingCertificate (PEM)
  - SamlMetadata.encryptionCertificate (PEM)
- WHEN SamlMetadata is saved
- THEN opencatalogi MUST generate SAML 2.0 SP metadata XML conforming to eIDAS profile:
  - EntityDescriptor with entityID=spEntityId
  - SPSSODescriptor with AssertionConsumerServiceURL, SingleLogoutServiceURL
  - KeyDescriptor with signing and encryption certificates (X.509)
  - NameIDFormat: unspecified
  - RequestedAttributes list with eIDAS-MDS attributes
- AND MUST publish at: `{base-url}/catalog/saml/sp-metadata/{serviceDienstPublicatieUuid}.xml`

#### Scenario: SAML metadata is publicly accessible without authentication
- GIVEN generated SAML metadata XML at `/catalog/saml/sp-metadata/{uuid}.xml`
- WHEN accessed without API credentials
- THEN the endpoint MUST return HTTP 200 with Content-Type: application/xml
- AND no authentication MUST be required (Logius must be able to fetch it)

#### Scenario: Certificate refresh triggers metadata update notification
- GIVEN published SAML metadata for a service
- WHEN editor updates SamlMetadata.signingCertificate (renewal)
- THEN opencatalogi MUST:
  - Regenerate the SP metadata XML
  - Update the published metadata at the fixed URL
  - Send a metadata-refresh notification to the Logius endpoint configured in settings
- AND the notification MUST include the new metadata URL and refresh timestamp

#### Scenario: Metadata is revoked when service is withdrawn
- GIVEN published SAML metadata and DienstPublicatie.status=gepubliceerd
- WHEN editor changes status to "ingetrokken" (withdrawn)
- THEN the metadata endpoint MUST return HTTP 404
- AND a revocation notice MUST be sent to Logius

### Requirement: Publication MUST fan-out to all configured targets atomically
When a DienstPublicatie is marked for publication and multiple targets are configured (website, overheid.nl, your-europe, eidas), opencatalogi MUST push to each target independently. The publication is considered fully successful only when all targets succeed. Partial failures are tracked but do not prevent the publication from advancing to "published" status; instead, status becomes "published_met_waarschuwing" (published with warning).

#### Scenario: Service published to three targets, all succeed
- GIVEN DienstPublicatie with publicatieDoelen=[website, overheid-nl, your-europe]
- AND all target adapters are healthy
- WHEN publication is initiated (status → gepubliceerd)
- THEN opencatalogi MUST:
  1. Push metadata to website (local) via REST
  2. Push to overheid.nl via SOAP
  3. Push to your-europe-API via OData
  4. Wait for all three responses
- AND MUST create PublicatieLog entries for each push with response-status=200/OK
- AND MUST advance status to gepubliceerd (fully successful)
- AND MUST record publicatieDatum as current timestamp

#### Scenario: Your-Europe target fails, other targets succeed
- GIVEN DienstPublicatie with publicatieDoelen=[website, overheid-nl, your-europe]
- WHEN publication is initiated
- AND your-europe-API returns HTTP 400 (invalid SDG classification)
- AND website and overheid.nl pushes succeed
- THEN opencatalogi MUST:
  - Log the failure in PublicatieLog with errorDetail="{response.body}"
  - Set status to gepubliceerd_met_waarschuwing
  - Mark the service as "needs attention" for the content editor
  - NOT block the publication (other targets are live)

#### Scenario: Publication with single target
- GIVEN DienstPublicatie with publicatieDoelen=[website]
- WHEN publication is initiated
- THEN opencatalogi MUST push only to website
- AND MUST advance status to gepubliceerd even if eIDAS-specific targets are not configured

#### Scenario: Retry failed publication target
- GIVEN a service in status=gepubliceerd_met_waarschuwing (your-europe push failed)
- WHEN editor clicks "Retry your-europe sync"
- THEN opencatalogi MUST:
  - Attempt to push to your-europe-API again
  - Log the retry in PublicatieLog with retryAttempt=2
  - Update status to gepubliceerd if successful
  - Keep status=gepubliceerd_met_waarschuwing if retry also fails

#### Scenario: Payload hash validation for idempotent pushes
- GIVEN a published service that was successfully synced to your-europe
- WHEN the service metadata is NOT modified (title, description unchanged)
- AND editor clicks "Force resync to your-europe"
- THEN opencatalogi MUST:
  - Compute payload-hash of serialized metadata
  - Compare to the previous PublicatieLog.payloadHash
  - Recognize payload unchanged
  - Log sync attempt with note "Idempotent resync, no changes"
  - Return HTTP 200 without pushing to your-europe (optimization)

### Requirement: eTranslation integration for 24 EU languages
When a content editor triggers "Auto-translate to EU languages", opencatalogi calls the CEF eTranslation REST API to generate TaalVariant proposals in 24 EU languages. Proposals are marked as "concept" and must be reviewed by a translator before cross-border publication.

#### Scenario: Editor triggers auto-translation workflow
- GIVEN a service with TaalVariant in Dutch (nl)
- WHEN editor clicks "Translate to EU languages" and selects target languages [en, de, fr, it, ...total 12]
- THEN opencatalogi MUST:
  1. Serialize service metadata (titel, beschrijving, procedurestappen)
  2. Call eTranslation API with sourceLanguage=nl, targetLanguages=[selected], domain=publico
  3. Receive async job ID from eTranslation
  4. Poll eTranslation status until all translations are ready
  5. Receive translated text per language

#### Scenario: eTranslation proposal is presented for review
- GIVEN completed eTranslation job with translations in [en, de, fr]
- WHEN translations are ready
- THEN opencatalogi MUST:
  - Create provisional TaalVariant entries with status=concept and vertaalbron=eTranslation
  - Present each translation in a side-by-side review interface
  - Highlight obvious errors (e.g., untranslated tokens, character encoding issues)
  - Allow editor to accept, edit, or reject per language

#### Scenario: Editor accepts eTranslation proposal
- GIVEN a proposed German translation ready for acceptance
- WHEN editor clicks "Approve" for the de variant
- THEN TaalVariant.status MUST change from "concept" to "gepubliceerd"
- AND TaalVariant.vertaalbron MUST remain "eTranslation"
- AND publication to cross-border targets MAY include German variant

#### Scenario: eTranslation failure is handled gracefully
- GIVEN eTranslation API is temporarily unavailable
- WHEN editor triggers auto-translation
- THEN opencatalogi MUST:
  - Catch the API error
  - Notify editor: "Translation service unavailable, please try again later"
  - NOT create any TaalVariant entries
  - Return HTTP 503 or user-friendly error

#### Scenario: Manual translation bypasses eTranslation
- GIVEN a service in concept stage without auto-translations
- WHEN editor manually enters text for a new language (e.g., Polish)
- THEN a new TaalVariant MUST be created with:
  - taal=pl
  - vertaalbron=handmatig
  - status=gepubliceerd (or concept if waiting for review)

### Requirement: Once-Only Principle (OOTS) evidence broker integration
When a cross-border service requires user-supplied documents (e.g., a Polish business registry extract for a Dutch permit application), opencatalogi MUST register the evidence requirements with the OOTS evidence broker. This prevents users from manually uploading documents; instead, the service retrieves evidence cross-border via the OOTS infrastructure.

#### Scenario: Service configured with required evidence
- GIVEN a service: "Polish Business Registration Verification for Dutch Permit"
- WHEN editor sets CrossBorderProces.oatsEvidenceTypes = ["PL/Business_Registry_Extract"]
- AND sets oatsEvidenceBrokerEndpoint = "https://evidence-broker.eu/api/evidence"
- THEN opencatalogi MUST:
  1. Validate evidence type against OOTS registry
  2. Store endpoint and evidence mapping in CrossBorderProces
  3. Generate a pre-filled evidence request form (reference to OOTS)

#### Scenario: User accesses service with evidence requirement
- GIVEN a published cross-border service with OATS configuration
- WHEN an EU user accesses the service application form
- THEN the form MUST:
  - NOT ask user to upload Polish business registry extract
  - Instead, show "Retrieve via OOTS" button
  - Link to evidence broker for cross-border evidence retrieval

#### Scenario: OOTS evidence broker responds with document
- GIVEN OOTS retrieval for Polish registry extract in progress
- WHEN evidence broker delivers the PDF to the service endpoint
- THEN opencatalogi MUST:
  - Receive and validate the evidence against the declared evidence type
  - Attach to user's service application
  - Automatically advance the workflow (no manual intervention)

### Requirement: Quality feedback integration with Your-Europe portal
Periodically, opencatalogi MUST fetch user feedback (ratings, comments, issues) from the Your-Europe portal for each published service. Feedback is displayed to the service owner within opencatalogi for content improvement.

#### Scenario: Your-Europe user rates a service
- GIVEN a published service on Your-Europe portal
- WHEN an end user rates the service (1-5 stars) and leaves a comment: "Procedure unclear"
- THEN the feedback is stored on Your-Europe platform

#### Scenario: Feedback is harvested into opencatalogi
- GIVEN a feedback record on Your-Europe for a published service
- WHEN opencatalogi daily harvest job runs (cron 02:00)
- THEN opencatalogi MUST:
  1. Call Your-Europe feedback-API with filters for this service
  2. Fetch new feedback since last harvest timestamp
  3. Create FeedbackRecord linked to DienstPublicatie
  4. Store rating, comment, user region, timestamp
  5. Mark service owner for notification

#### Scenario: Service owner reviews feedback in opencatalogi
- GIVEN published service with 3 recent feedback items (2 positive, 1 negative)
- WHEN service owner views DienstPublicatie detail page
- THEN a "Feedback" tab MUST show:
  - Average rating (4.3/5)
  - Feedback timeline
  - Sentiment analysis (if available)
  - Links to Your-Europe portal feedback thread
- AND owner MUST be able to add internal notes to feedback items

#### Scenario: Feedback drives content revision
- GIVEN negative feedback: "I cannot find the building permit application form"
- WHEN service owner sees the feedback
- THEN owner can click "Create task from feedback"
- AND this creates a content improvement task linked to the feedback record
- AND the service can be re-published with updated procedure steps once fixed

## Data Model

### DienstPublicatie
Wraps a service with publication metadata:
```
{
  uuid: string,
  serviceDienstUuid: string (FK to Service),
  scope: enum [lokaal, regionaal, nationaal, grensoverschrijdend],
  status: enum [concept, intern, gepubliceerd, ingetrokken],
  publicatieDoelen: array [website, overheid-nl, your-europe, eidas],
  publicatieDatum: datetime (nullable),
  ingetrokkenDatum: datetime (nullable),
  laatsteWijziging: datetime,
}
```

### EidasMetadata
SDG classification and eIDAS requirements:
```
{
  uuid: string,
  dienstPublicatieUuid: string (FK),
  sdgBijlage: enum [I, II, III] (nullable),
  proceduureCategorie: enum [registratie, identificatie, document, betaling] (nullable),
  minimumAssuranceNiveau: enum [laag, substantief, hoog],
  grensoverschrijdend: boolean,
  talen: array [nl, en, de, fr, ... 24 EU languages],
}
```

### SamlMetadata
SAML 2.0 configuration for eIDAS node:
```
{
  uuid: string,
  dienstPublicatieUuid: string (FK),
  spEntityId: string,
  assertionConsumerServiceUrl: string,
  singleLogoutServiceUrl: string,
  signingCertificate: string (PEM),
  encryptionCertificate: string (PEM),
  supportedAttributes: array [BSN, eIDAS-MDS-naturalperson, eIDAS-MDS-legalperson],
  certificateExpiryDate: date,
}
```

### CrossBorderProces
OOTS and service localization:
```
{
  uuid: string,
  dienstPublicatieUuid: string (FK),
  oatsEvidenceTypes: array [EU evidence type URNs],
  oatsEvidenceBrokerEndpoint: string (nullable),
  supportedInputCredentials: array [eIDAS-eID, EUDI-Wallet, ...],
  vertaalStatus: enum [compleet, incompleet, verouderd],
  ondersteuningVia: enum [vertaal-api, extern-loket, menselijk-contact],
}
```

### TaalVariant
Language-specific service description:
```
{
  uuid: string,
  dienstPublicatieUuid: string (FK),
  taal: string (ISO 639-1: nl, en, de, ...),
  titel: string,
  beschrijving: string,
  procedurestappen: array [step objects],
  vertaalbron: enum [handmatig, eTranslation, professioneel],
  status: enum [concept, gepubliceerd],
  aanmaakDatum: datetime,
  goedkeuringsDatum: datetime (nullable),
}
```

### PublicatieLog
Sync history per target:
```
{
  uuid: string,
  dienstPublicatieUuid: string (FK),
  doel: enum [website, overheid-nl, your-europe, eidas],
  payloadHash: string (SHA256 of serialized metadata),
  responseStatus: integer (HTTP status code),
  responseBody: string (truncated, max 1000 chars),
  tijdstip: datetime,
  retryAttempt: integer,
  fouten: array [error detail objects],
}
```

## Standards & Compliance

- **eIDAS Regulation (EU 910/2014)** and eIDAS 2.0 (EUDI Wallet)
- **Single Digital Gateway Regulation (EU 2018/1724)** — SDG Annex classification
- **Once-Only Technical System (EU 2022/1463)** — OOTS evidence broker
- **SAML 2.0 + eIDAS SAML Profile** for federative authentication
- **eIDAS Minimum Data Set (MDS)** for natural and legal person attributes
- **CEF eTranslation API** for machine translation
- **DCAT-AP, ADMS, CPSV-AP** for semantic service description (OASIS Core Public Service Vocabulary)
- **Wet Digitale Overheid (Wdo)** — Dutch eIDAS implementation
- **NORA, DSO, Common Ground** — Dutch interoperability framework

## Success Criteria
- [ ] All 8 requirements have passing test scenarios
- [ ] Services can be published to website without cross-border setup (backward compatibility)
- [ ] SDG classification is enforced for your-europe target
- [ ] English variant is generated or required for cross-border services
- [ ] SAML metadata is publicly accessible and valid per eIDAS profile
- [ ] Publication fan-out completes within 30 seconds for all targets
- [ ] eTranslation integration works for minimum 12 target languages
- [ ] Your-Europe feedback is harvested daily with <4-hour latency
- [ ] All UI workflows tested with personas: redacteur, diensteigenaar, eIDAS-beheerder
