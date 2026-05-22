---
status: draft
---
# Forum Standaardisatie "Pas toe of leg uit"-Registratie

## Purpose

Het Forum Standaardisatie (BZK, met secretariaat Logius) houdt een lijst bij van 115 open standaarden waarvoor in Nederland het "pas toe of leg uit"-regime geldt. Het regime — vastgelegd in de Instructie Rijksdienst inzake openbare orde en in de aanwijzingen voor de departementen aan agentschappen, zbo's en mede-overheden — verplicht overheidsorganisaties bij ICT-inkoop én bij eigen ontwikkeling om deze standaarden toe te passen óf publiekelijk te verantwoorden waarom dat niet kan. Voorbeelden van standaarden op de lijst: IPv6 + DNSSEC, TLS 1.3, DKIM/SPF/DMARC, ODF, PDF/A-2, WCAG 2.2, NLCIUS (Peppol), Digikoppeling, SAML 2.0 / OpenID Connect, STOP/TPOD, NEN 2082 (recordsmanagement), NEN 7510 (zorginformatie), enzovoorts.

In de praktijk is naleving slecht meetbaar: gemeenten en overige overheden weten zelf vaak niet welke standaarden van toepassing zijn op welke applicatie of welk component, laat staan dat ze documenteren waar en waarom ze afwijken. De rapportages aan Forum Standaardisatie (jaarlijkse monitor pas-toe-of-leg-uit) zijn handmatig samengesteld, momentopname, vaak onvolledig, en geven inkopers en bestuurders geen real-time inzicht. Het Forum noemt dit "monitorgat" expliciet in zijn jaarrapportages.

Deze spec voegt aan opencatalogi een register toe voor de complete pas-toe-of-leg-uit-lijst, en koppelt deze aan: (1) applicaties en componenten in de softwarecatalogus, (2) registers en schemas in openregister, (3) externe systemen in openconnector, zodat per object expliciet wordt vastgelegd welke standaarden van toepassing zijn (Toegepast / Niet toegepast / Niet van toepassing) en bij afwijking een verklaring met onderbouwing en evidence-link. Het systeem genereert vervolgens de jaarrapportage automatisch in het formaat dat het Forum verwacht, en levert een continu dashboard voor CIO, CISO, IBD en auditor. Updates van de Forum-lijst (nieuwe standaarden, status-wijzigingen) worden automatisch geïmporteerd via de Forum API.

De waardepropositie is drieledig: (a) **compliance** — de gemeente kan aantoonbaar voldoen aan de pas-toe-of-leg-uit-verplichting; (b) **inkoop** — bij aanbestedingen kunnen exact de juiste standaarden worden voorgeschreven met automatische impact-analyse op het bestaande landschap; (c) **transparantie** — burgers en journalisten kunnen via het publieke deel van de catalogus zien welke standaarden hun gemeente toepast.

## Data Model

Deze spec voegt vier nieuwe schema's toe en breidt de bestaande software-registratie-schemas uit.

**Schema: `Standaard`** — één Forum Standaardisatie-standaard. Velden: `id` (uuid), `naam` (string, bv. `TLS 1.3`), `volledigeNaam` (string), `forumId` (string, canonieke Forum-identifier), `categorie` (enum: open-standaarden-met-status, gangbare-standaarden, in-onderzoek, vervallen), `status` (enum: opname, herbevestigd, in-procedure, archief), `domein` (array enum: identificatie, beveiliging, toegankelijkheid, documenten, koppelvlakken, semantiek, geo, archief, betalingen), `eigenaar` (string, bv. "IETF", "W3C", "Geonovum", "NEN", "Logius"), `versie` (string), `webURL` (uri naar forumstandaardisatie.nl pagina), `specificatieURL` (uri), `verplichteToepassingsgebied` (text — wanneer is het van toepassing?), `functioneleToepassing` (text), `werkingsgebied` (array enum: rijk, gemeente, provincie, waterschap, gemeenschappelijke-regelingen, zelfstandige-bestuursorganen, alle-overheden), `datumOpname` (date), `datumHerbevestiging` (date, optioneel), `vervangenDoor` (ref, optioneel), `relatieMetAndereStandaarden` (array refs), `lastSyncedAt` (datetime).

