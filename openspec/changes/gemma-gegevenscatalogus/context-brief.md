---
status: draft
---
# GEMMA Gegevenscatalogus Integratie

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Standaarden > GEMMA gegevenscatalogus / Standaarden

**Rationale:** Standards-mapping page  
_Source: /tmp/ia-doc-dec-cat-conn.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

De Gemeentelijke Model Architectuur (GEMMA) is de referentiearchitectuur voor de Nederlandse gemeenten, ontwikkeld en beheerd door VNG Realisatie. Een centraal onderdeel daarvan is de GEMMA Gegevenscatalogus: ongeveer 600 gestandaardiseerde objecttypen die de fundamentele begrippen in het gemeentelijk domein beschrijven — denk aan `Persoon`, `Adres`, `Zaak`, `Document`, `Verblijfsobject` (BAG), `Ingeschrevennatuurlijkpersoon` (BRP), `Belanghebbende` (WMO/JW), `Kadastraalonroerendezaak`, `Vergunning`, `Inkomensgegevens`, en honderden meer. Per objecttype levert de catalogus: een formele definitie (DEFINITIE), attributen met datatypes en cardinaliteit, relaties met andere objecttypen, regels voor geldigheid (begin/einde geldigheid, materiele/formele geschiedenis), en herkomst (welk basisregister of welke wetgeving).

Gemeenten worstelen met het toepassen van deze catalogus. Het materiaal wordt door VNG gepubliceerd als HTML-website (gemmaonline.nl), als SKOS-skos-bestand en als UML-modellen in Enterprise Architect — geen van die formaten is direct bruikbaar in moderne software-stacks. Gevolg: elke gemeente of leverancier bouwt eigen mappings (van GEMMA naar eigen datamodel), die niet onderling vergelijkbaar zijn. Bij upgrades van GEMMA (2.6 → 3.0 in 2025 was een significante release met circa 80 gewijzigde objecttypen) moeten al die mappings opnieuw worden gevalideerd.

Deze spec maakt de GEMMA Gegevenscatalogus first-class in opencatalogi: importeren via SKOS/OWL/RDF, bladeren en zoeken via een UI, mappen naar eigen schemas in openregister, versie-tracking over GEMMA-releases, en compliance-rapportages ("welke van mijn registers zijn GEMMA-conform op niveau X?"). Daarmee maakt de gemeente expliciet zichtbaar wat de mate van standaardisatie is, en kan een leverancier (Conduction, of een andere ICT-leverancier) zijn eigen schemas aan de standaard verankeren zodat ze tussen gemeenten herbruikbaar zijn.

De spec ondersteunt ook de bredere VNG-doelstelling onder Common Ground en de "Realisatie IV-Gemeenten": gegevens éénmaal opslaan, meervoudig gebruiken, met de objecttypen-catalogus als gedeeld semantisch fundament.

## Data Model

Deze spec voegt vijf schema's toe aan opencatalogi en breidt twee bestaande uit.

**Schema: `GemmaCatalogus`** — root-object per GEMMA-release. Velden: `id` (uuid), `versie` (string, bv. `3.0`, `2.6`), `releaseDatum` (date), `status` (enum: concept, vastgesteld, vervallen), `taal` (BCP-47, default `nl-NL`), `bron` (uri naar VNG release), `importedAt` (datetime), `importedBy` (ref naar User), `aantalObjecttypen` (int), `aantalAttributen` (int), `aantalRelaties` (int), `wijzigingstype` (enum: major, minor, patch — gerelateerd aan vorige versie), `voorganger` (ref naar GemmaCatalogus, optioneel).

**Schema: `GemmaObjecttype`** — één objecttype in de catalogus. Velden: `id` (uuid), `catalogus` (ref), `naam` (string, bv. `Persoon`), `nameSpace` (string, bv. `https://gemmaonline.nl/index.php/...`), `urn` (string, canonieke URN), `definitie` (text), `toelichting` (text), `synoniemen` (array string), `domein` (enum: BAG, BRP, BRK, BGT, WMO, JW, Zaak, Document, Belasting, Subsidie, Vergunning, etc.), `subtype` (enum: object, gegevenselement, attribuutsoort, relatiesoort), `parent` (ref naar GemmaObjecttype, optioneel — voor generalisatie), `abstract` (bool), `herkomst` (text — wetgeving / basisregister), `geldigVan` (date), `geldigTot` (date, optioneel), `vervangenDoor` (ref, optioneel), `attributen` (array van GemmaAttribuut-refs), `relaties` (array van GemmaRelatie-refs).

