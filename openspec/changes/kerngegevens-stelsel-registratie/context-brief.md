status: draft

# Kerngegevensstelsel Registratie

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Standaarden > Kerngegevensstelsel / Standaarden

**Rationale:** Registratie page  
_Source: /tmp/ia-doc-dec-cat-conn.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Het Nederlandse overheidslandschap kent een gelaagde structuur van data-registers: tien basisregistraties (BRP, BAG, BRT, BRK, NHR, BGT, BRO, BRV, WOZ, BRI) met wettelijke status, daarnaast kerngegevens uit het sectorbrede stelsel (zoals NLX-aangesloten registers, gemeentelijke kerntabellen, en de nieuwe Stelselcatalogus 2026), en tenslotte sectorbrede registers per domein (zorg, onderwijs, justitie, mobiliteit). Voor elke dataset die een organisatie publiceert is het cruciaal te markeren welke stelselpositie deze inneemt: is dit een wettelijke basisregistratie waarop terugmelding-plicht rust, een kerngegeven dat ander beleid voedt, of een afgeleid sectoraal product?

De huidige opencatalogi mist deze gelaagdheid expliciet. Datasets krijgen wel DCAT-AP-metadata, maar de stelsel-positie wordt vaak alleen in een vrij-tekst beschrijving genoemd - waardoor automatische federatie met data.overheid.nl, het Forum Standaardisatie-register, en de Stelselcatalogus niet betrouwbaar werkt. Bij hergebruik door derden ontbreekt vaak duidelijkheid over brondatum, bijhoudbron, geldigheidsperiode en terugmeld-mechanisme - allemaal verplichte velden onder DCAT-AP-NL 2.1 voor publieke datasets.

Deze spec voegt een gestructureerde Stelselregistratie-extensie toe aan opencatalogi-datasets: per dataset een stelselpositie-classificatie, een brondatum-velden-set, een geldigheidsregime, en (waar van toepassing) een terugmeld-endpoint. Volledig DCAT-AP-NL 2.1 conform met de Nederlandse profielen (data.overheid.nl harvest-conform). Maakt federatie naar de landelijke Stelselcatalogus mogelijk.

## Data Model

**StelselPositie** (nieuw schema, register `opencatalogi-stelsel`):
- `datasetId` (relatie naar Dataset)
- `positieType` (enum: basisregistratie / kerngegevensstelsel / sectorbreed-register / afgeleide-set / lokale-registratie)
- `basisregistratieNaam` (enum, indien type=basisregistratie: BRP / BAG / BRT / BRK / NHR / BGT / BRO / BRV / WOZ / BRI)
- `wettelijkeGrondslag` (string, bv. "Wet BAG art. 31")
- `bronhouder` (string, KvK + naam)
- `gegevensbeheerder` (string, KvK + naam, vaak gelijk aan bronhouder)
- `verstrekker` (string, organisatie die ontsluit)
- `aansluitDatum` (date, eerste opname in stelsel)

**KerngegevensSet** (nieuw schema):
- `datasetId` (relatie)
- `setNaam` (string, bv. "Identificerende persoonsgegevens", "Vestigingsadres")
- `bronAttributen` (array van attribuut-referenties met paden naar dataset-velden)
- `gerelateerdeBasisregistratie` (string, naar welke BR dit een kerngegeven van is)

**BijhoudCyclus** (nieuw schema):
- `datasetId` (relatie)
- `bijhoudFrequentie` (enum: realtime / dagelijks / wekelijks / maandelijks / kwartaal / jaarlijks / event-driven)
- `bijhoudBron` (string, systeem-referentie)
- `laatsteUpdate` (datetime)
- `volgendeGeplandeUpdate` (datetime)
- `peildatum` (date, voor snapshot-georienteerde sets)

**Geldigheidsperiode** (nieuw schema, voor temporele datasets):
- `datasetId` (relatie)
- `geldigVanaf` (date)
- `geldigTotEnMet` (date, nullable voor open-einde)
- `historischeVersies` (array van dataset-versie-references)

**TerugmeldEndpoint** (nieuw schema):
- `datasetId` (relatie)
- `terugmeldUrl` (uri)
- `terugmeldProtocol` (enum: digikoppeling-ebms / rest-json / nlx)
- `responsTermijn` (string, bv. "5 werkdagen")
- `contactgegevens` (string)

## Requirements

### REQ-001: Stelselpositie verplicht bij publicatie

GIVEN een dataset in opencatalogi met status `concept`
WHEN de gebruiker de status naar `gepubliceerd` wil zetten
THEN valideert het systeem dat een StelselPositie-object is gekoppeld met minimaal `positieType` ingevuld, en blokkeert publicatie als dit ontbreekt - met een melding die linkt naar de stelselpositie-wizard.

### REQ-002: Basisregistratie-validatie

