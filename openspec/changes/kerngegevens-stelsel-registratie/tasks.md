# Tasks: kerngegevens-stelsel-registratie

## Task 1: Data model and schema registration
- **Spec ref**: specs/kerngegevens-stelsel-registratie/spec.md (Requirements 1-5)
- **Status**: todo
- **Description**: Register the five new OpenRegister schemas: StelselPositie, KerngegevensSet, BijhoudCyclus, Geldigheidsperiode, TerugmeldEndpoint. Include enum validators, relationship definitions (datasetId foreign key), and seed data examples.
- **Acceptance criteria**: 
  - [ ] All five schemas registered in openregister `registers/opencatalogi-stelsel.register.yaml`
  - [ ] StelselPositie.positieType enum: basisregistratie / kerngegevensstelsel / sectorbreed-register / afgeleide-set / lokale-registratie
  - [ ] StelselPositie.basisregistratieNaam enum locked to ten official basis registers (BRP, BAG, BRT, BRK, NHR, BGT, BRO, BRV, WOZ, BRI)
  - [ ] KerngegevensSet.bronAttributen allows array of {pad: string, label: string}
  - [ ] BijhoudCyclus.bijhoudFrequentie enum: realtime / dagelijks / wekelijks / maandelijks / kwartaal / jaarlijks / event-driven
  - [ ] TerugmeldEndpoint.terugmeldProtocol enum: digikoppeling-ebms / rest-json / nlx
  - [ ] All schemas include dct:description metadata (Dutch + English)
  - [ ] Seed data (3-5 example objects per schema) present in documentation

## Task 2: Publication validation pipeline (dataset → stelselregistratie check)
- **Spec ref**: specs/kerngegevens-stelsel-registratie/spec.md (Requirement 1)
- **Status**: todo
- **Description**: Extend opencatalogi's dataset publication workflow to enforce StelselPositie presence. Add a pre-publish validation hook that checks for attached StelselPositie and shows a wizard if missing.
- **Acceptance criteria**:
  - [ ] Publication action checks for attached StelselPositie before allowing status transition to `gepubliceerd`
  - [ ] If missing, publication is blocked with user-facing error message
  - [ ] Error message includes a "Configureer stelselregistratie" button that opens the stelsel-wizard modal
  - [ ] Wizard modal component created at `src/views/DatasetPublicationWizard.vue`
  - [ ] POST handler saves StelselPositie and updates dataset status in one transaction
  - [ ] Unit tests: dataset without position, dataset with partial position (missing required fields), dataset with valid position

## Task 3: Basis register validation and ROO lookup
- **Spec ref**: specs/kerngegevens-stelsel-registratie/spec.md (Requirement 2)
- **Status**: todo
- **Description**: Implement strict validation for `positieType = basisregistratie`: enforce `basisregistratieNaam` from official list, validate `wettelijkeGrondslag` format, and validate `bronhouder` KvK against the Register Nederlandse Overheids-organisaties (ROO).
- **Acceptance criteria**:
  - [ ] `basisregistratieNaam` dropdown shows exactly ten official names, no custom input
  - [ ] `wettelijkeGrondslag` field accepts prose (e.g., "Wet BRP art. 3") and is required for basisregistratie type
  - [ ] `bronhouder` KvK lookup calls ROO API (Logius) and validates only government entities
  - [ ] Resolved organization name displayed below KvK field
  - [ ] Form rejects invalid KvK numbers with error message
  - [ ] Unit tests: invalid KvK, valid KvK (Logius), non-government KvK, network timeout handling

## Task 4: Kerngegevens set builder with schema validation
- **Spec ref**: specs/kerngegevens-stelsel-registratie/spec.md (Requirement 3)
- **Status**: todo
- **Description**: Build UI component for KerngegevensSet definition, allowing data-stewards to map dataset schema fields to kerngegevens attributes. Integrate with openregister to validate field paths against dataset schema.
- **Acceptance criteria**:
  - [ ] KerngegevensSet list view shows all sets for a dataset (create/edit/delete)
  - [ ] "Create kerngegevens set" form with fields: setNaam, gerelateerdeBasisregistratie, bronAttributen
  - [ ] `gerelateerdeBasisregistratie` dropdown shows all ten basis registers
  - [ ] bronAttributen builder allows adding/removing attribute mappings
  - [ ] Per-attribute form: pad (string, validated), label (string, required)
  - [ ] Path validation calls openregister ObjectService to fetch dataset schema
  - [ ] Autocomplete suggestions for valid paths based on schema
  - [ ] Error handling for schema fetch failures or missing schema
  - [ ] Multiple sets per dataset supported (no conflict)
  - [ ] Unit tests: valid paths, invalid paths, schema not found, multiple sets

