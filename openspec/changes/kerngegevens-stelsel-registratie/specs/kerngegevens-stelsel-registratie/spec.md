---
status: draft
---

# Kerngegevensstelsel Registratie Specification

## Purpose
Defines how opencatalogi datasets are registered within the Dutch government data stelsel hierarchy (basisregistraties, kerngegevens, sectorale registers, derived and local datasets), enabling proper classification, federation with national catalogs, compliance with DCAT-AP-NL 2.1, and support for data freshness tracking and feedback mechanisms.

## Context
Dutch government data governance operates within a five-tier structure: ten statutory basis registers (BRP, BAG, BRT, BRK, NHR, BGT, BRO, BRV, WOZ, BRI) with legal grounding; kerngegevens derived from these registers for use across policy domains; sector-wide registers (healthcare, education, justice, mobility); local derived datasets; and foreign/private data. Each dataset's role within this hierarchy is crucial for reusers to understand authority, currency, feedback routes, and legal compliance (DCAT-AP-NL 2.1, Wet BAG art. 38a, NORA principles). 

Current opencatalogi captures DCAT-AP basics but leaves stelsel positioning implicit in prose descriptions, breaking:
- Automated federation to data.overheid.nl and Stelselcatalogus
- Reliable discovery of source dates, maintenance frequency, and validity windows
- Feedback loops for error reporting (Wet BAG art. 38a)
- Compliance audits for mandatory metadata on public datasets

This spec adds five interconnected metadata schemas (StelselPositie, KerngegevensSet, BijhoudCyclus, Geldigheidsperiode, TerugmeldEndpoint) and integrates them into the dataset publication pipeline, export (DCAT-AP-NL 2.1 RDF/XML and JSON-LD), and federation infrastructure.

**Relation to existing specs:**
- **opencatalogi base**: This extends the Dataset entity with stelsel-specific metadata and validation hooks
- **openregister `register-i18n`**: Schema-field validation for kerngegevens mappings
- **openconnector federation**: Consumes StelselPositie for Stelselcatalogus push targets
- **DCAT-AP-NL 2.1 (Forum Standaardisatie)**: Normative standard for RDF export format and vocabulary URIs
- **Stelselcatalogus (Logius)**: Federation target and vocabulaire authority for kerngegevens

## Requirements

### Requirement: Stelsel position MUST be registered before dataset publication

Every dataset published to a public catalog MUST have a StelselPositie object attached, specifying its role within the government data hierarchy.

#### Scenario: Dataset without position blocks publication
- GIVEN a dataset in `concept` status with no attached StelselPositie
- WHEN the user attempts to change status to `gepubliceerd`
- THEN the system MUST refuse and show a validation error: "Stelselregistratie ontbreekt. Kies of dit een basisregistratie, kerngegeven, sectoraal register, afgeleide of lokale set is."
- AND the UI MUST display a "Configureer stelselregistratie" button linking to the stelsel-wizard

#### Scenario: Dataset with position allows publication
- GIVEN a dataset with a complete StelselPositie object (minimally `positieType` set)
- WHEN the user changes status to `gepubliceerd`
- THEN the system MUST allow publication and record the StelselPositie as published metadata

#### Scenario: StelselPositie GUID is recorded on publish
- GIVEN a StelselPositie with `positieType = kerngegevensstelsel`
- WHEN the dataset is published
- THEN the StelselPositie.aansluitDatum MUST be set to the current date (if not already set)
- AND the association between Dataset and StelselPositie MUST be immutable after publication

### Requirement: Basis register position MUST enforce legal and organizational requirements

Datasets claiming `positieType = basisregistratie` MUST satisfy strict validation for legal basis, source organization, and feedback mechanism.

#### Scenario: Basis register without legal basis is rejected
- GIVEN a StelselPositie with `positieType = basisregistratie` and no `wettelijkeGrondslag`
- WHEN the form is submitted
- THEN the system MUST reject with error: "Wettelijke grondslag is verplicht voor basisregistraties (bijv. 'Wet BRP art. 3')"
- AND the field MUST accept prose (e.g., "Wet BAG art. 31") not just codes

