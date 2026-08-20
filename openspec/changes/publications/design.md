# Design: publications

## Architecture Overview

Publications are served through a dedicated public controller (`PublicationsController`) that wraps OpenRegister's `ObjectService` and OpenCatalogi's `CatalogiService` and `PublicationService`. All endpoints are unauthenticated (`#[PublicPage]`, `#[NoCSRFRequired]`) and carry CORS headers so external frontends can call them cross-origin.

```
External frontend (tilburg-woo-ui, etc.)
        │
        │  GET /api/{catalogSlug}
        │  GET /api/{catalogSlug}/{id}
        │  GET /api/{catalogSlug}/{id}/attachments
        │  GET /api/{catalogSlug}/{id}/download
        │  GET /api/{catalogSlug}/{id}/uses
        │  GET /api/{catalogSlug}/{id}/used
        ▼
PublicationsController                         (lib/Controller/PublicationsController.php)
    │
    ├── CatalogiService::getCatalogBySlug()    catalog resolution + in-process cache
    │       ▼
    │   ObjectService::searchObjectsPaginated() (list endpoint: _schemas, _register, _rbac)
    │   ObjectService::find()                  (single-object fast path)
    │   ObjectService::renderEntity()          (expand related objects via _extend)
    │   ObjectService::getObjectUses()         (/uses endpoint)
    │   ObjectService::getObjectUsedBy()       (/used endpoint)
    │
    ├── PublicationService::attachments()      (/attachments endpoint)
    │   PublicationService::download()         (/download endpoint)
    │
    └── IDBConnection (direct SQL)             findObjectLocation fallback
            ▼
        information_schema.tables → UNION ALL across oc_openregister_table_*
```

## Route ordering constraint

The wildcard `{catalogSlug}` routes use the regex `[a-z0-9-]+` and **must appear last** in `appinfo/routes.php`. Placing them before named routes like `/api/themes` or `/api/glossary` would cause the catalog slug pattern to absorb those paths.

```php
// appinfo/routes.php — order matters
['name' => 'themes#index',      'url' => '/api/themes', ...],   // specific first
['name' => 'glossary#index',    'url' => '/api/glossary', ...],
// ... all other named routes ...
['name' => 'publications#list', 'url' => '/api/{catalogSlug}',  // wildcard last
 'verb' => 'GET', 'requirements' => ['catalogSlug' => '[a-z0-9-]+']]
```

## findObjectLocation — magic table fallback