## Task 5: Maintenance cycle (BijhoudCyclus) tracker and freshness indicator
- **Spec ref**: specs/kerngegevens-stelsel-registratie/spec.md (Requirement 4)
- **Status**: todo
- **Description**: Implement BijhoudCyclus data entry and calculate actualiteit (freshness) indicator based on `bijhoudFrequentie` vs. `laatsteUpdate`. Expose indicator on dataset detail page and in DCAT export.
- **Acceptance criteria**:
  - [ ] BijhoudCyclus form with fields: bijhoudFrequentie (enum), bijhoudBron, laatsteUpdate, volgendeGeplandeUpdate, peildatum
  - [ ] Freshness indicator calculation: compare `laatsteUpdate` against `bijhoudFrequentie` window
  - [ ] Indicators: GREEN (within SLA), ORANGE (>66% overdue), RED (>100% overdue), or always GREEN for realtime
  - [ ] Indicator displayed on dataset detail page with tooltip showing SLA status
  - [ ] Hourly background job updates freshness indicators for all datasets
  - [ ] DCAT export includes `dn:bijhoudStatus` with vocabulaire URI (`http://data.overheid.nl/vocab/bijhoudstatus/{groen|oranje|rood}`)
  - [ ] Unit tests: within-SLA, approaching-SLA, SLA-breached, realtime, no bijhoudCyclus

## Task 6: Validity period (Geldigheidsperiode) and historical snapshots
- **Spec ref**: specs/kerngegevens-stelsel-registratie/spec.md (Requirement 5)
- **Status**: todo
- **Description**: Manage dataset temporal scope and enable snapshot creation when validity period closes. Support reuser queries for historical data via peildatum parameter.
- **Acceptance criteria**:
  - [ ] Geldigheidsperiode form with fields: geldigVanaf, geldigTotEnMet (nullable), historischeVersies (read-only array)
  - [ ] When new period is created (old period is closed):
    - [ ] System automatically sets old period's `geldigTotEnMet` to yesterday
    - [ ] Immutable snapshot created and stored with version URI (e.g., `/datasets/{id}/versions/2010-01-01`)
    - [ ] Snapshot reference added to old period's `historischeVersies`
    - [ ] New period created with provided start date and `geldigTotEnMet = null`
  - [ ] Dataset detail page shows validity label: "Geldig vanaf ... tot ..." or "Geldig vanaf ... (huidig)"
  - [ ] Historical versions list shown for closed periods
  - [ ] API endpoint supports `?peildatum=YYYY-MM-DD` to retrieve snapshot valid on date
  - [ ] API response includes `_snapshot: true`, `_validFrom`, `_validUntil` metadata
  - [ ] Unit tests: open period, closed period, snapshot creation, peildatum query, multiple versions

## Task 7: Terugmeld endpoint configuration and feedback button
- **Spec ref**: specs/kerngegevens-stelsel-registratie/spec.md (Requirement 6)
- **Status**: todo
- **Description**: Register TerugmeldEndpoint for datasets (mandatory for basisregistratie), validate protocol and SLA, and display "Onjuist gegeven melden" feedback button on dataset detail pages with protocol-aware submission routing.
- **Acceptance criteria**:
  - [ ] TerugmeldEndpoint form with fields: terugmeldUrl (HTTPS URI validation), terugmeldProtocol (enum), responsTermijn (string), contactgegevens (string)
  - [ ] TerugmeldEndpoint is mandatory for `positieType = basisregistratie`, optional for others
  - [ ] Publication validation rejects basisregistratie without terugmeldendpoint
  - [ ] "Onjuist gegeven melden" button displayed on dataset detail page if endpoint configured
  - [ ] Feedback modal form with fields: dataset-identifier, error-description, source, attachments (optional)
  - [ ] Form submission routes via terugmeldProtocol:
    - [ ] digikoppeling-ebms: POST to terugmeldUrl with Digikoppeling envelope (ebMS 2.0)
    - [ ] rest-json: POST to terugmeldUrl with JSON body
    - [ ] nlx: POST to terugmeldUrl via NLX gateway
  - [ ] Confirmation message displays SLA: "Bedankt. Uw melding wordt verwerkt binnen {responsTermijn}"
  - [ ] Error handling for unreachable endpoints (5xx, timeout) with user-facing message
  - [ ] Unit tests: missing endpoint, invalid URL, each protocol type, network errors