**Schema: `GemmaAttribuut`** — één attribuut van een objecttype. Velden: `id` (uuid), `objecttype` (ref), `naam` (string, bv. `geboortedatum`), `definitie` (text), `datatype` (enum: string, integer, decimal, datum, datumtijd, boolean, code, opsomming, AN, N — conform GEMMA-datatypes), `formaat` (string, bv. regex of opsomming-codeRef), `lengte` (string, bv. `4..16`), `cardinaliteit` (enum: `0..1`, `1..1`, `0..*`, `1..*`), `autoriteit` (string, bv. "Basisregister BRP"), `herkomstWetgeving` (string), `kerngegeven` (bool — gemarkeerd als kerngegeven door VNG), `gevoeligheid` (enum: openbaar, persoonsgegeven, bijzonderPersoonsgegeven, strafrechtelijkPersoonsgegeven), `voorbeelden` (array string).

**Schema: `GemmaRelatie`** — relatie tussen twee objecttypen. Velden: `id` (uuid), `vanObjecttype` (ref), `naarObjecttype` (ref), `naam` (string, bv. `heeftBewoners`), `definitie` (text), `cardinaliteit` (string, bv. `1..* : 0..*`), `rol` (string), `omgekeerdeRol` (string), `aggregatieType` (enum: associatie, aggregatie, compositie, generalisatie).

**Schema: `GemmaMapping`** — koppeling tussen een GEMMA-objecttype en een lokaal Schema in openregister. Velden: `id` (uuid), `gemmaObjecttype` (ref), `localSchema` (ref naar openregister Schema), `mappingKwaliteit` (enum: volledig, partieel, geen — voor de mate van dekking), `attribuutMappings` (array van objects: `{gemmaAttribuut: ref, localProperty: string, transformatie: string}`), `relatieMappings` (array — analoog), `validatieStatus` (enum: gevalideerd, gewaarschuwd, conflict), `gevalideerdOp` (datetime), `gevalideerdDoor` (ref naar User), `opmerkingen` (text).

**Uitbreidingen aan bestaande schema's**:
- `Catalogus` (opencatalogi): extra velden `gemmaCompliant` (bool), `gemmaCompliancePercentage` (decimal 0..100), `actieveCatalogus` (ref naar GemmaCatalogus).
- `Schema` (openregister): extra velden `gemmaObjecttype` (ref, optioneel — kanonieke referentie), `gemmaMappingKwaliteit` (enum).

## Requirements

**REQ-GEM-001: Importeren van GEMMA-release via SKOS/OWL/RDF**
Het systeem MOET een GEMMA-release kunnen importeren uit het door VNG geleverde SKOS-bestand of OWL/RDF-bestand.
- GIVEN een beheerder uploadt `gemma-3.0.ttl` (Turtle RDF), WHEN de import-job start, THEN MOET het systeem alle Objecttypen, Attributen en Relaties parsen en een GemmaCatalogus-record + alle bijbehorende records creëren binnen 30 minuten voor de full 600-objecttype-set.
- GIVEN het importbestand bevat een verwijzing naar een externe ontologie (bv. SKOS-Core), WHEN parsing loopt, THEN MOET het systeem de ontologie ophalen of een lokale cache gebruiken en MOET geen import falen op netwerkproblemen.
- GIVEN een import-job loopt al en een tweede wordt getriggerd, WHEN de tweede start, THEN MOET deze met foutmelding `import-already-running` worden afgewezen.