#### Scenario: Basis register name MUST be from official list
- GIVEN a StelselPositie with `positieType = basisregistratie`
- WHEN the user selects `basisregistratieNaam`
- THEN the dropdown MUST show exactly ten options: BRP, BAG, BRT, BRK, NHR, BGT, BRO, BRV, WOZ, BRI
- AND selecting any other value MUST be impossible

#### Scenario: Bronhouder MUST match official organization registry
- GIVEN a StelselPositie with `positieType = basisregistratie` and `basisregistratieNaam = BRP`
- WHEN the user enters a `bronhouder` value
- THEN the system MUST validate against the Register Nederlandse Overheids-organisaties (ROO) API
- AND only KvK numbers recognized as valid government entities MUST be accepted
- AND the response MUST display the official organization name (e.g., "Logius (KvK 34272727)")

#### Scenario: Basis register example — BRP
- GIVEN a dataset published by Logius containing BRP extracts
- WHEN the catalogusbeheerder fills the stelsel-form
- THEN the form MUST accept:
  - `positieType = basisregistratie`
  - `basisregistratieNaam = BRP`
  - `wettelijkeGrondslag = Wet BRP art. 3`
  - `bronhouder = Logius (KvK 34272727)`
  - `gegevensbeheerder = Logius (KvK 34272727)` (typically same)
  - `verstrekker = Logius (KvK 34272727)`
  - `aansluitDatum = 1997-01-01`

### Requirement: Core data sets MUST document kerngegevens mappings

Datasets marked as `kerngegevensstelsel` or `afgeleide-set` MUST define which attributes serve as core data and map them to schema fields.

#### Scenario: Kerngegevens set defines attribute sources
- GIVEN a dataset with `positieType = kerngegevensstelsel` and `gerelateerdeBasisregistratie = BRP`
- WHEN the data-steward creates a KerngegevensSet named "Identificatie-persoonsgegevens"
- THEN the form MUST allow adding one or more `bronAttributen` entries
- AND each entry MUST specify:
  - `pad`: Schema path (e.g., `/bsn`, `/geslachtsnaam`) — validated against openregister schema
  - `label`: Human-readable name (e.g., "BSN", "Geslachtsnaam")
- AND the form MUST reject paths that do not exist in the dataset schema

#### Scenario: Multiple kerngegevens sets per dataset
- GIVEN a dataset representing merged BRP + BAG data
- WHEN the data-steward creates kerngegevens sets
- THEN the system MUST allow multiple KerngegevensSet objects (e.g., "Identificatie", "Vestigingsadres")
- AND each set MUST independently specify `gerelateerdeBasisregistratie` (BRP for one, BAG for the other)
- AND the sets MUST coexist without conflict in the published metadata

#### Scenario: Kerngegevens validation against schema
- GIVEN a KerngegevensSet with `bronAttributen` pointing to path `/nonexistent`
- WHEN the form is submitted
- THEN the system MUST query the openregister API for the dataset schema
- AND MUST reject with error: "Pad '/nonexistent' bestaat niet in het dataset schema"
- AND MUST display a list of valid paths for autocomplete

### Requirement: Data maintenance frequency MUST be tracked and expose a freshness indicator

Every dataset MUST record its maintenance cycle and expose a freshness status (green/orange/red) based on SLA compliance.

#### Scenario: Maintenance frequency options
- GIVEN the BijhoudCyclus form
- WHEN the user opens the `bijhoudFrequentie` dropdown
- THEN the system MUST show: realtime, dagelijks, wekelijks, maandelijks, kwartaal, jaarlijks, event-driven

#### Scenario: Freshness indicator calculation
- GIVEN a dataset with:
  - `bijhoudFrequentie = wekelijks` (weekly)
  - `laatsteUpdate = 2026-05-15T10:00Z` (one week ago)