## Task 8: DCAT-AP-NL 2.1 export with stelsel vocabulaires
- **Spec ref**: specs/kerngegevens-stelsel-registratie/spec.md (Requirement 7)
- **Status**: todo
- **Description**: Extend opencatalogi DCAT export to serialize StelselPositie, KerngegevensSet, BijhoudCyclus, Geldigheidsperiode, and TerugmeldEndpoint into DCAT-AP-NL 2.1 RDF/XML and JSON-LD with proper vocabulaire URIs and Forum Standaardisatie compliance.
- **Acceptance criteria**:
  - [ ] DCAT RDF/XML export includes `dcat:theme` or `dn:stelselPositie` with vocabulaire URI for position type
  - [ ] DCAT RDF/XML includes `dn:basisregistratie` URI for basis register type
  - [ ] DCAT RDF/XML includes `dn:bronhouder`, `dn:wettelijkeGrondslag`, `dn:gegevensbeheerder`, `dn:verstrekker` properties
  - [ ] DCAT JSON-LD export includes `dn:kerngegevens` array with kerngegevens set details
  - [ ] Each kerngegevens set serialized with `rdfs:label`, `dn:bronAttributen` (array of path/label), `dn:gerelateerdeBasisregistratie`
  - [ ] DCAT export includes `dct:issued` (aansluitDatum) for basisregistratie
  - [ ] DCAT export includes `dn:bijhoudStatus` with vocabulaire URI for freshness indicator
  - [ ] DCAT export includes `dn:terugmeldEndpoint` with URL and protocol
  - [ ] DCAT export includes `dcat:temporalResolution` or `dn:geldigheid` for validity periods
  - [ ] Vocabulaire base URIs: `http://data.overheid.nl/vocab/`
  - [ ] GET `/api/dcat.rdf` returns RDF/XML, GET `/api/dcat.jsonld` returns JSON-LD, both lossless
  - [ ] Unit tests: all entity types, RDF/XML validity, JSON-LD validity, missing optional fields, vocabulaire URIs

## Task 9: Federation to Stelselcatalogus (OpenConnector integration)
- **Spec ref**: specs/kerngegevens-stelsel-registratie/spec.md (Requirement 8)
- **Status**: todo
- **Description**: Integrate with openconnector to collect all datasets with `positieType = kerngegevensstelsel` and push them to the Stelselcatalogus API (Logius) daily. Include transformation of vocabulaires and error handling.
- **Acceptance criteria**:
  - [ ] Federation job defined in openconnector with schedule: daily (configurable)
  - [ ] Job queries opencatalogi for published datasets with `StelselPositie.positieType = kerngegevensstelsel`
  - [ ] For each dataset, construct Stelselcatalogus API payload:
    - [ ] StelselPositie data (positieType, kerngegevens relation, dates)
    - [ ] KerngegevensSet data (setNaam, bronAttributen, gerelateerdeBasisregistratie)
    - [ ] Transform vocaulaires to Stelselcatalogus-expected URIs
  - [ ] POST each payload to `https://api.stelselcatalogus.logius.nl/v1/kerngegevens` with configured API key
  - [ ] Validation before push: check required fields, validate relationships
  - [ ] Error handling: log validation errors, retry 3x with exponential backoff for network errors
  - [ ] On error: record as open finding, skip dataset, continue with others
  - [ ] Catalogusbeheerder notification on validation error: "Dataset {id} could not be federated — review kerngegevens"
  - [ ] Job logs success/failure per dataset (structured logs: timestamp, dataset-id, status, error-message)
  - [ ] Unit tests: all validations, successful push, API errors, network timeout, payload transformation