**REQ-GEM-002: Browse-UI met facet-navigatie en search**
De gebruiker MOET via een UI door de catalogus kunnen bladeren, filteren op domein/status, en op vrije tekst kunnen zoeken.
- GIVEN een ingelogde gebruiker opent `/gemma-catalogus`, WHEN de pagina laadt, THEN MOET deze de actieve catalogus tonen met sidebar-facetten voor `domein` (BAG, BRP, etc.), `status`, `subtype` en `kerngegeven`.
- GIVEN een gebruiker zoekt op term "verblijfsobject", WHEN hij Enter drukt, THEN MOET het systeem matches in `naam`, `synoniemen`, `definitie` en `attributen.naam` tonen, gerangschikt op relevantie.
- GIVEN een Objecttype-detailpagina, WHEN deze laadt, THEN MOET deze de definitie, attributen-tabel, relaties (uitgaand én inkomend), herkomst-wetgeving en linken naar gerelateerde objecttypen tonen.

**REQ-GEM-003: Mapping van eigen Schema naar GEMMA-Objecttype**
Een data-architect MOET een openregister-Schema kunnen koppelen aan een GEMMA-Objecttype en per attribuut een transformatie kunnen definiëren.
- GIVEN een data-architect opent een Schema "MijnPersoon" en kiest "Koppel aan GEMMA", WHEN hij `Persoon` selecteert, THEN MOET het systeem een drag-and-drop UI tonen waarin lokale properties op GEMMA-attributen kunnen worden gemapt.
- GIVEN een mapping wordt opgeslagen met 8 van de 12 verplichte attributen gevuld, WHEN deze wordt gevalideerd, THEN MOET `mappingKwaliteit` op `partieel` worden gezet en MOET een lijst missende attributen worden getoond.
- GIVEN alle verplichte attributen en relaties zijn gemapt, WHEN gevalideerd, THEN MOET `mappingKwaliteit: volledig` zijn en MOET het Schema badge "GEMMA-conform" tonen in de UI.

**REQ-GEM-004: Versie-tracking en migratie tussen GEMMA-releases**
Wanneer een nieuwe GEMMA-release wordt geïmporteerd MOET het systeem de wijzigingen ten opzichte van de vorige release detecteren en mappings flaggen die mogelijk aanpassing nodig hebben.
- GIVEN GemmaCatalogus 2.6 is actief en 3.0 wordt geïmporteerd, WHEN de import klaar is, THEN MOET het systeem een diff-rapport produceren met `objecttypenToegevoegd`, `objecttypenVerwijderd`, `objecttypenGewijzigd` (per gewijzigd objecttype: gewijzigde attributen/relaties).
- GIVEN een bestaande GemmaMapping verwijst naar een verwijderd Objecttype, WHEN de diff loopt, THEN MOET de mapping `validatieStatus: conflict` krijgen en MOET een notificatie naar de data-architect worden gestuurd.
- GIVEN een attribuut is hernoemd in 3.0 (oud: `geboortdat`, nieuw: `geboortedatum`), WHEN de diff loopt, THEN MOET het systeem suggesties tonen voor automatische hermapping op basis van fuzzy-naam-matching en URN-historiek.

**REQ-GEM-005: Compliance-rapportage per Register**
Het systeem MOET per openregister Register een compliance-rapport produceren met de mate van GEMMA-conformiteit.
- GIVEN een Register met 12 Schemas waarvan 9 een volledige GEMMA-mapping hebben en 2 een partiele en 1 geen, WHEN het rapport wordt gegenereerd, THEN MOET `gemmaCompliancePercentage = 75.0` worden gerapporteerd.
- GIVEN het rapport, WHEN het wordt geëxporteerd als PDF of CSV, THEN MOET het per Schema de mapping-status, ontbrekende verplichte attributen en aanbevolen acties tonen.
- GIVEN het rapport wordt maandelijks gegenereerd via cron, WHEN het verandert, THEN MOET een diff-notificatie naar de CIO-gebruiker worden gestuurd.

**REQ-GEM-006: API-validatie tegen GEMMA-mapping**
Wanneer een openregister-object wordt aangemaakt of bewerkt en het Schema heeft een actieve GEMMA-mapping, MOET het systeem optioneel valideren op GEMMA-niveau.
- GIVEN een Schema met `gemmaObjecttype: Persoon` en `gemmaMappingKwaliteit: volledig`, GIVEN configuratie `gemmaValidationMode: strict`, WHEN een object met ontbrekend `bsn` wordt aangemaakt, THEN MOET de validatie falen met error "GEMMA-validatie: verplicht attribuut `inp.bsn` ontbreekt".
- GIVEN configuratie `gemmaValidationMode: warn`, WHEN dezelfde aanmaak gebeurt, THEN MOET de aanmaak slagen met warning-header in de response.
- GIVEN configuratie `gemmaValidationMode: off`, WHEN aangemaakt, THEN MOET geen extra validatie plaatsvinden.