- WHEN the dataset is viewed today (2026-05-22)
- THEN the actualiteit-indicator MUST show GREEN (within SLA)
- AND the label MUST read "Actueel (bijgewerkt 7 dagen geleden)"

#### Scenario: Orange indicator for stale data
- GIVEN a dataset with:
  - `bijhoudFrequentie = wekelijks`
  - `laatsteUpdate = 2026-05-08T10:00Z` (9 days ago, beyond the 1-week window)
- WHEN the dataset is viewed on 2026-05-22
- THEN the actualiteit-indicator MUST show ORANGE (approaching SLA breach)
- AND the label MUST read "Verouderd (bijgewerkt 14 dagen geleden)"

#### Scenario: Red indicator for broken SLA
- GIVEN a dataset with:
  - `bijhoudFrequentie = dagelijks`
  - `laatsteUpdate = 2026-05-10T08:00Z` (12 days without update)
- WHEN the dataset is viewed on 2026-05-22
- THEN the actualiteit-indicator MUST show RED (SLA breached)
- AND the label MUST read "Kritiek (niet bijgewerkt sinds 12 dagen)"
- AND an alert MUST suggest contacting the data steward

#### Scenario: Realtime datasets never show orange/red
- GIVEN a dataset with `bijhoudFrequentie = realtime` and `laatsteUpdate` from 6 months ago
- WHEN the dataset is viewed
- THEN the actualiteit-indicator MUST remain GREEN
- AND the label MUST read "Realtime (geen update-SLA)"

#### Scenario: DCAT export includes freshness status
- GIVEN a dataset with freshness indicator GREEN
- WHEN the DCAT-AP-NL 2.1 export is generated
- THEN the RDF MUST include:
  - `dct:modified` = the `laatsteUpdate` timestamp
  - Custom property `dn:bijhoudStatus = "groen"` (or "oranje"/"rood")
  - Vocabulaire URI for status: `http://data.overheid.nl/vocab/bijhoudstatus/groen`

### Requirement: Dataset validity windows MUST support historical snapshots

Datasets with temporal scope MUST record validity periods and enable access to historical versions.

#### Scenario: Open-ended validity (current data)
- GIVEN a dataset with `Geldigheidsperiode.geldigVanaf = 2010-01-01` and `geldigTotEnMet = null`
- WHEN the dataset is viewed
- THEN the UI MUST display "Geldig vanaf 1 januari 2010 (huidig)"
- AND no historical versions selector MUST appear

#### Scenario: Closed validity period (historical snapshot)
- GIVEN a dataset with `Geldigheidsperiode.geldigVanaf = 1997-01-01` and `geldigTotEnMet = 2009-12-31`
- WHEN the dataset is viewed
- THEN the UI MUST display "Geldig van 1 januari 1997 tot 31 december 2009 (historisch)"
- AND the historical-versions list MUST be visible

#### Scenario: Snapshot creation on validity close
- GIVEN a dataset currently in Geldigheidsperiode with `geldigTotEnMet = null` (open)
- WHEN a new Geldigheidsperiode is created with a start date
- THEN the system MUST automatically:
  1. Set the OLD period's `geldigTotEnMet` to yesterday
  2. Create an immutable snapshot of the current dataset state
  3. Assign the snapshot a version URI (e.g., `/datasets/{id}/versions/2010-01-01`)
  4. Add the snapshot reference to the OLD period's `historischeVersies` array
  5. Create a NEW Geldigheidsperiode with the new start date and `geldigTotEnMet = null`

#### Scenario: Reuser queries historical data on a reference date
- GIVEN a dataset with multiple Geldigheidsperioden (snapshots at 1997, 2010, 2024)
- WHEN a reuser wants to know "which version was current on 2012-06-15?"
- THEN the API MUST accept `?peildatum=2012-06-15` and return the snapshot valid on that date
- AND the response MUST include a `_snapshot: true` flag and `_validFrom` / `_validUntil` metadata

### Requirement: Feedback mechanism MUST be registered for basis registers and core data

