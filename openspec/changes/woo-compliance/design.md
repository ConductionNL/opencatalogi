# Design: woo-compliance

## Architecture Overview

See `specs/woo-compliance/spec.md` for the full requirement set and GIVEN/WHEN/THEN scenarios.

This change is a **focused bug fix + formalisation**. No new entities, controllers, or services are introduced. The scope is limited to:

1. One method fix in `RobotsController::getRobots()`
2. Formal tests for the robots.txt gate and DIWOO metadata mapping
3. Documentation of the existing SitemapService behaviour

---

## Component Map

### Controllers (existing, one fix)

| Controller | File | Role in this change |
|---|---|---|
| `SitemapController` | `lib/Controller/SitemapController.php` | Delegates to SitemapService. No changes needed. |
| `RobotsController` | `lib/Controller/RobotsController.php` | **Bug fix**: add `hasWooSitemap: true` filter before emitting sitemap lines. |

### Services (existing, read-only)

| Service | Responsibility |
|---|---|
| `SitemapService` | `buildSitemapIndex()`, `buildSitemap()`, `mapDiwooDocument()`, `isValidSitemapRequest()` |
| `SettingsService` | `getSettings()` — returns catalog register/schema slugs for object queries |
| `ObjectService` (OpenRegister) | `searchObjectsPaginated()` — publication queries with register/schema filters |
| `FileService` (OpenRegister) | `getFiles()`, `formatFiles()` — file metadata per publication |
| `IURLGenerator` (Nextcloud) | Base URL generation for sitemap loc values |

### Response Classes (existing)

| Class | Used by |
|---|---|
| `XMLResponse` | `SitemapController` — wraps sitemap XML output |
| `TextResponse` | `RobotsController` — wraps plain-text robots.txt output |

---

## Request Flow

### Sitemap index (`GET /api/{catalogSlug}/sitemaps/{categoryCode}`)

```
SitemapController::getSitemapIndex($catalogSlug, $categoryCode)
  │
  ▼
SitemapService::isValidSitemapRequest($catalogSlug, $categoryCode)
  │  validates: (1) categoryCode ∈ INFO_CAT map  (2) catalog has hasWooSitemap:true
  │  validates: (3) mapped schema exists in catalog.schemas[]
  │  → 400 XMLResponse on failure
  │
  ▼
SitemapService::buildSitemapIndex($catalog, $schemaId)
  │  searchObjectsPaginated(register, schema, limit=1000, page=N) ordered by updated DESC
  │  → one <sitemap> entry per page of 1000 publications
  │  → lastmod = updated of first publication in that batch
  │
  ▼
XMLResponse  (Content-Type: application/xml)
  <sitemapindex xmlns="https://www.sitemaps.org/schemas/sitemap/0.9">
    <sitemap>
      <loc>…/sitemaps/{categoryCode}/publications?page=1</loc>
      <lastmod>2025-03-14T09:00:00+01:00</lastmod>
    </sitemap>
    …
  </sitemapindex>
```

### DIWOO sitemap (`GET /api/{catalogSlug}/sitemaps/{categoryCode}/publications?page=N`)

```
SitemapController::getSitemap($catalogSlug, $categoryCode, $page)
  │
  ▼
SitemapService::buildSitemap($catalog, $schemaId, $page)
  │  searchObjectsPaginated(register, schema, limit=1000, page=N)
  │  for each publication → FileService::getFiles() + formatFiles()
  │  for each file → SitemapService::mapDiwooDocument()
  │
  ▼
XMLResponse
  <diwoo:Documents xmlns:diwoo="…" xmlns="https://www.sitemaps.org/schemas/sitemap/0.9"
                   xsi:schemaLocation="…">
    <diwoo:Document>
      <loc>{file.downloadUrl}</loc>
      <lastmod>{file.published ?? publication.updated}</lastmod>
      <diwoo:creatiedatum>{publication.created}</diwoo:creatiedatum>
      <diwoo:publisher resource="{publication.organisation}">{file.owner ?? publication.owner}</diwoo:publisher>
      <diwoo:format resource="{europa.eu URI}">{file.extension}</diwoo:format>
      <diwoo:informatiecategorie resource="{tooiCategorieUri}">{tooiCategorieNaam}</diwoo:informatiecategorie>
      <diwoo:soortHandeling>ontvangst</diwoo:soortHandeling>
      <diwoo:atTime>{file.published ?? publication.published}</diwoo:atTime>
    </diwoo:Document>
    …
  </diwoo:Documents>
```

### Robots.txt (`GET /api/robots.txt`)

**Current (buggy) flow:**
```
RobotsController::getRobots()
  │  searchObjects(catalog register/schema) → ALL catalogs with a slug
  │  for each catalog → 17 sitemap URLs  ← emits catalogs regardless of hasWooSitemap
  │
  ▼
TextResponse  "Sitemap: {url}\n…"
```

**Fixed flow:**
```
RobotsController::getRobots()
  │  searchObjects(catalog register/schema) → catalogs with slug AND hasWooSitemap=true
  │  for each qualifying catalog → 17 sitemap URLs
  │
  ▼
TextResponse  "Sitemap: {url}\n…"
```

The fix is a single filter predicate added to the catalog iteration: skip any catalog where `hasWooSitemap !== true`.

---

## DIWOO Document Metadata Mapping

Full field source table for `SitemapService::mapDiwooDocument()`:

