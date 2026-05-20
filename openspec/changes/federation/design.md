# Design: federation

## Context

OpenCatalogi serves as the public-facing publication platform for WOO government transparency. Multiple government organizations (municipalities, ministries, agencies) run separate OpenCatalogi instances. The federation layer enables a unified search experience by aggregating publications across the entire network.

**Existing infrastructure:**
- `lib/Controller/FederationController.php` — all 6 endpoint methods implemented with `@PublicPage`, `@NoCSRFRequired`, `@NoAdminRequired`
- `lib/Service/PublicationService.php` — `getAggregatedPublications()`, `getFederatedPublication()`, `getFederatedUses()`, `getFederatedUsed()`, `attachments()`, `download()` all implemented
- `lib/Service/DirectoryService.php` — provides Listing URLs for remote instances
- `appinfo/routes.php` (lines 98–103) — all 6 federation routes registered
- `GuzzleHttp\Promise\Utils::settle()` — async parallel HTTP to remote directories

**Constraints:**
- No separate SearchService or ElasticSearchService — all federation logic lives in PublicationService
- Attachments and download endpoints are local-only (binary files cannot be meaningfully aggregated from remote sources)
- Only Listings with `integrationLevel: "search"` and `default: true` are queried during federation
- Listings pointing to the local instance are skipped to prevent self-referential loops

## Goals / Non-Goals

**Goals:**
- Unified publication search across local catalogs and all remote directories
- Async parallel HTTP to minimize latency when querying multiple remote instances
- Facet merging from all sources (bucket `count` values are summed per `_id`)
- Result sorting by `_score` (relevance) across local and remote items
- Complete sub-resource coverage: list, single, uses, used, attachments, download
- All endpoints publicly accessible (no authentication required)

**Non-Goals:**
- Write operations across federation (federation is read-only)
- Caching of federated results
- Real-time synchronization between instances
- File attachment aggregation from remote instances
- Cross-instance deduplication of identical publications

## Decisions

### 1. FederationController is a thin routing layer
All business logic — aggregation, async HTTP calls, facet merging, sorting — lives in `PublicationService`. `FederationController` only maps requests to service methods and formats responses. This avoids code duplication with the regular publications endpoints.

### 2. Async HTTP via `GuzzleHttp\Promise\Utils::settle()`
Remote directory endpoints are queried in parallel. `settle()` (not `all()`) is used so that failures or timeouts on individual remote instances do not block the overall response. Each remote result is merged into the final set only if its promise fulfilled.

### 3. Attachments and download are local-only
`publicationAttachments()` and `publicationDownload()` delegate to `PublicationService::attachments()` and `PublicationService::download()` which only serve local files. This is by design: binary file content cannot be proxied or aggregated meaningfully from remote sources.

### 4. Listings filter: `integrationLevel = "search"` and `default = true`
The aggregation only queries remote directories that are explicitly configured for search federation. This prevents unintended load or exposure on directories not set up for federation.

### 5. Result sorting by `_score` using `usort()`
After merging local and remote results, the combined array is sorted by the `_score` field descending. This ensures the most relevant results appear first regardless of source origin.

## Seed Data

### Listings (remote federation sources)

```json
[
  {
    "id": "listing-fed-001",
    "title": "Gemeente Amsterdam OpenCatalogi",
    "url": "https://opencatalogi.amsterdam.nl",
    "integrationLevel": "search",
    "default": true,
    "status": "actief"
  },
  {
    "id": "listing-fed-002",
    "title": "Gemeente Rotterdam Publicatieplatform",
    "url": "https://catalogus.rotterdam.nl",
    "integrationLevel": "search",
    "default": true,
    "status": "actief"
  },
  {
    "id": "listing-fed-003",
    "title": "Gemeente Utrecht Openbare Documenten",
    "url": "https://opendata.utrecht.nl/opencatalogi",
    "integrationLevel": "search",
    "default": true,
    "status": "actief"
  },
  {
    "id": "listing-fed-004",
    "title": "Rijksdienst voor Ondernemend Nederland",
    "url": "https://catalogi.rvo.nl",
    "integrationLevel": "none",
    "default": false,
    "status": "actief"
  }
]
```

### Aggregated publications response (example)

```json
{
  "results": [
    {
      "id": "pub-local-001",
      "title": "Besluit inrichting openbare ruimte 2024",
      "description": "Besluit van het college van burgemeester en wethouders over de inrichting van de openbare ruimte in de gemeente.",
      "catalog": "Lokale Regelgeving",
      "_score": 0.94,
      "_source": "local"
    },
    {
      "id": "pub-ams-042",
      "title": "Verordening op de openbare orde Amsterdam 2023",
      "description": "Gemeentelijke verordening vastgesteld door de gemeenteraad van Amsterdam inzake openbare orde.",
      "catalog": "Verordeningen Amsterdam",
      "_score": 0.87,
      "_source": "https://opencatalogi.amsterdam.nl"
    },
    {
      "id": "pub-rdam-017",
      "title": "Aanbestedingsdocument digitale infrastructuur Rotterdam",
      "description": "Europese aanbestedingsdocumenten voor de vernieuwing van de digitale infrastructuur gemeente Rotterdam.",
      "catalog": "Aanbestedingen",
      "_score": 0.76,
      "_source": "https://catalogus.rotterdam.nl"
    },
    {
      "id": "pub-utr-008",
      "title": "WOO-besluit verzoek informatieverstrekking 2024-0042",
      "description": "Besluit op WOO-verzoek inzake informatieverstrekking over gemeentelijke subsidieverlening.",
      "catalog": "WOO-besluiten",
      "_score": 0.71,
      "_source": "https://opendata.utrecht.nl/opencatalogi"
    }
  ],
  "total": 1842,
  "page": 1,
  "pages": 185,
  "limit": 10,
  "offset": 0,
  "facets": {
    "theme": {
      "buckets": [
        { "_id": "openbare-orde-veiligheid", "count": 312 },
        { "_id": "bestuur-organisatie", "count": 287 },
        { "_id": "financien-belasting", "count": 194 },
        { "_id": "wonen-ruimtelijke-ordening", "count": 176 }
      ]
    }
  }
}
```

## File Changes

- `lib/Controller/FederationController.php` — Thin routing layer with 6 public endpoint methods; all implemented
- `lib/Service/PublicationService.php` — `getAggregatedPublications()`, `getFederatedPublication()`, `getFederatedUses()`, `getFederatedUsed()`, `attachments()`, `download()`; all implemented
- `lib/Service/DirectoryService.php` — Provides Listing data for remote directory URLs
- `appinfo/routes.php` — All 6 federation routes registered (lines 98–103)