**Schema: `Toepassing`** — koppelt een Standaard aan een Object (applicatie/component/register/koppelvlak). Velden: `id` (uuid), `standaard` (ref), `object` (polymorphic ref — naar Applicatie, Component, Register, Source, etc.), `objectType` (string, schema-naam), `objectId` (string), `status` (enum: toegepast, gedeeltelijk-toegepast, niet-toegepast, niet-van-toepassing), `toelichting` (text — vereist bij `gedeeltelijk` of `niet-toegepast`), `afwijkingReden` (enum: kosten, geen-leverancier-ondersteuning, technisch-niet-mogelijk, in-realisatie, anders), `afwijkingsToelichting` (text), `eindstandDatum` (date — verwachte datum waarop afwijking opgeheven), `verantwoordelijke` (ref naar User), `vastgesteldOp` (datetime), `vastgesteldDoor` (ref naar User), `geldigVan` (date), `geldigTot` (date, optioneel), `revisieDatum` (date — wanneer moet dit opnieuw bekeken worden, default jaarlijks).

**Schema: `Evidence`** — bewijsstuk bij een Toepassing. Velden: `id` (uuid), `toepassing` (ref), `type` (enum: testresultaat, certificaat, audit-rapport, screenshot, configuratie-export, leverancier-verklaring, beleidsdocument), `naam` (string), `bestand` (uri naar object-storage), `mimeType` (string), `sha256` (string), `geldigVan` (date), `geldigTot` (date, optioneel), `uitgevoerdDoor` (string — partij die test/audit deed), `referentie` (string — bv. test-ID, certificaat-nummer), `uploadedAt` (datetime), `uploadedBy` (ref).

**Schema: `Rapportage`** — jaarlijkse pas-toe-of-leg-uit-rapportage. Velden: `id` (uuid), `jaar` (int), `organisatie` (ref naar Organisatie), `periode` (string, bv. `2026-Q1`), `gegenereerdOp` (datetime), `gegenereerdDoor` (ref), `status` (enum: concept, vastgesteld, ingediend), `vastgesteldDoor` (string, naam van CIO/bestuurder), `vastgesteldOp` (datetime), `pdfBestand` (uri), `jsonBestand` (uri), `metrics` (json — geaggregeerd: totaal standaarden, toegepast, gedeeltelijk, niet-toegepast, niet-van-toepassing), `ingediendBij` (string — bv. Forum Standaardisatie of intern), `terugkoppeling` (text — feedback van ontvanger).

**Uitbreidingen aan bestaande schema's**:
- `Applicatie` (softwarecatalog): extra veld `toepassingen` (array van Toepassing-refs).
- `Component` (softwarecatalog): idem.

## Requirements

**REQ-FOR-001: Initiële import en periodieke sync met Forum Standaardisatie**
Het systeem MOET de complete lijst van 115 standaarden importeren bij setup en deze elke 7 dagen automatisch syncen.
- GIVEN een fresh installatie, WHEN een beheerder `forum-sync --initial` triggert, THEN MOET het systeem alle 115 standaarden importeren via de Forum API (`/api/standaarden`), inclusief domein, status, eigenaar, werkingsgebied en URL's, binnen 5 minuten.
- GIVEN de wekelijkse cron loopt, WHEN er een nieuwe standaard is toegevoegd (bv. nieuwe IPv6-extensie) of een status is gewijzigd (in-procedure → opname), THEN MOET dat als delta worden gesynchroniseerd en MOET een notificatie naar de CISO worden gestuurd met de wijzigingen.
- GIVEN de Forum API is tijdelijk niet beschikbaar, WHEN de sync faalt, THEN MOET de connector retry met exponential backoff (3 pogingen over 24 uur) en daarna een alert genereren.