GIVEN een StelselPositie met `positieType = basisregistratie`
WHEN het object wordt opgeslagen
THEN eist het systeem dat `basisregistratieNaam` is gekozen uit de officiele lijst van tien, `wettelijkeGrondslag` is ingevuld, en `bronhouder` matcht met een entiteit uit het register Nederlandse Overheids-organisaties (ROO).

### REQ-003: Kerngegevens-afleiding documenteren

GIVEN een dataset met StelselPositie van type `kerngegevensstelsel` of `afgeleide-set`
WHEN de gebruiker kerngegevens-sets definieert
THEN kan per set een relatie worden gelegd naar de bronnen-basisregistratie via `gerelateerdeBasisregistratie`, en moeten de `bronAttributen` mappen op feitelijke veldnamen in de dataset-schema-definitie (validatie tegen schema).

### REQ-004: Bijhoudfrequentie + actualiteit-indicator

GIVEN een gepubliceerde dataset met BijhoudCyclus
WHEN een gebruiker of harvester de dataset bekijkt
THEN toont opencatalogi een actualiteit-indicator (groen/oranje/rood) gebaseerd op of `laatsteUpdate` valt binnen de verwachte `bijhoudFrequentie`-band, en exporteert deze status in de DCAT-output als `dct:modified` + custom `dn:bijhoudStatus`.

### REQ-005: Geldigheidsperiode + historische versies

GIVEN een dataset met temporele scope
WHEN een nieuwe Geldigheidsperiode wordt geopend (oude wordt afgesloten met `geldigTotEnMet`)
THEN snapshot het systeem de oude dataset-versie als immutable record toegankelijk via versie-permalinks, en linkt deze in `historischeVersies` - zodat hergebruikers kunnen terugkijken op welke versie ze op een peildatum gebruikten.

### REQ-006: Terugmelding-mechanisme registreren

GIVEN een dataset met `positieType = basisregistratie`
WHEN de publicerende organisatie de dataset wil gepubliceerd zien
THEN eist het systeem een TerugmeldEndpoint-koppeling conform de Terugmeldvoorziening-richtlijnen (artikel 38a Wet BAG en analogen), en toont op de dataset-detailpagina een prominente "Onjuist gegeven melden"-knop die het juiste protocol uitvoert.

### REQ-007: DCAT-AP-NL 2.1 export

GIVEN een dataset met volledig ingevulde Stelselregistratie-extensies
WHEN data.overheid.nl (of een andere harvester) de DCAT-feed ophaalt
THEN exporteert opencatalogi een DCAT-AP-NL 2.1 conforme RDF/XML- en JSON-LD-representatie met de Nederlandse profiel-uitbreidingen (kerngegevens, bronhouder, terugmeld-uri) - inclusief geldige IRI's voor stelsel-vocabulaires.

### REQ-008: Federatie naar Stelselcatalogus

GIVEN een opencatalogi-instantie met datasets die `positieType = kerngegevensstelsel` hebben
WHEN de geconfigureerde Stelselcatalogus-federatie-job draait (dagelijks)
THEN pusht het systeem de relevante datasets via de Stelselcatalogus-API met de juiste vocabulaire-mappings, en logt fouten als open-bevindingen voor de catalogus-beheerder.

## Standards

- **DCAT-AP-NL 2.1** (Forum Standaardisatie, verplicht op pas-toe-of-leg-uit-lijst)
- **NL GOV Profile of DCAT-AP** (2.1)
- **Stelselcatalogus** (Logius, vocabulaire-set voor kerngegevens)
- **Wet BAG, BRP, BRK, etc.** (wettelijke grondslag basisregistraties)
- **Terugmeldvoorziening** (Logius-richtlijnen artikel 38a Wet BAG en analogen)
- **NORA** (Nederlandse Overheid Referentie Architectuur, principes hergebruik)
- **Digikoppeling ebMS / NLX** (transport voor terugmeldingen)
- **W3C DCAT 3** (basis-vocabulaire)

## Cross-app

- **openregister**: schema-koppeling voor `bronAttributen`-validatie
- **opencatalogi base**: dataset-entiteit waarop deze spec extensies bouwt
- **openconnector**: federatie-source-definities (Stelselcatalogus, data.overheid.nl)
- **softwarecatalog**: API-leveranciers-referentie voor verstrekkers
- **mydash**: actualiteit-dashboard per stelselpositie
- **docudesk**: opslag wettelijke-grondslag-PDFs en bijhouder-overeenkomsten

## Target users

- **Catalogusbeheerder** (gemeente/provincie/UWV): registratie + onderhoud StelselPositie
- **Data-steward** (per domein): bijhoudCyclus + Geldigheidsperiode-beheer
- **Architect** (NORA-aligned): stelsel-positionering bij nieuwe dataset
- **Privacy Officer**: validatie kerngegevens (privacy-impact)
- **Terugmelder** (extern, burger/bedrijf): gebruik terugmeld-endpoint
- **Federatie-beheerder** (Logius/VNG): consumeert DCAT-feed
- **Hergebruiker** (data-journalist, ontwikkelaar): consulteert peildatum + historische versies