**REQ-GEM-007: Bidirectionele linking met externe GEMMA-online**
Vanuit een GemmaObjecttype-detailpagina MOET een gebruiker direct kunnen doorklikken naar de canonieke pagina op gemmaonline.nl.
- GIVEN een GemmaObjecttype-detailpagina, WHEN de gebruiker op "open op GEMMA-online" klikt, THEN MOET een nieuw tabblad de juiste objecttype-pagina openen op `gemmaonline.nl/index.php/Objecttype:{naam}`.
- GIVEN VNG publiceert een GitHub-issue of update over een objecttype, WHEN de gebruiker op "discussie op GitHub" klikt, THEN MOET het issue-overzicht van het VNG-Realisatie GitHub-repo worden geopend met filter op het objecttype-label.
- GIVEN een Objecttype heeft een `vervangenDoor`, WHEN de pagina laadt, THEN MOET een banner "vervallen — gebruik {nieuw}" worden getoond met directe link.

**REQ-GEM-008: Domeinmodel-export per domein als JSON-LD**
De gebruiker MOET per domein (bv. BRP, BAG) een complete subset van de catalogus kunnen exporteren als JSON-LD voor gebruik in andere apps.
- GIVEN een gebruiker opent een domein-overzichtspagina BAG, WHEN hij op "Exporteer als JSON-LD" klikt, THEN MOET een downloadbaar bestand worden gegenereerd dat alle BAG-Objecttypen met attributen, relaties en context bevat conform JSON-LD 1.1.
- GIVEN dezelfde export wordt 5 dagen later opnieuw gedaan op dezelfde GEMMA-versie, WHEN de download wordt gestart, THEN MOET een gecachte versie binnen 2 seconden worden geleverd.
- GIVEN een actieve GemmaCatalogus wordt vervangen door een nieuwe versie, WHEN dat gebeurt, THEN MOET de cache worden geïnvalideerd.

**REQ-GEM-009: Synoniem- en attribuut-suggesties bij Schema-aanmaak**
Wanneer een gebruiker een nieuw Schema aanmaakt in openregister MOET het systeem GEMMA-Objecttype-matches en attribuut-suggesties tonen op basis van de Schema-naam en eerste properties.
- GIVEN een gebruiker maakt een Schema "Inwoner" aan, WHEN hij begint met properties te definiëren, THEN MOET het systeem `Persoon` en `Ingeschrevennatuurlijkpersoon` als suggesties tonen met match-score.
- GIVEN de gebruiker kiest `Persoon`, WHEN hij property "geboorte" begint te typen, THEN MOET het systeem `geboortedatum`, `geboorteplaats`, `geboorteland` suggereren met juiste datatype en cardinaliteit.
- GIVEN de gebruiker accepteert een suggestie, WHEN hij opslaat, THEN MOET de property automatisch worden gemapt naar het GEMMA-attribuut zonder extra mapping-stap.

**REQ-GEM-010: Audit-trail op mappings voor compliance-doeleinden**
Elke wijziging op een GemmaMapping MOET worden gelogd voor audit-doeleinden, met name voor BIO/IBD-audits.
- GIVEN een data-architect wijzigt een attribuut-mapping, WHEN hij opslaat, THEN MOET een audit-record worden aangemaakt met `who`, `when`, `what` (diff), `why` (verplicht commentaar-veld).
- GIVEN een GemmaMapping wordt verwijderd, WHEN dat gebeurt, THEN MOET de mapping niet hard worden verwijderd, maar `status: vervallen` krijgen met behoud van history.
- GIVEN een auditor opent het audit-overzicht, WHEN hij filtert op een Schema, THEN MOET de volledige history zichtbaar zijn, exporteerbaar als PDF en cryptografisch ondertekend.

## Standards & Sources