**REQ-FOR-002: Per Applicatie aangeven welke standaarden van toepassing zijn**
Een beheerder MOET per applicatie in de softwarecatalog kunnen aangeven welke standaarden van toepassing zijn en met welke status.
- GIVEN een Applicatie-detailpagina met tab "Standaarden", WHEN de beheerder de tab opent, THEN MOET het systeem suggesties tonen op basis van het type applicatie (bv. een webapplicatie krijgt automatisch TLS 1.3, HTTPS, WCAG 2.2 als suggesties).
- GIVEN de beheerder accepteert een suggestie en kiest `status: toegepast`, WHEN hij opslaat, THEN MOET een Toepassing-record worden aangemaakt met `vastgesteldOp: now()` en `verantwoordelijke: currentUser`.
- GIVEN de beheerder kiest `status: niet-toegepast`, WHEN hij opslaat, THEN MOET het systeem een verplicht `afwijkingReden` en `afwijkingsToelichting` afdwingen voordat opslaan slaagt.

**REQ-FOR-003: Status-indicators in lijst- en detailweergaves**
Alle weergaves van applicaties, componenten en registers MOETEN het standaarden-toepassings-niveau visueel weergeven.
- GIVEN een Applicatie-listview, WHEN deze laadt, THEN MOET elke rij een mini-indicator tonen ("12/15 standaarden toegepast") met kleurcodering (groen ≥90%, oranje 70-90%, rood <70%).
- GIVEN een Applicatie-detailpagina, WHEN deze laadt, THEN MOET een tabel met alle relevante standaarden worden getoond, gegroepeerd op domein, met status-badges en een "afwijking-onderbouwing"-link bij niet-toegepaste.
- GIVEN een publieke catalogus-pagina (voor burgers), WHEN deze laadt, THEN MOET een vereenvoudigde versie van de status worden getoond zonder interne verantwoordelijken of revisiedata.

**REQ-FOR-004: Verplichte audit-hook met evidence-link voor toegepaste standaarden**
Bij `status: toegepast` MOET het systeem optioneel (of verplicht, configureerbaar) een Evidence-koppeling vereisen.
- GIVEN configuratie `evidenceRequired: true` voor categorie "beveiliging", WHEN een beheerder TLS 1.3 als toegepast registreert zonder Evidence, THEN MOET opslaan falen met melding "Evidence vereist voor beveiligingsstandaarden".
- GIVEN een Evidence wordt geüpload (bv. een SSL Labs-rapport), WHEN deze wordt opgeslagen, THEN MOET het bestand SHA256-gehashed naar object-storage worden geschreven met retentiebeleid conform Archiefwet (minimaal 7 jaar voor compliance-evidence).
- GIVEN een Evidence is verlopen (`geldigTot < today`), WHEN de daily cron loopt, THEN MOET de bijbehorende Toepassing een waarschuwingsindicator krijgen en MOET de verantwoordelijke een notificatie krijgen voor hercertificering.

**REQ-FOR-005: Jaarlijkse rapportage-generator**
Het systeem MOET de jaarrapportage in het door Forum Standaardisatie verwachte formaat genereren (PDF + JSON conform Forum-template).
- GIVEN een CIO opent `/rapportage/genereer` en kiest jaar 2026, WHEN hij klikt op "Genereer", THEN MOET een Rapportage-record worden aangemaakt met aggregatie over alle applicaties, componenten en registers binnen de organisatie.
- GIVEN de rapportage is gegenereerd, WHEN deze wordt geopend, THEN MOET een PDF met huisstijl en een JSON-bestand conform Forum Standaardisatie-schema worden getoond, exporteerbaar voor indiening.
- GIVEN de CIO heeft de rapportage gereviewd en wil deze vaststellen, WHEN hij op "Vaststellen" klikt en zijn naam invult, THEN MOET `status: vastgesteld` worden gezet, de PDF cryptografisch worden ondertekend en MOET de versie immutable worden.