Datasets claiming mandatory status (`basisregistratie`, `kerngegevensstelsel`) MUST have a TerugmeldEndpoint registered.

#### Scenario: Basis register publication requires feedback endpoint
- GIVEN a dataset with `positieType = basisregistratie`
- WHEN the user attempts to publish
- THEN the system MUST check for an attached TerugmeldEndpoint
- AND if missing, MUST reject with error: "Terugmeldendpoint is verplicht voor basisregistraties (Wet BAG art. 38a e.d.)"

#### Scenario: Feedback endpoint validation
- GIVEN a TerugmeldEndpoint form
- WHEN the user enters `terugmeldUrl = https://invalid-url`
- THEN the system MUST validate the URL is a valid HTTPS URI
- AND MUST reject with error if the protocol is not HTTPS

#### Scenario: Feedback protocols
- GIVEN the `terugmeldProtocol` dropdown
- WHEN the user clicks
- THEN the system MUST show three options:
  - `digikoppeling-ebms`: For government-to-government (G2G) feedback
  - `rest-json`: For HTTP REST API endpoints
  - `nlx`: For NLX-routed feedback

#### Scenario: Feedback button on dataset detail page
- GIVEN a published dataset with `positieType = basisregistratie` and a TerugmeldEndpoint configured
- WHEN a visitor views the public dataset detail page
- THEN a prominent "Onjuist gegeven melden" (Report inaccuracy) button MUST be visible
- AND clicking the button MUST:
  - Open a feedback form with fields for dataset identifier, error description, source
  - Pre-fill the `terugmeldUrl` from the TerugmeldEndpoint
  - Route submission via the configured protocol (Digikoppeling, REST, or NLX)
  - Display confirmation: "Bedankt. Uw melding wordt verwerkt binnen {responsTermijn}"

#### Scenario: Feedback endpoint example — BRP
- GIVEN a BRP dataset published by Logius
- WHEN the data-steward configures the TerugmeldEndpoint
- THEN the form MUST accept:
  - `terugmeldUrl = https://logius.nl/terugmelding-api/brp`
  - `terugmeldProtocol = digikoppeling-ebms`
  - `responsTermijn = 5 werkdagen`
  - `contactgegevens = terugmelding@logius.nl`

### Requirement: DCAT-AP-NL 2.1 export MUST include stelsel metadata with proper vocabularies

The DCAT export pipeline MUST translate all StelselPositie, KerngegevensSet, BijhoudCyclus, Geldigheidsperiode, and TerugmeldEndpoint metadata into valid DCAT-AP-NL 2.1 RDF/XML and JSON-LD with proper vocabulary URIs.

#### Scenario: DCAT export includes stelsel position
- GIVEN a published dataset with `StelselPositie.positieType = kerngegevensstelsel`
- WHEN the DCAT-AP-NL 2.1 RDF/XML feed is exported
- THEN the RDF MUST include a property mapping the position to a vocabulary URI:
  - Property: `dcat:theme` or custom `dn:stelselPositie`
  - Value: `http://data.overheid.nl/vocab/stelselPositie/kerngegevensstelsel`

#### Scenario: DCAT export includes basis register metadata
- GIVEN a published dataset with `StelselPositie.positieType = basisregistratie` and `basisregistratieNaam = BRP`
- WHEN the DCAT-AP-NL 2.1 export is generated
- THEN the RDF MUST include:
  - `dn:basisregistratie = http://data.overheid.nl/vocab/basisregistraties/brp`
  - `dct:issued` or equivalent for `aansluitDatum`
  - `dn:bronhouder` with organization IRI (from ROO)
  - `dn:wettelijkeGrondslag` as literal text

#### Scenario: DCAT export includes feedback endpoint
- GIVEN a TerugmeldEndpoint with `terugmeldUrl = https://logius.nl/terugmelding`
- WHEN the DCAT-AP-NL 2.1 export is generated
- THEN the RDF MUST include:
  - `dn:terugmeldEndpoint = <https://logius.nl/terugmelding>`
  - `dn:terugmeldProtocol = "digikoppeling-ebms"` (as typed literal or vocabulaire reference)

