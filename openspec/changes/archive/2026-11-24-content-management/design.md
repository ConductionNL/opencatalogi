# Design: Content Management

## Context

OpenCatalogi includes a lightweight CMS layer for managing static content on catalog websites consumed by external frontends (e.g. tilburg-woo-ui). Four content types are supported: pages (block-based static content), menus (hierarchical navigation), themes (publication categorisation cards), and glossary terms (definitions). All content types are stored as OpenRegister objects and served via public CORS-enabled API endpoints.

## Goals / Non-Goals

**Goals:**
- Define canonical schemas for Page, Menu, Theme, and Glossary entities
- Specify the four public REST APIs (list + single-item) with CORS support
- Formalise IAppConfig configuration keys for each content type
- Document group-based access control and login-visibility flags for pages and menus
- Specify fallback behaviour when IAppConfig keys are absent (menus: schema 7 / register 1)
- Document Solr bypass (`_source: database`) and publishing-workflow skip for glossary
- Provide Dutch seed data (3–5 objects per schema) for dev/test

**Non-Goals:**
- Write operations (create/update/delete) on CMS content via public API — admin UI only
- Full-text search indexing of CMS content via Solr
- Multi-language / i18n content variants
- Version history or publish/unpublish workflow for pages, menus, or themes

## Decisions

1. All four content types are stored as plain OpenRegister objects — no custom Entity/Mapper classes
2. Schema and register IDs are resolved at runtime from `IAppConfig`; hard-coded fallbacks only for menus (schema 7, register 1)
3. Glossary queries always include `_source: database` to avoid Solr dependency and `published=false` because glossary does not use the publishing workflow
4. CORS preflight is handled by a dedicated OPTIONS route for each endpoint, annotated `#[PublicPage] #[NoCSRFRequired]`
5. Facet unwrapping: if the `facets` key is present inside search results it is promoted to the top-level response key
6. Page slugs use pattern `^[a-z0-9-]+$`; the route pattern for `{slug}` uses `.+` to support nested slugs like `about/team`

## File Changes

### Backend

- `lib/Controller/PagesController.php` — `index()` and `show($slug)` actions + OPTIONS routes; `@PublicPage @NoCSRFRequired @CORS`
- `lib/Controller/MenusController.php` — `index()` and `show($id)` actions + OPTIONS routes; fallback to schema 7 / register 1
- `lib/Controller/ThemesController.php` — `index()` and `show($id)` actions + OPTIONS routes; facet unwrapping
- `lib/Controller/GlossaryController.php` — `index()` and `show($id)` actions + OPTIONS routes; `_source: database`, `published=false`
- `appinfo/routes.php` — public routes for all four content types; specific `{slug}` route BEFORE wildcard routes
- IAppConfig keys: `page_schema`, `page_register`, `menu_schema`, `menu_register`, `theme_schema`, `theme_register`, `glossary_schema`, `glossary_register`

### Frontend

- `src/views/PageIndex.vue` — list view at `/pages`, uses `CnIndexPage` + `useListView`
- `src/modals/ViewPageModal.vue` — read-only page detail with rendered content blocks
- `src/forms/PageContentForm.vue` — edit page content blocks
- `src/views/MenuIndex.vue` — list view at `/menus`
- `src/modals/ViewMenuModal.vue` — read-only menu tree view
- `src/forms/MenuItemForm.vue` — edit menu items
- `src/views/ThemeIndex.vue` — list view at `/themes`
- `src/modals/ThemeModal.vue` — create/edit theme
- `src/modals/ViewThemeModal.vue` — read-only theme detail
- `src/views/GlossaryIndex.vue` — list view at `/glossary`
- `src/modals/GlossaryModal.vue` — create/edit glossary term
- `src/modals/ViewGlossaryModal.vue` — read-only glossary term detail

## Reuse Analysis

| Existing capability | Used by | Notes |
|---|---|---|
| `ObjectService::searchObjectsPaginated()` | All four controllers | Primary data retrieval; no custom query builder needed |
| `IAppConfig` | All four controllers | Runtime schema/register resolution |
| `createObjectStore()` | Frontend Pinia stores | CRUD store generation; no custom store logic needed |
| `CnIndexPage` + `useListView` | All four index views | Handles search, pagination, filter state |
| `CnFormDialog` / `CnAdvancedFormDialog` | Modals | Schema-driven forms; no custom form component needed |
| `CnDataTable` | Index pages | Sortable, paginated list; no custom table |

