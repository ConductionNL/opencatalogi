# Design: kerngegevens-stelsel-registratie

## Architecture Overview

This spec adds a comprehensive stelselregistratie layer to opencatalogi datasets, enabling structured classification and federation of datasets within the Dutch government data governance structure.

### Core Entities

**StelselPositie** registers the systemic role of a dataset:
- Maps datasets to one of five position types: basisregistratie (statutory), kerngegevensstelsel (core data), sectorbreed-register (sector-specific), afgeleide-set (derived), lokale-registratie (local)
- For basis registers: enforces legal grounding, source organization identity (KvK), and enlistment date
- Anchors all other metadata (kerngegevens, maintenance, validity, feedback)

**KerngegevensSet** documents core data derivations:
- Identifies which attributes within a dataset serve as "kerngegevens" (core data used by other policy areas)
- Maps attribute references to actual dataset schema fields (validated against openregister schema)
- Traces back to source basis register (e.g., "Identificatie-attributen" → BRP)

**BijhoudCyclus** tracks data freshness:
- Records maintenance frequency (realtime, daily, weekly, monthly, quarterly, yearly, event-driven)
- Stores system source, last update timestamp, next planned update, and (for snapshots) reference date
- Powers actualiteit-indicator (green/orange/red) showing whether data meets SLA

**Geldigheidsperiode** manages temporal scope:
- Marks valid-from and valid-through dates for datasets with temporal bounds
- Stores immutable snapshots of prior dataset versions as historical records
- Enables reusers to query: "what version was current on 2024-01-15?"

**TerugmeldEndpoint** enables feedback loops:
- Registers the feedback submission endpoint (URI) for basis registers and core data
- Specifies protocol (Digikoppeling ebMS, REST-JSON, NLX)
- Records response SLA and contact details
- Mandatory for datasets claiming basisregistratie status (required by Wet BAG art. 38a analog)

### Integration Points

- **OpenRegister** (`openregister` app): Schema validation for `bronAttributen` field mappings; reuses schema-declarative-business-logic pattern (ADR-031)
- **OpenCatalogi core**: Extends existing Dataset entity; DCAT-AP-NL 2.1 export hooks; publication validation pipeline
- **OpenConnector** (`openconnector` app): Federation sources for Stelselcatalogus push; harvest jobs; vocab mapping
- **SoftwareCatalog** (`softwarecatalog` app): Organization reference resolution (KvK ↔ name); verstrekker (distributor) lookup
- **MyDash** (`mydash` app): Actualiteit dashboard per stelsel position; heat map of SLA adherence
- **DocuDesk** (`docudesk` app): Storage of legal-basis PDFs, source-organization agreements

### Data Flow

1. **Catalogusbeheerder** creates Dataset and clicks "Publish"
2. **Validation hook** checks for attached StelselPositie; if missing, shows wizard
3. **Catalogusbeheerder** selects `positieType`; UI conditionally shows schema based on type:
   - **basisregistratie**: Demands legal-basis text, bronhouder KvK (validated against ROO), terugmeld-endpoint, wettelijkeGrondslag
   - **kerngegevensstelsel** / **afgeleide-set**: Offer KerngegevensSet builder to map schema fields to kerngegevens
   - **All types**: BijhoudCyclus (frequency, last-update, next-update) and Geldigheidsperiode optional
4. **Backend** validates all fields; on publish, records aansluitDatum (now)
5. **Export pipeline** translates StelselPositie + KerngegevensSet into DCAT-AP-NL 2.1 RDF/XML with proper vocabulary URIs
6. **OpenConnector federation job** (daily) collects all datasets with `positieType = kerngegevensstelsel`, pushes via Stelselcatalogus API
7. **Actualiteit monitor** (hourly) checks `BijhoudCyclus.laatsteUpdate` against `bijhoudFrequentie`; updates indicator
8. **Reuser** (data journalist, developer) sees on Dataset detail page:
   - Stelsel position label (e.g. "Kerngegevensstelsel")
   - Last-update + freshness indicator
   - Link to historical versions (if Geldigheidsperiode exists)
   - Terugmeld button (if TerugmeldEndpoint configured)

### Seed Data (Examples)

**StelselPositie examples:**
- BRP extract: `positieType=basisregistratie`, `basisregistratieNaam=BRP`, `wettelijkeGrondslag=Wet BRP art. 3`, `bronhouder=Logius (KvK 34272727)`, `aansluitDatum=1997-01-01`
- NHR dataset: `positieType=basisregistratie`, `basisregistratieNaam=NHR`, `wettelijkeGrondslag=Handelsregisterwet art. 5`, `bronhouder=KvK (KvK 27373977)`, `gegevensbeheerder=KvK (KvK 27373977)`, `verstrekker=KvK (KvK 27373977)`
- Gemeentelijke kerngegevens: `positieType=kerngegevensstelsel`, `gerelateerdeBasisregistratie=BRP`, `aansluitDatum=2024-01-15`

**KerngegevensSet examples:**
- Identificatie-persoonsgegevens: `setNaam="Identificerende persoonsgegevens"`, `bronAttributen=[{pad: "/bsn", label: "BSN"}, {pad: "/geslachtsnaam", label: "Geslachtsnaam"}]`, `gerelateerdeBasisregistratie=BRP`
- Vestigingsadres: `setNaam="Vestigingsadres"`, `bronAttributen=[{pad: "/adres/straatnaam", label: "Straatnaam"}, {pad: "/adres/huisnummer", label: "Huisnummer"}]`, `gerelateerdeBasisregistratie=BAG`

**BijhoudCyclus examples:**
- BRP realtime: `bijhoudFrequentie=realtime`, `bijhoudBron=BRP-systeem`, `laatsteUpdate=2026-05-22T14:37:00Z`, `volgendeGeplandeUpdate=null`
- Maandelijkse UAV snapshot: `bijhoudFrequentie=maandelijks`, `peildatum=2026-05-01`, `laatsteUpdate=2026-05-01T08:00:00Z`, `volgendeGeplandeUpdate=2026-06-01T08:00:00Z`

**Geldigheidsperiode examples:**
- Historical BRP: `geldigVanaf=1997-01-01`, `geldigTotEnMet=2010-12-31`, `historischeVersies=[{version: "1997-01", uri: "/datasets/brp/versions/1997-01"}]`
- Current BAG: `geldigVanaf=2010-01-01`, `geldigTotEnMet=null` (open-ended)

**TerugmeldEndpoint examples:**
- BRP feedback: `terugmeldUrl=https://logius.nl/terugmelding-api/brp`, `terugmeldProtocol=digikoppeling-ebms`, `responsTermijn=5 werkdagen`, `contactgegevens=terugmelding@logius.nl`
- NHR feedback: `terugmeldUrl=https://kvk.nl/api/feedback/nhr`, `terugmeldProtocol=rest-json`, `responsTermijn=10 werkdagen`, `contactgegevens=support@kvk.nl`

See specs/kerngegevens-stelsel-registratie/spec.md for detailed requirements and scenarios.