**REQ-FOR-006: Revisie-cyclus en herinnering**
Toepassingen MOETEN een revisiedatum hebben en automatisch herinneren als deze nadert.
- GIVEN een Toepassing met `revisieDatum: 2026-06-01`, WHEN de daily cron op 2026-05-01 loopt (30 dagen voor revisie), THEN MOET een notificatie naar de `verantwoordelijke` worden gestuurd.
- GIVEN de revisiedatum is gepasseerd zonder actie, WHEN de cron loopt op revisiedatum + 1, THEN MOET de Toepassing-status overgaan naar `revisie-vereist` en MOET een escalatie naar de CISO gaan.
- GIVEN een verantwoordelijke voert revisie uit en bevestigt "nog steeds van toepassing", WHEN hij opslaat, THEN MOET `vastgesteldOp` en `revisieDatum` worden vernieuwd (+12 maanden default).

**REQ-FOR-007: Inkoopondersteuning — standaarden voorschrijven bij aanbesteding**
Bij het aanmaken van een aanbesteding/inkooptraject MOET het systeem aanbevelingen doen voor verplichte standaarden.
- GIVEN een inkoper maakt een aanbesteding "Nieuwe zorgapplicatie" aan in opencatalogi, WHEN hij type "zorgapplicatie" selecteert, THEN MOET het systeem automatisch NEN 7510, TLS 1.3, SAML 2.0 en WCAG 2.2 als verplicht-toe-te-passen voorstellen.
- GIVEN de inkoper accepteert de suggesties, WHEN hij de aanbesteding publiceert, THEN MOET de aanbestedingstekst een gegenereerd "standaarden-blok" bevatten met juiste artikel-verwijzingen.
- GIVEN een nieuwe applicatie uit de aanbesteding wordt opgenomen in de catalogus, WHEN deze wordt aangemaakt, THEN MOET de aanbesteding-link worden bewaard en MOET het systeem direct Toepassing-records aanmaken op `status: toegepast` met evidence-vereiste.

**REQ-FOR-008: Cross-organisatie benchmark (geanonimiseerd)**
Het systeem MOET geanonimiseerde cross-organisatie benchmarks kunnen tonen (opt-in).
- GIVEN een organisatie heeft opt-in voor benchmarking, WHEN een CIO de benchmark-pagina opent, THEN MOET deze tonen hoe het toepassings-percentage van de eigen organisatie zich verhoudt tot de mediaan en het 75e percentiel van de deelnemers per standaard.
- GIVEN een organisatie zonder opt-in opent de pagina, WHEN deze laadt, THEN MOET alleen het eigen overzicht worden getoond met een banner "deel om benchmarks te zien".
- GIVEN data wordt gepubliceerd voor benchmarking, WHEN dat gebeurt, THEN MOET deze geen identificeerbare info bevatten (geen organisatienaam, geen specifieke applicatienamen).

**REQ-FOR-009: Importeren van nieuwe standaarden via Forum API**
Wanneer Forum Standaardisatie een nieuwe standaard toevoegt MOET het systeem deze importeren en automatisch flaggen voor review.
- GIVEN de wekelijkse sync detecteert dat NEN 7517 (medisch dossier delen) nieuw op de lijst staat, WHEN de import draait, THEN MOET een Standaard-record worden aangemaakt en MOET een notificatie naar de CISO worden gestuurd.
- GIVEN een nieuwe standaard is geïmporteerd, WHEN een beheerder de standaard-detailpagina opent, THEN MOET een lijst met suggested applications worden getoond (op basis van applicatie-type matching).
- GIVEN een standaard verandert van status `in-procedure` naar `opname`, WHEN deze verandering wordt gedetecteerd, THEN MOET een alert worden gegenereerd omdat alle relevante applicaties nu actief moeten worden voorzien van Toepassings-records.