#### Scenario: DCAT export includes kerngegevens mappings
- GIVEN a dataset with KerngegevensSet "Identificatie-persoonsgegevens" with `bronAttributen = [{pad: "/bsn", label: "BSN"}]`
- WHEN the DCAT-AP-NL 2.1 JSON-LD export is generated
- THEN the JSON-LD MUST include a `dn:kerngegevens` array with objects:
  ```json
  {
    "@id": "http://data.overheid.nl/kerngegevens/identificatie-persoonsgegevens",
    "rdfs:label": "Identificatie-persoonsgegevens",
    "dn:bronAttributen": [
      {
        "dn:pad": "/bsn",
        "rdfs:label": "BSN"
      }
    ],
    "dn:gerelateerdeBasisregistratie": "http://data.overheid.nl/vocab/basisregistraties/brp"
  }
  ```

#### Scenario: DCAT Turtle and RDF/XML are both available
- GIVEN a dataset with complete stelsel metadata
- WHEN a harvester requests the DCAT feed in different formats
- THEN `GET /api/dcat.rdf` MUST return valid RDF/XML (Content-Type: `application/rdf+xml`)
- AND `GET /api/dcat.jsonld` MUST return valid JSON-LD (Content-Type: `application/ld+json`)
- AND both representations MUST be lossless (no vocabulaire terms omitted)

### Requirement: Federation to Stelselcatalogus MUST push core data automatically

The openconnector federation job MUST collect all datasets with `positieType = kerngegevensstelsel` and push them to the Stelselcatalogus API daily.

#### Scenario: Federation job collects and transforms data
- GIVEN opencatalogi instances configured to federate to Stelselcatalogus
- WHEN the federation job runs (daily)
- THEN the job MUST:
  1. Query all published datasets with `StelselPositie.positieType = kerngegevensstelsel`
  2. For each dataset, construct a Stelselcatalogus API payload with `StelselPositie` + `KerngegevensSet` data
  3. Transform stelsel vocabulaires to Stelselcatalogus-expected URIs (e.g., `dn:stelselPositie/kerngegevensstelsel` → `https://stelselcatalogus.logius.nl/kerngegevens/kerngegevensstelsel`)
  4. POST to `https://api.stelselcatalogus.logius.nl/v1/kerngegevens` with authorization credentials
  5. Log success or failure for each dataset

#### Scenario: Federation push includes metadata validation
- GIVEN a dataset with incomplete `KerngegevensSet` (missing required attributes)
- WHEN the federation job attempts to push
- THEN the job MUST validate all required fields before attempting push
- AND if validation fails, MUST log the error and mark the dataset as "pending manual review"
- AND the catalogusbeheerder MUST receive a notification: "Dataset {id} could not be federated to Stelselcatalogus — please review kerngegevens mappings"

#### Scenario: Federation error handling
- GIVEN the Stelselcatalogus API is temporarily unavailable
- WHEN the federation job calls the API
- THEN the job MUST:
  - Capture the HTTP error (5xx)
  - Log the incident (timestamp, dataset ID, error)
  - Retry up to 3 times with exponential backoff
  - If all retries fail, record the open finding and skip to next dataset
  - Do NOT block other datasets from pushing

### Requirement: UI MUST provide stelsel-position wizard on dataset creation

The dataset creation / publication flow MUST include a guided wizard for StelselPositie configuration.

#### Scenario: Stelsel wizard appears on first publish attempt
- GIVEN a user creating a new dataset with status `concept`
- WHEN they click "Publiceren" (Publish)
- THEN if no StelselPositie exists, a modal MUST open: "Wat is de stelselposition van deze dataset?"
- AND the modal MUST show five radio buttons for position types:
  - "Basisregistratie (wettelijk, m.a.w. één van: BRP, BAG, etc.)"
  - "Kerngegevensstelsel (afgeleide kerngegevens uit basisregistraties)"
  - "Sectorbreed register (domeinspecifiek zoals zorg, onderwijs)"
  - "Afgeleide set (bewerking van andere dataset)"
  - "Lokale registratie (alleen voor deze gemeente/provincie)"