- **GEMMA 3.0 Architectuurplaat en Architectuurkader** — VNG Realisatie, https://www.gemmaonline.nl/index.php/GEMMA. Vastgesteld 2024 (effectief 2025-2026).
- **GEMMA Gegevenscatalogus** — onderdeel van GEMMA, gepubliceerd op gemmaonline.nl, downloadbaar als SKOS Turtle (.ttl), RDF/XML, JSON-LD en als Enterprise Architect UML XMI.
- **SKOS (Simple Knowledge Organization System)** — W3C Recommendation 2009. Gebruikt voor de thesaurus-laag (concepten, broader/narrower).
- **OWL 2** — W3C Recommendation 2012. Gebruikt voor formele class-hiërarchie en relaties.
- **JSON-LD 1.1** — W3C Recommendation 2020. Voor export en RESTful publicatie.
- **PROV-O** — W3C Recommendation 2013. Voor provenance/herkomst van objecttypen (welke wet, welk basisregister).
- **NEN 2660-1:2022** — "Regels voor informatiemodellering". GEMMA Gegevenscatalogus is hierop gebaseerd.
- **MIM 1.1.1 (Metamodel Informatiemodellering)** — Geonovum-standaard die de NL-overheid gebruikt voor informatiemodellen; GEMMA 3.0 volgt MIM 1.1.1.
- **VNG GitHub** — github.com/VNG-Realisatie/Gemeentelijk-Gegevensmodel; issue-tracker en source-of-truth voor wijzigingen.
- **DCAT-AP-NL** — voor publicatie van de catalogus als open dataset.
- **Forum Standaardisatie "Pas toe of leg uit"** — relevante standaarden NEN 2660, JSON, RDF, SKOS staan op de lijst.

## Cross-app integration

- **opencatalogi** (base) — eigenaar van alle nieuwe schema's. UI integreert met bestaande Catalogus-publicatieflow.
- **openregister** — host voor Schema- en Register-objecten waar mappings naar verwijzen. Validatie-uitbreiding in REQ-GEM-006 wordt geïmplementeerd als plugin in openregister's validatie-pipeline.
- **softwarecatalog** — software-registraties die `gemmaCompliant: true` claimen, MOETEN een GemmaMapping-link tonen als bewijs.
- **openconnector** — kan worden gebruikt om externe systemen (Zaaksysteem.nl, GovUnited Software, etc.) te koppelen waarbij de GEMMA-mapping als transformatie-laag dient.
- **forum-standaardisatie-pas-toe-of-leg-uit** (zustertspec) — verwijst naar de GEMMA-catalogus als bron voor toepassing van NEN 2660 en de bijbehorende verklaring.
- **docudesk** — kan compliance-rapporten genereren als PDF met huisstijl.
- **mydash** — KPI's: GEMMA-compliance-percentage per Register, aantal Schemas zonder mapping, gemiddelde mapping-kwaliteit.
- **decidesk** — vergaderstukken met objecttype-categorisatie (bijvoorbeeld een raadsbesluit dat over `Verblijfsobject` gaat) kunnen automatisch worden gelabeld.

## Target users

- **Data-architecten gemeenten** (primair) — moeten hun datamodel afstemmen op GEMMA voor interoperabiliteit en compliance.
- **Informatiemanagers** — gebruiken compliance-rapportages voor sturing en verantwoording naar bestuur.
- **CIO en CISO** — willen bewijsbaar GEMMA-conformiteit voor BIO-audits en informatiebeveiligingsstandaarden.
- **Leveranciers van gemeentesoftware** (waaronder Conduction zelf) — willen claims kunnen maken over GEMMA-conformiteit van hun productschema's.
- **VNG Realisatie** — krijgt feedback over welke objecttypen in de praktijk worden gebruikt en waar mappings struikelen; input voor catalogus-evolutie.
- **Beleidsmedewerkers** — gebruiken het als woordenboek voor het gemeentelijk vakgebied bij het schrijven van beleidsstukken.
- **Externe ontwikkelaars** (civic tech, journalistiek) — gebruiken JSON-LD-exports om interoperabele applicaties tegen gemeentelijke data te bouwen.
- **Auditors (NOREA, Algemene Rekenkamer)** — kunnen automatische assertions doen tegen gepubliceerde compliance-rapportages.
- **Common Ground-community** — een geïntegreerde GEMMA-catalogus is een fundamenteel bouwblok voor de Common Ground-realisatie (datalaag-services die meerdere gemeenten delen); de mappings in deze spec maken die hergebruik concreet bewijsbaar.
- **Studenten en docenten Bestuurlijke Informatiekunde** — krijgen een levend voorbeeld van een toegepaste referentiearchitectuur i.p.v. alleen PDF-documenten te bestuderen.

