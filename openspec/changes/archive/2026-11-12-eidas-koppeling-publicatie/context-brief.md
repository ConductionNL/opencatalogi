## Status

Draft — opencatalogi spec brief, 2026-05-21.

# eIDAS Koppeling — Publicatie Dienstencatalogus

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Koppelvlakken > eIDAS dienstencatalogus / Koppelvlakken

**Rationale:** Dienstencatalogus publication  
_Source: /tmp/ia-doc-dec-cat-conn.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

opencatalogi houdt een gestructureerde catalogus van publieke diensten — voor een gemeente bijvoorbeeld "rijbewijs aanvragen", "verhuizing doorgeven", "kapvergunning indienen". Vandaag is die catalogus voornamelijk binnenlands gepubliceerd (op de gemeente-website, op Overheid.nl, in PDC's). De Single Digital Gateway (SDG) regulation en de eIDAS verordening verplichten echter dat veel van die diensten ook EU-breed vindbaar zijn, met machine-leesbare metadata, en dat ze cross-border benaderbaar zijn voor burgers en bedrijven uit andere lidstaten (een Pool die in NL wil verhuizen moet de dienst kunnen vinden en — voor cruciale diensten van het eIDAS-actie-7-mandaat — ook online kunnen afnemen). Deze spec voegt aan opencatalogi een eIDAS-publicatielaag toe: per dienst registreren we het eIDAS-relevante type, de scope (lokaal/regionaal/nationaal/grensoverschrijdend), het minimum-assurance-niveau (laag/substantieel/hoog), de talen-ondersteuning, het Your-Europe-portal-blok, en de SAML-metadata voor het eIDAS-knooppunt (BRP-Identificatie/Logius). Een sync-pipeline pushed de metadata naar het nationaal Your-Europe-aansluitpunt en — voor diensten die federatief identificeren — naar het eIDAS-knooppunt.

## Data Model

- **DienstPublicatie**: dienst, scope (lokaal/regionaal/nationaal/grensoverschrijdend), publicatie-status (concept/intern/gepubliceerd/ingetrokken), publicatie-doelen (lijst: website/overheid-nl/your-europe/eidas), publicatie-datum.
- **EidasMetadata**: dienstpublicatie, sdg-bijlage (I/II/III), procedure-categorie (registratie/identificatie/document/betaling), minimum-assurance-niveau (low/substantial/high), grensoverschrijdend (ja/nee), talen (lijst: nl/en/de/fr/...).
- **SamlMetadata**: dienst-eindpunt-url, sp-entity-id, certificate-ref, supported-attributes (BSN/eIDAS-MDS-naturalperson/eIDAS-MDS-legalperson), assertion-endpoint, signing-cert, encryption-cert.
- **CrossBorderProces**: dienstpublicatie, supported-input-credentials, vertaal-status, ondersteuning-via (vertaal-API/extern-loket/menselijk-contact).
- **PublicatieLog**: dienstpublicatie, doel, payload-hash, response-status, tijdstip, fouten.
- **TaalVariant**: dienst, taal, titel, beschrijving, procedure-stappen, vertaalbron (handmatig/eTranslation/professioneel).

## Requirements

**REQ-001: SDG-classificatie verplicht per dienst.** GIVEN een dienst die in aanmerking komt voor SDG-publicatie, WHEN een redacteur de dienst markeert voor publicatie-doel=your-europe, THEN opencatalogi verplicht selectie van sdg-bijlage (I/II/III) en procedure-categorie; zonder deze klassificatie kan de publicatie niet naar status=gepubliceerd.

**REQ-002: Engelstalige variant verplicht voor grensoverschrijdend.** GIVEN een DienstPublicatie met scope=grensoverschrijdend, WHEN publicatie wordt geactiveerd, THEN er moet minimaal een TaalVariant in `en` bestaan voor titel, beschrijving en procedure-stappen; bij ontbreken wordt eTranslation (CEF Digital eTranslation API) als concept-vertaling aangeboden ter handmatige review voordat publicatie doorgaat.

**REQ-003: Assurance-niveau koppelen aan authenticatie-vereiste.** GIVEN een dienst met EidasMetadata.minimum_assurance_niveau=high, WHEN de dienst online wordt aangeboden, THEN het achterliggende authenticatie-eindpunt moet eIDAS-high notified means accepteren (bv. DigiD Hoog, of een notified eIDAS-eID uit een andere lidstaat); opencatalogi valideert dit tegen de SamlMetadata van de dienst en blokkeert publicatie bij mismatch.

**REQ-004: SAML metadata-uitwisseling met eIDAS-knooppunt.** GIVEN een dienst die federatief identificeert via het Nederlandse eIDAS-knooppunt (Logius), WHEN de SamlMetadata wordt opgeslagen, THEN opencatalogi genereert een conforme SAML 2.0 SP-metadata-XML (met signing- en encryption-certificaten, supported-attribute-list conform eIDAS-MDS) en biedt deze aan via een vaste publieke URL voor automatische ingest door het knooppunt; wijzigingen versturen een metadata-refresh-notificatie.

**REQ-005: Publicatie-fan-out naar meerdere doelen.** GIVEN een DienstPublicatie met publicatie-doelen=[website, overheid-nl, your-europe, eidas], WHEN de publicatie geactiveerd wordt, THEN opencatalogi vuurt per doel een aparte push (REST, SOAP of OData afhankelijk van het doel), legt iedere response vast in PublicatieLog, en markeert de publicatie pas als volledig succesvol wanneer alle doelen geslaagd zijn; bij gedeeltelijke fail wordt de status gepubliceerd_met_waarschuwing.

**REQ-006: eTranslation-integratie voor 24 EU-talen.** GIVEN een dienst die in nl is geredigeerd, WHEN de redacteur "vertaal naar EU-talen" kiest, THEN opencatalogi roept de CEF eTranslation REST-API aan voor de geselecteerde talen, ontvangt asynchroon vertaalde TaalVarianten gemarkeerd als vertaalbron=eTranslation en status=concept; varianten moeten door een reviewer worden bekrachtigd voordat ze cross-border gepubliceerd worden.

**REQ-007: Once-Only-Principle voorbereiding.** GIVEN een grensoverschrijdende dienst die documenten vereist (bv. uittreksel uit het Poolse handelsregister), WHEN de DienstPublicatie wordt geconfigureerd, THEN opencatalogi documenteert welke evidence-types via het OOTS (Once-Only Technical System) opvraagbaar zijn, registreert het OOTS-evidence-broker-eindpunt, en publiceert dit als CrossBorderProces-config zodat een burger niet meer fysiek documenten hoeft te uploaden.

**REQ-008: Quality-feedback-loop per gepubliceerde dienst.** GIVEN een gepubliceerde dienst op Your-Europe-portal, WHEN een gebruiker daar feedback geeft (rating, opmerking), THEN opencatalogi haalt de feedback periodiek op via de Your-Europe-feedback-API en presenteert deze aan de dienst-eigenaar binnen opencatalogi, gekoppeld aan de DienstPublicatie, zodat content-verbetering op één plek beheerd wordt.

## Standards

- **eIDAS-verordening (EU 910/2014)** en herziening eIDAS 2.0 (EUDI Wallet).
- **SDG-verordening (EU 2018/1724)** — Single Digital Gateway.
- **OOTS — Once-Only Technical System (EU 2022/1463)**.
- **SAML 2.0 + eIDAS-SAML-profile** voor federatieve authenticatie.
- **eIDAS Minimum Data Set (MDS)** voor natural en legal persons.
- **CEF eTranslation API** voor machine-vertaling.
- **DCAT-AP, ADMS, CPSV-AP** (Core Public Service Vocabulary Application Profile) — semantische beschrijving van publieke diensten.
- **NORA / DSO / Common Ground** — Nederlandse interoperabiliteits-context.
- **Wet digitale overheid (Wdo)** — Nederlandse implementatie eIDAS.

## Cross-app

- **openregister** — DienstPublicatie / EidasMetadata / SamlMetadata / TaalVariant schemas; eindpunt voor publieke metadata-XML.
- **openconnector** — adapters naar Your-Europe-API, eIDAS-knooppunt Logius, OOTS-evidence-broker, eTranslation REST.
- **docudesk** — archivering van gepubliceerde versies (welke metadata stond wanneer gepubliceerd).
- **decidesk** — formeel besluit voor het in productie nemen van grensoverschrijdende dienstverlening (data-protection-impact-assessment, verwerkers-overeenkomst).
- **softwarecatalog** — bovenliggende publicatie van software-componenten die de dienst leveren (CPSV-AP koppeling naar componenten).

## Target users

- **Catalogus-redacteur / contentbeheerder** — diensten redigeren, klassificeren, vertalingen reviewen.
- **Dienst-eigenaar (vakafdeling)** — inhoudelijk verantwoordelijk; reageert op Your-Europe-feedback.
- **eIDAS-functioneel beheerder / Logius-contactpersoon** — SAML-metadata beheren, certificaten verlengen.
- **Privacy officer / FG** — beoordeelt grensoverschrijdende gegevensuitwisseling.
- **EU-burger / EU-onderneming** — eindgebruiker via Your-Europe-portal; verwacht dienst-info in eigen taal en cross-border-afname.
- **Strategisch beleid digitale dienstverlening** — bewaakt SDG-compliance-percentage en monitort dekking.