#### Scenario: Basis register branch of wizard
- GIVEN the user selects "Basisregistratie"
- WHEN they click "Volgende"
- THEN the wizard MUST show:
  - "Welke basisregistratie?" dropdown (BRP, BAG, BRT, BRK, NHR, BGT, BRO, BRV, WOZ, BRI)
  - "Wettelijke grondslag" text field (e.g., "Wet BRP art. 3")
  - "Bronhouder KvK-nummer" field with ROO validation
  - "Terugmeldendpoint (verplicht voor basis registers)" section with URL + protocol + SLA

#### Scenario: Kerngegevens branch of wizard
- GIVEN the user selects "Kerngegevensstelsel"
- WHEN they click "Volgende"
- THEN the wizard MUST show:
  - "Gerelateerde basisregistratie" dropdown (BRP, BAG, etc.)
  - "Kerngegevenssетten" builder: "Voeg een kerngegevensset toe"
  - For each set: "Naam", "Bronattributen" schema-field mapper
  - Maintenance cycle section: "Bijhoudfrequentie", "Laatste update", "Volgende geplande update"

#### Scenario: Wizard saves StelselPositie and returns to dataset
- GIVEN the user completes the wizard form with all required fields
- WHEN they click "Opslaan" (Save)
- THEN the system MUST:
  1. Create or update the StelselPositie object
  2. Validate all fields
  3. Close the modal
  4. Re-display the dataset with stelsel metadata visible
  5. Allow the user to click "Publiceren" again without being prompted

### Requirement: Cross-app integrations MUST be documented and validated

The five new schemas interact with existing opencatalogi, openregister, openconnector, softwarecatalog, and mydash infrastructure. These integration points MUST be tested and documented.

#### Scenario: Schema validation against openregister
- GIVEN a KerngegevensSet with `bronAttributen` referencing paths like `/bsn`, `/geslachtsnaam`
- WHEN the form is submitted
- THEN the system MUST call the openregister `ObjectService` API to fetch the dataset schema
- AND MUST validate each path exists in the schema
- AND MUST display a helpful error if paths are invalid

#### Scenario: Organization lookup from softwarecatalog
- GIVEN the user entering a bronhouder KvK
- WHEN the field loses focus
- THEN the system MUST call softwarecatalog's organization lookup API
- AND MUST resolve the KvK to an official name (e.g., "Logius")
- AND MUST display the resolved name below the input field

#### Scenario: Actualiteit dashboard in mydash
- GIVEN the mydash actualiteit-dashboard configured for a catalogusbeheerder
- WHEN the dashboard loads
- THEN mydash MUST query all datasets in the catalog and their `BijhoudCyclus` metadata
- AND MUST display a heatmap showing:
  - Green cells: datasets with freshness GREEN (within SLA)
  - Orange cells: datasets with freshness ORANGE (within 50% SLA overrun)
  - Red cells: datasets with freshness RED (SLA breached)
- AND MUST allow drilling down into RED datasets to contact the data steward

## Standards and Compliance

- **DCAT-AP-NL 2.1** (Forum Standaardisatie, pass-toe-of-leg-uit-verplicht)
- **NL GOV Profile of DCAT-AP** (v2.1)
- **Stelselcatalogus vocabulaire** (Logius, kerngegevens URIs)
- **Wet BRP, Wet BAG, etc.** (wettelijke grondslag, artikel 38a feedback)
- **Terugmeldvoorziening richtlijnen** (Logius)
- **NORA** (Nederlandse Overheid Referentie Architectuur)
- **Digikoppeling** (ebMS 2.0 profile for G2G feedback)
- **NLX** (Nederlandse logistieke uitwisseling, optional transport)
- **W3C DCAT 3.0** (base vocabulaire)
- **Register Nederlandse Overheids-organisaties (ROO)** (organization reference data, Logius)