| DIWOO XML element / attribute | Source field | Fallback |
|---|---|---|
| `<loc>` | `file.downloadUrl` | `""` (empty string — KOOP may reject) |
| `<lastmod>` | `file.published` | `publication.@self.updated` |
| `<diwoo:creatiedatum>` | `publication.@self.created` | — |
| `<diwoo:publisher @resource>` | `publication.@self.organisation` | — |
| `<diwoo:publisher>` (text) | `file.owner` | `publication.@self.owner` |
| `<diwoo:format @resource>` | europa.eu file-type URI derived from `file.extension` | — |
| `<diwoo:format>` (text) | `file.extension` (lowercase) | — |
| `<diwoo:informatiecategorie>` (text) | `publication.tooiCategorieNaam` | — |
| `<diwoo:informatiecategorie @resource>` | `publication.tooiCategorieUri` | — |
| `<diwoo:soortHandeling>` | hardcoded `"ontvangst"` | — |
| `<diwoo:atTime>` | `file.published` | `publication.@self.published` |

---

## WOO Information Categories

The 17 valid `categoryCode` values and their schema mappings:

| categoryCode (URL segment) | WOO schema name |
|---|---|
| `sitemapindex-diwoo-infocat001.xml` | Wetten en algemeen verbindende voorschriften |
| `sitemapindex-diwoo-infocat002.xml` | Overige besluiten van algemene strekking |
| `sitemapindex-diwoo-infocat003.xml` | Ontwerpen van wet- en regelgeving met adviesaanvraag |
| `sitemapindex-diwoo-infocat004.xml` | Organisatie en werkwijze |
| `sitemapindex-diwoo-infocat005.xml` | Bereikbaarheidsgegevens |
| `sitemapindex-diwoo-infocat006.xml` | Bij vertegenwoordigende organen ingekomen stukken |
| `sitemapindex-diwoo-infocat007.xml` | Vergaderstukken Staten-Generaal |
| `sitemapindex-diwoo-infocat008.xml` | Vergaderstukken decentrale overheden |
| `sitemapindex-diwoo-infocat009.xml` | Agenda's en besluitenlijsten bestuurscolleges |
| `sitemapindex-diwoo-infocat010.xml` | Adviezen |
| `sitemapindex-diwoo-infocat011.xml` | Convenanten |
| `sitemapindex-diwoo-infocat012.xml` | Jaarplannen en jaarverslagen |
| `sitemapindex-diwoo-infocat013.xml` | Subsidieverplichtingen anders dan met beschikking |
| `sitemapindex-diwoo-infocat014.xml` | Woo-verzoeken en -besluiten |
| `sitemapindex-diwoo-infocat015.xml` | Onderzoeksrapporten |
| `sitemapindex-diwoo-infocat016.xml` | Beschikkingen |
| `sitemapindex-diwoo-infocat017.xml` | Klachtoordelen |

---

## Seed Data

This change does **not** introduce or modify any OpenRegister schemas — it operates on the existing `Catalog` and `Publication` entities. Seed data requirements follow the ADR-001 exception: *"Changes that only modify frontend components or non-schema backend logic do not require seed data."*

The `hasWooSitemap` field is an existing property on the Catalog schema. The existing register template in `lib/Settings/opencatalogi_register.json` should already include at least one catalog with `hasWooSitemap: true` for testability. If none exists, the tasks section includes a task to add one mock catalog with `hasWooSitemap: true` and one with `hasWooSitemap: false` to the register template.

Example seed catalog objects (for reference — do not create new schemas):

```json
{
  "@self": {
    "register": "opencatalogi",
    "schema": "catalog",
    "slug": "woo-publicaties-amsterdam"
  },
  "title": "WOO Publicaties Gemeente Amsterdam",
  "summary": "Openbaar register van WOO-verzoeken en actieve openbaarmakingen van Gemeente Amsterdam.",
  "hasWooSitemap": true,
  "listed": true,
  "slug": "woo-publicaties-amsterdam"
}
```

```json
{
  "@self": {
    "register": "opencatalogi",
    "schema": "catalog",
    "slug": "intern-register-denhaag"
  },
  "title": "Intern Documentregister Den Haag",
  "summary": "Interne publicatielijst, niet bestemd voor DIWOO-indexering.",
  "hasWooSitemap": false,
  "listed": false,
  "slug": "intern-register-denhaag"
}
```

---

## Reuse Analysis

Per ADR-001 requirements, all existing OpenRegister capabilities leveraged by this change:

| Capability | Provider | Usage |
|---|---|---|
| Object pagination queries | `ObjectService::searchObjectsPaginated()` | Fetch publications in batches of 1000 |
| File metadata | `FileService::getFiles()` + `formatFiles()` | Retrieve download URLs and owner info per publication |
| Settings / register lookup | `SettingsService::getSettings()` | Resolve catalog register and schema slugs |
| URL generation | `IURLGenerator` (Nextcloud core) | Build absolute sitemap loc values |
| XML response | `XMLResponse` (existing OpenCatalogi class) | Content-Type: application/xml wrapper |
| Text response | `TextResponse` (existing OpenCatalogi class) | Content-Type: text/plain wrapper |

**Deduplication check:** No new search endpoints, query builders, file controllers, or custom pagination are introduced. All data access goes through the existing `ObjectService` and `FileService` abstractions provided by OpenRegister.

---

## Test Strategy

1. **Unit: robots.txt gate** — mock a catalog list containing one catalog with `hasWooSitemap: true` and one with `hasWooSitemap: false`; assert robots.txt output contains 17 sitemap lines for the first and zero lines for the second.
2. **Unit: isValidSitemapRequest** — assert 400 for an invalid category code; assert 400 when the mapped schema is absent from the catalog; assert pass-through for a valid, configured catalog.
3. **Unit: mapDiwooDocument** — assert each DIWOO field is populated from the documented source, and assert each fallback activates when the primary source is absent.
4. **Unit: buildSitemapIndex pagination** — mock 2500 publications; assert 3 `<sitemap>` entries in the index (pages 1–3), each with the correct `lastmod` drawn from the first publication in that batch.