## Task 10: Stelsel-position wizard UI
- **Spec ref**: specs/kerngegevens-stelsel-registratie/spec.md (Requirement 9)
- **Status**: todo
- **Description**: Build multi-step guided wizard for StelselPositie configuration, triggered on first publish attempt. Include conditional form branches for each position type.
- **Acceptance criteria**:
  - [ ] Wizard modal triggered when dataset lacks StelselPositie on publish attempt
  - [ ] Step 1: "Wat is de stelselposition?" with 5 radio buttons and descriptions
  - [ ] Step 2 (basisregistratie branch):
    - [ ] "Welke basisregistratie?" dropdown (10 official names)
    - [ ] "Wettelijke grondslag" text field
    - [ ] "Bronhouder KvK" field with ROO validation and name resolution
    - [ ] "Terugmeldendpoint" section (URL + protocol + SLA)
  - [ ] Step 2 (kerngegevensstelsel branch):
    - [ ] "Gerelateerde basisregistratie" dropdown (10 options)
    - [ ] Kerngegevensset builder
    - [ ] Maintenance cycle (bijhoudFrequentie, lastUpdate, nextUpdate)
  - [ ] Step 2 (sectorbreed-register branch):
    - [ ] "Sectordomein" field (healthcare, education, justice, mobility, other)
    - [ ] Optional kerngegevens sets
  - [ ] Step 2 (afgeleide-set branch):
    - [ ] "Afleidingslogica" text field (prose description)
  - [ ] Step 2 (lokale-registratie branch):
    - [ ] (No additional required fields; simple confirmation)
  - [ ] "Opslaan" button validates all fields, creates/updates StelselPositie, closes wizard, returns to dataset view
  - [ ] "Annuleren" button closes wizard without saving
  - [ ] Wizard progress indicator showing current step
  - [ ] Form validation: red borders on required fields, inline error messages
  - [ ] Unit tests: all branches, validation, save, cancel, missing required fields

## Task 11: Actualiteit dashboard integration (MyDash)
- **Spec ref**: specs/kerngegevens-stelsel-registratie/spec.md (Requirement 10)
- **Status**: todo
- **Description**: Integrate with mydash to display a heatmap dashboard of dataset freshness by stelsel position, allowing catalogusbeheerders to monitor SLA compliance across the catalog.
- **Acceptance criteria**:
  - [ ] Dashboard widget queryable from mydash: "Actualiteit per stelselposition"
  - [ ] Widget queries opencatalogi for all published datasets and their BijhoudCyclus + freshness
  - [ ] Heatmap display: rows = stelsel positions, columns = time periods (weekly/monthly)
  - [ ] Cell colors: GREEN (SLA met), ORANGE (approaching breach), RED (breached)
  - [ ] Cell tooltip shows dataset count, update count, SLA status
  - [ ] Drill-down: clicking RED cells shows list of breached datasets with data-steward contact
  - [ ] Filter by catalog, date range
  - [ ] Export to CSV with dataset-id, position-type, last-update, freshness-status
  - [ ] Unit tests: data aggregation, color assignment, drill-down data accuracy

## Task 12: Cross-app integration tests and documentation
- **Spec ref**: specs/kerngegevens-stelsel-registratie/spec.md (Requirement 11)
- **Status**: todo
- **Description**: Validate integration with openregister, softwarecatalog, openconnector, mydash. Document integration points and APIs. Create end-to-end tests.
- **Acceptance criteria**:
  - [ ] OpenRegister integration docs: schema validation API, error handling, timeouts
  - [ ] SoftwareCatalog integration docs: organization lookup, KvK resolution
  - [ ] OpenConnector federation docs: Stelselcatalogus API format, request/response examples, error codes
  - [ ] MyDash integration docs: dashboard query format, heatmap data structure
  - [ ] End-to-end test: create dataset → set position → publish → verify DCAT export → verify federation push
  - [ ] Integration test: ROO lookup with invalid KvK → softwarecatalog fallback
  - [ ] Integration test: schema validation with missing schema → graceful degradation
  - [ ] Timeout handling tests: all external APIs (ROO, Stelselcatalogus, SoftwareCatalog)
  - [ ] Updated CLAUDE.md with stelselregistratie-specific instructions (l10n keys, validation patterns)
  - [ ] API documentation: POST /stelselPositie, GET /stelselPositie/{id}, etc.
