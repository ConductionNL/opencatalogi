# OpenCatalogi — Overheidsfunctionaliteiten

> Functiepagina voor Nederlandse overheidsorganisaties.
> Gebruik deze checklist om te toetsen aan uw Programma van Eisen.

**Product:** OpenCatalogi
**Categorie:** Gefedereerde catalogi & open data publicatie
**Licentie:** AGPL (vrije open source)
**Leverancier:** Conduction B.V. / Acato
**Platform:** Nextcloud + Open Register (self-hosted / on-premise / cloud)

## Legenda

| Status | Betekenis |
|--------|-----------|
| Beschikbaar | Functionaliteit is beschikbaar in de huidige versie |
| Gepland | Functionaliteit staat op de roadmap |
| Via platform | Functionaliteit wordt geleverd door Nextcloud / OpenRegister |
| Op aanvraag | Beschikbaar als maatwerk |
| N.v.t. | Niet van toepassing voor dit product |

---

## 1. Functionele eisen

### Catalogusbeheer

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| F-01 | Publicatieoverzichten beheren | Beschikbaar | Listings met volledige metadata |
| F-02 | Metadata-beheer (categorieën, tags, organisatie) | Beschikbaar | Gestructureerde metadata |
| F-03 | Zoeken met filters en facetten | Beschikbaar | Full-text zoeken via OpenRegister |
| F-04 | Publicatie-workflow (concept → gepubliceerd) | Beschikbaar | Levenscyclus per listing |
| F-05 | Organisatiebeheer | Beschikbaar | Organisaties als eigenaar van publicaties |

### Federatie & Synchronisatie

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| F-06 | Gefedereerde synchronisatie tussen organisaties | Beschikbaar | Cross-organisatie catalogus-uitwisseling |
| F-07 | Import/merge van externe catalogi | Beschikbaar | Geautomatiseerde bron-sync |
| F-08 | Directory-synchronisatie | Beschikbaar | Cron-gebaseerde sync met externe directories |
| F-09 | Bronconfiguratie (externe databronnen) | Beschikbaar | Configureerbare externe bronnen |

### Open Data

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| F-10 | Open data publicatie via API | Beschikbaar | Gestandaardiseerde API-endpoints |
| F-11 | DCAT-ondersteuning | Beschikbaar | Data Catalogue Vocabulary standaard |
| F-12 | Schema.org metadata | Beschikbaar | Linked data standaard |
| F-13 | Publiek toegankelijke catalogus | Beschikbaar | Geen authenticatie vereist voor lezen |

---

## 2. Technische eisen

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| T-01 | On-premise / self-hosted installatie | Beschikbaar | Nextcloud-app |
| T-02 | Open source (broncode beschikbaar) | Beschikbaar | AGPL, GitHub |
| T-03 | RESTful API | Via platform | OpenRegister REST API |
| T-04 | Cron-gebaseerde synchronisatie | Beschikbaar | System cron vereist |
| T-05 | Database-onafhankelijkheid | Via platform | PostgreSQL, MySQL, SQLite |
| T-06 | Containerisatie (Docker) | Beschikbaar | Docker Compose |

---

## 3. Beveiligingseisen

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| B-01 | RBAC | Via platform | OpenRegister RBAC |
| B-02 | Audit trail | Via platform | OpenRegister mutatie-historie |
| B-03 | BIO-compliance | Via platform | Nextcloud BIO |
| B-04 | 2FA | Via platform | Nextcloud 2FA |
| B-05 | SSO / SAML / LDAP | Via platform | Nextcloud SSO |

---

## 4. Privacyeisen (AVG/GDPR)

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| P-01 | Open data — geen persoonsgegevens | Beschikbaar | Catalogi bevatten alleen publieke metadata |
| P-02 | Data minimalisatie | Beschikbaar | Alleen catalogus-metadata, geen PII |
| P-03 | Verwerkingsovereenkomsten | Op aanvraag | Bij federatie met andere organisaties |

---

## 5. Toegankelijkheidseisen

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| A-01 | WCAG 2.1 AA | Beschikbaar | Nextcloud-componenten |
| A-02 | EN 301 549 | Beschikbaar | Via WCAG AA |
| A-03 | Toetsenbordnavigatie | Beschikbaar | Volledig navigeerbaar |
| A-04 | NL Design System | Beschikbaar | Via NL Design app |
| A-05 | Meertalig (NL/EN) | Beschikbaar | Volledige vertaling |

---

## 6. Integratiestandaarden

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| I-01 | Common Ground architectuur | Beschikbaar | Laag 3 (integratie) — catalogus als federatielaag |
| I-02 | DCAT (Data Catalogue Vocabulary) | Beschikbaar | Open data standaard |
| I-03 | Schema.org | Beschikbaar | Linked data metadata |
| I-04 | OpenRegister-integratie | Beschikbaar | Data opgeslagen als OpenRegister objecten |
| I-05 | Gefedereerde synchronisatie | Beschikbaar | Cross-organisatie data-uitwisseling |
| I-06 | REST API | Beschikbaar | Publieke API voor externe consumptie |

---

## 7. Archivering

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| AR-01 | Versiebeheer van publicaties | Via platform | OpenRegister mutatie-historie |
| AR-02 | Publicatie-metadata | Beschikbaar | Gestructureerde metadata per listing |

---

## 8. Beheer en onderhoud

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| BO-01 | Nextcloud App Store | Beschikbaar | Installatie via App Store |
| BO-02 | Automatische updates | Beschikbaar | Via Nextcloud app-updater |
| BO-03 | Beheerderspaneel | Beschikbaar | Nextcloud admin settings |
| BO-04 | Documentatie | Beschikbaar | GitBook docs |
| BO-05 | Open source community | Beschikbaar | GitHub Issues |
| BO-06 | Professionele ondersteuning (SLA) | Op aanvraag | Via Conduction B.V. |

---

## 9. Onderscheidende kenmerken

| Kenmerk | Toelichting |
|---------|-------------|
| **Gefedereerd** | Catalogi automatisch synchroniseren tussen organisaties |
| **Open data native** | DCAT/Schema.org uit de doos |
| **Nextcloud-native** | Geen apart portaal — draait in uw bestaande omgeving |
| **Common Ground** | Past in de Common Ground architectuur |
| **Data-hergebruik** | Catalogus-data herbruikbaar door andere apps |