When the primary path (catalog's registered schemas/registers) does not contain the requested UUID, the controller falls back to scanning every `oc_openregister_table_*` table via a single UNION ALL query. This handles moved objects and stale catalog configurations.

```sql
(SELECT 1 AS register_id, 2 AS schema_id
   FROM oc_openregister_table_1_2 WHERE _uuid = :uuid)
UNION ALL
(SELECT 1 AS register_id, 3 AS schema_id
   FROM oc_openregister_table_1_3 WHERE _uuid = :uuid)
...
LIMIT 1
```

Table name parsing: `oc_openregister_table_(\d+)_(\d+)` → register_id, schema_id.

## extractFilterValues — filter syntax normaliser

Converts the various filter formats accepted by the public API into a uniform `int[]`:

| Input format | Example | Output |
|---|---|---|
| Single numeric | `1` | `[1]` |
| Simple array | `[1, 2, 3]` | `[1, 2, 3]` |
| `{ "or": [1,2,3] }` | OR array | `[1, 2, 3]` |
| `{ "or": "1,2,3" }` | OR string | `[1, 2, 3]` |
| `{ "and": [1,2] }` | AND array | `[1, 2]` |
| `{ "and": "1,2" }` | AND string | `[1, 2]` |
| Comma-separated string | `"1,2,3"` | `[1, 2, 3]` |

The `[or]` query parameter syntax used by tilburg-woo-ui (`?schemas[or]=1,2,3`) flows through this normaliser before being passed to `ObjectService::searchObjectsPaginated`.

## Multi-schema catalog: universal ordering restriction

When a catalog spans multiple schemas whose properties differ (e.g., one schema uses `name`, another uses `naam`), ordering by schema-specific fields is rejected. Only the OpenRegister universal fields are permitted:

```
uuid | created | updated | published | depublished
```

Non-universal `_order` parameters are stripped before the `searchObjectsPaginated` call.

## API endpoint table

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/{catalogSlug}` | `#[PublicPage]` | Paginated list with facets; adds `@catalog` metadata |
| GET | `/api/{catalogSlug}/{id}` | `#[PublicPage]` | Single publication; fast-path + fallback location |
| GET | `/api/{catalogSlug}/{id}/attachments` | `#[PublicPage]` | File metadata for attached files |
| GET | `/api/{catalogSlug}/{id}/download` | `#[PublicPage]` | Stream file download |
| GET | `/api/{catalogSlug}/{id}/uses` | `#[PublicPage]` | Outgoing object relations |
| GET | `/api/{catalogSlug}/{id}/used` | `#[PublicPage]` | Incoming object relations |
| OPTIONS | `/api/{catalogSlug}` | `#[PublicPage]` | CORS preflight |
| OPTIONS | `/api/{catalogSlug}/{id}` | `#[PublicPage]` | CORS preflight |
| OPTIONS | `/api/{catalogSlug}/{id}/uses` | `#[PublicPage]` | CORS preflight |
| OPTIONS | `/api/{catalogSlug}/{id}/used` | `#[PublicPage]` | CORS preflight |
| OPTIONS | `/api/{catalogSlug}/{id}/attachments` | `#[PublicPage]` | CORS preflight |
| OPTIONS | `/api/{catalogSlug}/{id}/download` | `#[PublicPage]` | CORS preflight |

All `{catalogSlug}` patterns carry `requirements: ['catalogSlug' => '[a-z0-9-]+']`.

## Reuse Analysis

This change delegates all data access to existing OpenRegister and OpenCatalogi services. No custom data layer is built.

| Capability | Provided by | Usage |
|---|---|---|
| Paginated object search | `ObjectService::searchObjectsPaginated` | List endpoint |
| Single object retrieval | `ObjectService::find` + `renderEntity` | Detail endpoint |
| Relation traversal | `ObjectService::getObjectUses` / `getObjectUsedBy` | uses/used endpoints |
| File metadata | `PublicationService::attachments` | attachments endpoint |
| File streaming | `PublicationService::download` | download endpoint |
| Catalog resolution | `CatalogiService::getCatalogBySlug` | All endpoints |
| CORS headers | Nextcloud response helpers | All public endpoints |

`findObjectLocation` is the only bespoke query in this controller; it exists because `ObjectService` has no cross-schema fallback scan. `extractFilterValues` is a small normalisation utility with no equivalent in OpenRegister's public API. Both are private methods — candidates for extraction to `PublicationService` in a future cleanup.

## Seed Data

`lib/Settings/publication_register.json` must include 3–5 realistic Dutch publication objects under `components.objects[]` so the app is testable immediately after installation.

**Object 1 — WOO verzoek besluit (Gemeente Westerveld)**
```json
{
  "@self": {
    "register": "publication-register",
    "schema": "publication",
    "slug": "pub-woo-2025-001"
  },
  "title": "WOO-besluit inzake vergunningverlening Industrieweg 14",
  "summary": "Besluit op WOO-verzoek over verleende omgevingsvergunning aan Bouwbedrijf De Vries BV.",
  "description": "Op 12 februari 2025 ontving de gemeente een verzoek om openbaarmaking van alle documenten betreffende de omgevingsvergunning voor Industrieweg 14 te Diever. Het college heeft besloten de stukken openbaar te maken met uitzondering van bedrijfsvertrouwelijke informatie.",
  "organization": "gemeente-westerveld",
  "themes": ["woo", "omgevingsrecht"]
}
```

**Object 2 — Raadsbesluit (Gemeente Meppel)**
```json
{
  "@self": {
    "register": "publication-register",
    "schema": "publication",
    "slug": "pub-raad-2025-003"
  },
  "title": "Raadsbesluit vaststelling bestemmingsplan Buitengebied 2025",
  "summary": "De gemeenteraad van Meppel heeft op 27 maart 2025 het bestemmingsplan Buitengebied 2025 vastgesteld.",
  "description": "Het bestemmingsplan Buitengebied 2025 vervangt het bestemmingsplan uit 2012 en integreert de wijzigingen uit de omgevingsvisie 2022. Het plan is opgesteld conform de Omgevingswet en is digitaal raadpleegbaar via het Omgevingsloket.",
  "organization": "gemeente-meppel",
  "themes": ["ruimtelijke-ordening", "bestemmingsplan"]
}
```

**Object 3 — Jaarverslag (Gemeente Hoogeveen)**
```json
{
  "@self": {
    "register": "publication-register",
    "schema": "publication",
    "slug": "pub-jaarverslag-2024"
  },
  "title": "Jaarverslag en Jaarrekening 2024 Gemeente Hoogeveen",
  "summary": "Het jaarverslag en de jaarrekening 2024 van de gemeente Hoogeveen, vastgesteld door de gemeenteraad op 19 juni 2025.",
  "description": "Dit document bevat het bestuurlijk jaarverslag, de programmaverantwoording en de jaarrekening 2024. Het financieel resultaat over 2024 bedraagt een positief saldo van €1.247.000.",
  "organization": "gemeente-hoogeveen",
  "themes": ["financien", "verantwoording"]
}
```

**Object 4 — Subsidieregeling (Provincie Drenthe)**
```json
{
  "@self": {
    "register": "publication-register",
    "schema": "publication",
    "slug": "pub-subsidie-drenthe-2025-04"
  },
  "title": "Subsidieregeling Duurzame Energie Particulieren Drenthe 2025",
  "summary": "Provinciale subsidieregeling voor particulieren die zonnepanelen, warmtepompen of isolatiemaatregelen treffen.",
  "description": "Op grond van de Algemene Subsidieverordening Provincie Drenthe 2020 stelt de provincie subsidie beschikbaar voor duurzame energiemaatregelen door particuliere huiseigenaren. Het subsidieplafond bedraagt € 2.500.000 voor het jaar 2025.",
  "organization": "provincie-drenthe",
  "themes": ["duurzaamheid", "subsidie", "energie"]
}
```

**Object 5 — Vergunning (Gemeente Emmen)**
```json
{
  "@self": {
    "register": "publication-register",
    "schema": "publication",
    "slug": "pub-vergunning-emmen-2025-012"
  },
  "title": "Omgevingsvergunning uitbreiding distributiecentrum Rietlanden",
  "summary": "Verleende omgevingsvergunning voor uitbreiding van het distributiecentrum aan de Rietlanden 44 te Emmen.",
  "description": "De omgevingsvergunning betreft de uitbreiding van een bestaand distributiecentrum met 4.800 m². De aanvraag is getoetst aan het bestemmingsplan Erica-Klazienaveen Bedrijventerrein. Bezwaar is mogelijk tot en met 3 juli 2025.",
  "organization": "gemeente-emmen",
  "themes": ["omgevingsrecht", "vergunning", "bedrijventerrein"]
}
```