No custom search endpoints, file upload handlers, or audit controllers are required. All data persistence goes through OpenRegister's `ObjectService`.

## Seed Data

Seed objects use the `@self` envelope and are loaded via `ConfigurationService::importFromApp()` alongside schemas. Re-importing is idempotent (matched by slug).

### Page Objects

```json
[
  {
    "@self": { "register": "cms", "schema": "page", "slug": "home" },
    "title": "Welkom bij het Tilburgse Publicatieportaal",
    "slug": "home",
    "contents": [
      {
        "type": "hero",
        "id": "hero-1",
        "data": { "heading": "Overheidsinformatie transparant", "body": "Vind alle openbare publicaties van de gemeente Tilburg." },
        "groups": [],
        "hideAfterLogin": false,
        "hideBeforeLogin": false
      },
      {
        "type": "text",
        "id": "text-1",
        "data": { "body": "Op dit portaal vindt u WOO-publicaties, vergunningen en beleidsstukken." },
        "groups": [],
        "hideAfterLogin": false,
        "hideBeforeLogin": false
      }
    ],
    "groups": [],
    "hideAfterLogin": false,
    "hideBeforeLogin": false
  },
  {
    "@self": { "register": "cms", "schema": "page", "slug": "over-ons" },
    "title": "Over ons",
    "slug": "over-ons",
    "contents": [
      {
        "type": "text",
        "id": "text-1",
        "data": { "heading": "Over de gemeente Tilburg", "body": "De gemeente Tilburg zet zich in voor transparantie en open overheid." },
        "groups": [],
        "hideAfterLogin": false,
        "hideBeforeLogin": false
      }
    ],
    "groups": [],
    "hideAfterLogin": false,
    "hideBeforeLogin": false
  },
  {
    "@self": { "register": "cms", "schema": "page", "slug": "contact" },
    "title": "Contact",
    "slug": "contact",
    "contents": [
      {
        "type": "text",
        "id": "text-1",
        "data": { "heading": "Neem contact op", "body": "Voor vragen over publicaties kunt u contact opnemen via gemeente@tilburg.nl." },
        "groups": [],
        "hideAfterLogin": false,
        "hideBeforeLogin": false
      }
    ],
    "groups": [],
    "hideAfterLogin": false,
    "hideBeforeLogin": false
  },
  {
    "@self": { "register": "cms", "schema": "page", "slug": "inloggen" },
    "title": "Inloggen",
    "slug": "inloggen",
    "contents": [
      {
        "type": "text",
        "id": "text-login",
        "data": { "body": "Log in om uw persoonlijke publicaties en verzoeken te bekijken." },
        "groups": [],
        "hideAfterLogin": true,
        "hideBeforeLogin": false
      }
    ],
    "groups": [],
    "hideAfterLogin": true,
    "hideBeforeLogin": false
  }
]
```

### Menu Objects