## Implementatie-overwegingen

**Keuze 1: SKOS/OWL/RDF-import boven proprietary XMI-import.** VNG levert beide; XMI uit Enterprise Architect bevat extra modellering-informatie, maar SKOS/OWL is industrie-standaard en heeft veel betere parser-ondersteuning (rdf4j, ARC2, EasyRdf in PHP). Voor v1 starten we met SKOS/Turtle als primair formaat; XMI kan in een latere iteratie als alternatieve import voor diep modellering-detail.

**Keuze 2: Mapping als first-class object, niet inline op Schema.** Een GemmaMapping is groter dan een paar velden — het bevat per attribuut een mapping plus transformatie, en heeft eigen lifecycle (gevalideerd op datum X, vervallen, etc.). Inline veroorzaakt opgeblazen Schema-records en maakt audit-trail lastig. Aparte entity met FK naar Schema is de juiste keuze.

**Keuze 3: Validatie-mode configureerbaar (strict/warn/off).** Strict-mode is voor productie-registers waar GEMMA-conformiteit kritisch is (BRP-koppeling). Warn-mode is voor migratiefases waarin schemas geleidelijk worden gemapt. Off-mode voor registers waar GEMMA niet van toepassing is (bv. interne werktoolregisters). Configureerbaar per Register, niet globaal.

**Keuze 4: Diff-detectie tussen GEMMA-releases via URN-historiek, niet enkel naam-matching.** VNG kent stabiele URN's per Objecttype/Attribuut die over releases heen consistent zijn (ook bij hernoeming). Match-by-URN is de canonieke methode; naam-matching is fuzzy-fallback voor edge cases.

## Out-of-scope (toekomstige iteraties)

Niet in v1: schrijven naar GEMMA-online (one-way pull only — VNG is bron); automatische generatie van OpenAPI-specs uit GEMMA-mappings (interessant, eigen vervolgspec); BAG/BRP-specifieke validatie-regels (bv. geboortedatum vóór overlijdensdatum) — die horen in domein-specifieke validatie-spec; visualisatie van de catalogus als ERD-diagram (mooi maar v2); cross-domein-impact-analyse ("welke applicaties raken een wijziging op `Persoon.bsn`?") — afhankelijk van volledige lineage-modellering; integratie met provinciale/waterschap-referentiemodellen (PETRA, WILMA) — analoog maar separate spec.

## Risico's en mitigaties

**Risico: GEMMA-releases lopen achter op feitelijke wetgeving.** VNG kan maanden tot een jaar nodig hebben om wetswijzigingen in een nieuwe release te verwerken. Mitigatie: het systeem ondersteunt parallel meerdere catalogusversies; gemeenten kunnen al een eigen mapping maken op een "preview" van de volgende release; integratie met VNG GitHub-issue-tracker geeft early-warning.

**Risico: Mapping-kwaliteit subjectief.** "Volledig" mapping kan voor de ene reviewer iets anders betekenen dan voor de andere. Mitigatie: harde definitie in de spec (alle verplichte attributen + verplichte relaties gemapped = volledig); automatische scoring i.p.v. handmatige aanduiding; peer-review-flow waarin een tweede data-architect de mapping moet bevestigen voor "gevalideerd"-status.

**Risico: Import-performance bij grote catalogus.** 600 objecttypen met gemiddeld 15 attributen elk en relaties is een dataset van circa 25.000 records. Mitigatie: bulk-insert i.p.v. ORM-loop; transaction-batches van 1.000; benchmark-test als CI-vereiste.

**Risico: GEMMA-niveau-validatie blokkeert legitieme use-cases.** Strict-mode kan teveel zijn als een gemeente bewust afwijkt voor lokale variant. Mitigatie: strict-mode is opt-in per Register; afwijkingen kunnen worden gedocumenteerd als "intentional deviation" met motivering die als Toepassing wordt geregistreerd onder de pas-toe-of-leg-uit-spec.