**REQ-FOR-010: Publieke API voor transparantie**
De toepassings-status MOET via een publieke API beschikbaar zijn voor burgers, journalisten en toezichthouders.
- GIVEN een burger of journalist roept `GET /api/transparantie/toepassingen` aan, WHEN de API antwoordt, THEN MOET deze een geanonimiseerd overzicht teruggeven van standaarden en toepassings-status per applicatie (zonder interne verantwoordelijken).
- GIVEN een organisatie schakelt publieke API uit voor specifieke applicaties (bv. interne tools), WHEN de API wordt aangeroepen, THEN MOETEN deze niet in de response zitten en MOET een metadata-veld het aantal verborgen items aangeven.
- GIVEN een toezichthouder (BZK, AP, Algemene Rekenkamer) doet een geautoriseerde call met OAuth2-token, WHEN deze antwoordt, THEN MOET de volledige set inclusief afwijkingen en evidence-metadata worden teruggegeven (maar niet de evidence-bestanden zelf zonder aparte autorisatie).

## Standards & Sources

- **Forum Standaardisatie lijst** — forumstandaardisatie.nl/open-standaarden, gepubliceerd door BZK, beheerd door Logius. API beschikbaar via `https://www.forumstandaardisatie.nl/api/standaarden`.
- **Pas-toe-of-leg-uit beleid** — Instructie Rijksdienst (2008, herzien 2024), Aanwijzingen voor de departementen, en de Wet digitale overheid (artikel 3) die het regime nu wettelijk verankert.
- **Monitor pas-toe-of-leg-uit** — jaarrapportage van Forum Standaardisatie (laatste editie 2025), document-template beschikbaar voor inzending.
- **Wet digitale overheid (WDO)** — vastgesteld 2021, geleidelijk in werking. Artikelen 3 t/m 5 over open standaarden.
- **BIO (Baseline Informatiebeveiliging Overheid)** — verwijst naar Forum-standaarden voor beveiligingsmaatregelen.
- **NEN 2660-1:2022** — informatiemodellering, basis voor de Standaard- en Toepassing-modellen.
- **NEN 7510:2024** — zorgbeveiliging.
- **WCAG 2.2** — toegankelijkheid.
- **NEN 2082** — recordsmanagement, relevant voor evidence-retentie.
- **Archiefwet 1995** — retentie van evidence en rapportages.
- **JSON-LD 1.1** — voor publicatie van de standaarden-lijst als open dataset.
- **SAML 2.0 / OpenID Connect** — voor authenticatie van de publieke API toezichthouder-rol.

## Cross-app integration

- **opencatalogi** (base) — eigenaar van alle nieuwe schema's. UI integreert met bestaande Applicatie/Component-listviews via tabblad "Standaarden".
- **softwarecatalog** — Applicaties en Componenten krijgen de extra-velden. De softwarecatalog publiceert al naar de VNG-softwarecatalogus; deze rapportage wordt nu uitgebreid met toepassings-data.
- **openregister** — Registers in openregister kunnen ook Toepassing-records hebben (bv. een Register dat `Persoon` houdt heeft toepassing voor BRP-koppelvlak-standaarden).
- **openconnector** — externe Sources en Adapters krijgen Toepassing-records voor koppelvlak-standaarden (Digikoppeling, REST-API DesignRules, etc.).
- **gemma-gegevenscatalogus** (zustertspec) — relateert NEN 2660 (informatiemodellering) als Forum-standaard aan de GEMMA-mappings, zodat een GEMMA-mapping automatisch bewijs is voor NEN 2660-toepassing.
- **docudesk** — genereert de PDF-rapportage met huisstijl en cryptografische ondertekening.
- **mydash** — KPI's: toepassings-percentage per domein, aantal openstaande revisies, aantal verlopen evidence-bestanden, trend over tijd.
- **decidesk** — een bestuurlijk besluit tot afwijken van een standaard kan worden gekoppeld als evidence (verwijzing naar het raadsbesluit).
- **openklant** — gebruikt voor het beheer van leveranciers-contacten voor evidence-uitvraag.

## Target users