```json
[
  {
    "@self": { "register": "cms", "schema": "menu", "slug": "hoofdmenu" },
    "title": "Hoofdmenu",
    "position": 1,
    "items": [
      { "order": 1, "name": "Home", "link": "/", "description": "Startpagina", "icon": "home", "groups": [], "hideAfterLogin": false, "hideBeforeLogin": false, "items": [] },
      { "order": 2, "name": "Publicaties", "link": "/publicaties", "description": "Alle openbare publicaties", "icon": "file-document", "groups": [], "hideAfterLogin": false, "hideBeforeLogin": false, "items": [
        { "order": 1, "name": "WOO-verzoeken", "link": "/publicaties/woo", "description": "Wet open overheid", "icon": "scale-balance", "groups": [], "hideAfterLogin": false, "hideBeforeLogin": false, "items": [] },
        { "order": 2, "name": "Vergunningen", "link": "/publicaties/vergunningen", "description": "Verleende vergunningen", "icon": "check-decagram", "groups": [], "hideAfterLogin": false, "hideBeforeLogin": false, "items": [] }
      ]},
      { "order": 3, "name": "Over ons", "link": "/over-ons", "description": "Informatie over de gemeente", "icon": "information", "groups": [], "hideAfterLogin": false, "hideBeforeLogin": false, "items": [] },
      { "order": 4, "name": "Contact", "link": "/contact", "description": "Contactgegevens", "icon": "phone", "groups": [], "hideAfterLogin": false, "hideBeforeLogin": false, "items": [] }
    ],
    "groups": [],
    "hideBeforeLogin": false
  },
  {
    "@self": { "register": "cms", "schema": "menu", "slug": "voettermenu" },
    "title": "Voettermenu",
    "position": 2,
    "items": [
      { "order": 1, "name": "Privacy", "link": "/privacy", "description": "Privacyverklaring", "icon": "shield", "groups": [], "hideAfterLogin": false, "hideBeforeLogin": false, "items": [] },
      { "order": 2, "name": "Toegankelijkheid", "link": "/toegankelijkheid", "description": "Toegankelijkheidsverklaring", "icon": "eye", "groups": [], "hideAfterLogin": false, "hideBeforeLogin": false, "items": [] },
      { "order": 3, "name": "Cookies", "link": "/cookies", "description": "Cookiebeleid", "icon": "cookie", "groups": [], "hideAfterLogin": false, "hideBeforeLogin": false, "items": [] }
    ],
    "groups": [],
    "hideBeforeLogin": false
  },
  {
    "@self": { "register": "cms", "schema": "menu", "slug": "accountmenu" },
    "title": "Accountmenu",
    "position": 3,
    "items": [
      { "order": 1, "name": "Mijn verzoeken", "link": "/mijn/verzoeken", "description": "Uw ingediende verzoeken", "icon": "account", "groups": [], "hideAfterLogin": false, "hideBeforeLogin": true, "items": [] },
      { "order": 2, "name": "Uitloggen", "link": "/logout", "description": "Sessie beëindigen", "icon": "logout", "groups": [], "hideAfterLogin": false, "hideBeforeLogin": true, "items": [] }
    ],
    "groups": [],
    "hideBeforeLogin": true
  }
]
```

### Theme Objects

```json
[
  {
    "@self": { "register": "cms", "schema": "theme", "slug": "woo-verzoeken" },
    "title": "WOO-verzoeken",
    "summary": "Publicaties op grond van de Wet open overheid",
    "description": "Alle documenten die openbaar zijn gemaakt naar aanleiding van een WOO-verzoek of actieve openbaarmaking door de gemeente Tilburg.",
    "image": "/apps/opencatalogi/img/themes/woo.png",
    "content": "<p>Bekijk alle WOO-publicaties van de gemeente Tilburg.</p>",
    "link": "Bekijk publicaties",
    "url": "/publicaties/woo",
    "icon": "scale-balance",
    "isExternal": false,
    "sort": 1
  },
  {
    "@self": { "register": "cms", "schema": "theme", "slug": "vergunningen" },
    "title": "Vergunningen",
    "summary": "Verleende omgevings- en andere vergunningen",
    "description": "Zoek in de verleende vergunningen van de gemeente Tilburg, inclusief omgevingsvergunningen, evenementenvergunningen en ontheffingen.",
    "image": "/apps/opencatalogi/img/themes/vergunningen.png",
    "content": "<p>Vind vergunningsinformatie op naam, adres of type.</p>",
    "link": "Zoek vergunningen",
    "url": "/publicaties/vergunningen",
    "icon": "check-decagram",
    "isExternal": false,
    "sort": 2
  },
  {
    "@self": { "register": "cms", "schema": "theme", "slug": "beleidsstukken" },
    "title": "Beleidsstukken",
    "summary": "Vastgesteld beleid en beleidsnotities",
    "description": "Raadpleeg het vastgestelde beleid van de gemeente Tilburg, van structuurvisies tot uitvoeringsplannen.",
    "image": "/apps/opencatalogi/img/themes/beleid.png",
    "content": "<p>Lees hoe de gemeente haar taken uitvoert en welk beleid daarvoor geldt.</p>",
    "link": "Lees beleidsstukken",
    "url": "/publicaties/beleid",
    "icon": "file-document-outline",
    "isExternal": false,
    "sort": 3
  },
  {
    "@self": { "register": "cms", "schema": "theme", "slug": "open-data" },
    "title": "Open Data",
    "summary": "Datasets en statistieken van de gemeente",
    "description": "Download open datasets van de gemeente Tilburg, beschikbaar voor hergebruik onder een open licentie.",
    "image": "/apps/opencatalogi/img/themes/opendata.png",
    "content": "<p>Datasets zijn beschikbaar in CSV, JSON en XML formaat.</p>",
    "link": "Download datasets",
    "url": "https://data.tilburg.nl",
    "icon": "database",
    "isExternal": true,
    "sort": 4
  }
]
```

### Glossary Objects

```json
[
  {
    "@self": { "register": "cms", "schema": "glossary", "slug": "woo" },
    "title": "WOO",
    "summary": "Wet open overheid — vervangt de WOB en verplicht bestuursorganen tot actieve openbaarmaking.",
    "description": "De Wet open overheid (WOO) is op 1 mei 2022 in werking getreden als opvolger van de Wet openbaarheid van bestuur (WOB). De wet verplicht bestuursorganen om overheidsinformatie actief openbaar te maken. Burgers, bedrijven en journalisten kunnen daarnaast een verzoek indienen om specifieke documenten openbaar te maken.",
    "externalLink": "https://www.rijksoverheid.nl/onderwerpen/wet-open-overheid-woo",
    "keywords": ["wet open overheid", "WOB", "openbaarmaking", "FOIA", "transparantie"]
  },
  {
    "@self": { "register": "cms", "schema": "glossary", "slug": "bestuursorgaan" },
    "title": "Bestuursorgaan",
    "summary": "Een orgaan van een rechtspersoon die krachtens publiekrecht is ingesteld, of een ander persoon of college met openbaar gezag bekleed.",
    "description": "Bestuursorganen zijn organisaties of personen die publiekrechtelijk gezag uitoefenen, zoals gemeenten, provincies, ministeries, maar ook zelfstandige bestuursorganen (ZBO's) zoals de Belastingdienst. De WOO is van toepassing op bestuursorganen.",
    "externalLink": "https://wetten.overheid.nl/BWBR0005537/",
    "keywords": ["overheid", "ZBO", "publiekrechtelijk", "gezag", "gemeente", "ministerie"]
  },
  {
    "@self": { "register": "cms", "schema": "glossary", "slug": "weigeringsgrond" },
    "title": "Weigeringsgrond",
    "summary": "Wettelijke grond op basis waarvan openbaarmaking van informatie geheel of gedeeltelijk kan worden geweigerd.",
    "description": "De WOO kent absolute en relatieve weigeringsgronden (artikel 5.1 en 5.2). Absolute gronden, zoals bescherming van de eenheid van de Kroon of staatsveiligheid, geven geen ruimte voor belangenafweging. Relatieve gronden, zoals persoonlijke beleidsopvattingen, vereisen een afweging tussen het belang van openbaarmaking en het belang van bescherming.",
    "externalLink": "https://wetten.overheid.nl/BWBR0045754/",
    "keywords": ["artikel 5.1", "artikel 5.2", "openbaarmaking weigeren", "persoonlijke levenssfeer", "staatsgeheim"]
  },
  {
    "@self": { "register": "cms", "schema": "glossary", "slug": "inventarislijst" },
    "title": "Inventarislijst",
    "summary": "Overzicht van alle documenten in een WOO-verzoek met per document de beoordelingsstatus.",
    "description": "De inventarislijst is een verplicht onderdeel van het WOO-besluit. Het document somt alle documenten op die zijn beoordeeld in het kader van het verzoek, met per document de beoordeling (openbaar, deels openbaar of niet openbaar) en de eventuele weigeringsgronden.",
    "externalLink": null,
    "keywords": ["WOO-besluit", "documentoverzicht", "beoordeling", "openbaar", "niet openbaar"]
  },
  {
    "@self": { "register": "cms", "schema": "glossary", "slug": "cors" },
    "title": "CORS",
    "summary": "Cross-Origin Resource Sharing — mechanisme waarmee browsers verzoeken van andere domeinen kunnen autoriseren.",
    "description": "CORS staat voor Cross-Origin Resource Sharing en is een beveiligingsmechanisme dat browsers toepassen bij HTTP-verzoeken naar een ander domein dan de herkomstpagina. Openbare API-eindpunten van OpenCatalogi sturen CORS-headers mee zodat externe frontends zoals tilburg-woo-ui de gegevens mogen opvragen.",
    "externalLink": "https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS",
    "keywords": ["cross-origin", "browser", "beveiligingsbeleid", "HTTP-headers", "preflight", "OPTIONS"]
  }
]
```