- **CISO / IBD-functionaris** (primair) — verantwoordelijk voor naleving van BIO en compliance-rapportages.
- **CIO en informatiemanager** — verantwoordelijk voor jaarlijkse pas-toe-of-leg-uit-rapportage richting bestuur en Forum.
- **Inkopers en aanbestedingsjuristen** — gebruiken het bij het schrijven van programma's van eisen.
- **Architecten en software-leveranciers** — krijgen vroegtijdig inzicht in welke standaarden moeten worden ondersteund.
- **Forum Standaardisatie / BZK / Logius** — krijgen real-time, gestructureerde, vergelijkbare data over toepassing in plaats van handmatige enquêtes.
- **Algemene Rekenkamer en lokale Rekenkamers** — kunnen geautomatiseerd compliance-onderzoek doen.
- **Burgers en journalisten** — zien via publieke API of "hun" gemeente standaarden toepast (transparantie).
- **Bestuurders (wethouders, gedeputeerden)** — krijgen via mydash high-level KPI's voor sturing.
- **Auditors (NOREA, externe IT-auditors)** — gebruiken het audit-spoor en evidence-archief voor compliance-audits.
- **VNG en IPO** — krijgen sectorale benchmarks voor beleidsvorming.
- **Standaardisatie-community (eigenaren van de standaarden zelf)** — IETF, W3C, Geonovum, NEN, Logius zien via geanonimiseerde benchmarks waar hun standaarden in de praktijk goed of slecht aanslaan en kunnen daar input uit halen voor herziening.
- **Aanbestedingsplatformen (TenderNed)** — kunnen via API geverifieerde "standaarden-blokken" inrichten zodat inkopers de juiste eisen automatisch krijgen.

## Implementatie-overwegingen

**Keuze 1: Polymorphic Toepassing-koppeling.** Een Standaard kan van toepassing zijn op een Applicatie, een Component, een Register, een Source of een externe Integration. Polymorphic ref (`object`, `objectType`, `objectId`) is hier de schoonste keuze; per type een aparte join-tabel zou bij 5+ doel-typen onbeheersbaar worden en queryen voor de jaarrapportage erg lastig maken.

**Keuze 2: Evidence als verplicht-bij-categorie, niet altijd verplicht.** Voor TLS 1.3 en HTTPS is technische evidence (een test-rapport) absoluut nodig; voor governance-standaarden (bv. NEN 2082 recordsmanagement) is een beleidsdocument als evidence voldoende. Per `categorie` configureren is een betere ergonomie dan one-size-fits-all.

**Keuze 3: Cryptografische ondertekening van vastgestelde rapportage.** Bij `status: vastgesteld` wordt de PDF gesigneerd met een gemeente-certificaat. Dit is een AVG-vriendelijke manier om aantoonbaar te maken dat de rapportage niet na vaststelling is gewijzigd; auditors kunnen offline verifiëren.

**Keuze 4: Forum API-sync voor master-lijst is one-way pull.** Het Forum is de eigenaar van de lijst — gemeenten/leveranciers schrijven niet terug. Voor uitbreidingen of feedback over standaarden gaat dat via het reguliere Forum-proces (officieel verzoek tot opname), niet via API-push.

**Keuze 5: Publieke API standaard aan, behalve voor expliciet geheim-gemarkeerde apps.** Transparantie is een centrale waarde van het pas-toe-of-leg-uit-regime; een opt-out (i.p.v. opt-in) is consistent met de bedoeling van het beleid.

## Out-of-scope (toekomstige iteraties)

Niet in v1: AI-suggesties voor afwijkingsverklaringen op basis van historische verklaringen van vergelijkbare organisaties (interessant maar speculatief, AVG-impact onduidelijk); integratie met automatische scanners (SSL Labs, mozilla observatory) die direct evidence kunnen uploaden — concrete vervolgspec waard; workflow-engine voor approval van afwijkingen door bestuurder (BPMN/CMMN-overkill voor v1, simpele direct-approve volstaat); geavanceerde benchmark-analytics (clustering van vergelijkbare organisaties, gap-analyse t.o.v. best-practice) — afhankelijk van voldoende benchmark-data; meertaligheid voor de standaarden-namen (Engelstalige rapportages voor internationale audits) — relevant voor grote G4-gemeenten met internationale relaties, niet urgent voor de fleet.
