---
status: proposed
---

# woo-compliance Specification

## Purpose

Specify and harden the WOO (Wet Open Overheid) compliance features in OpenCatalogi: XML sitemap generation per catalog and WOO information category (informatiecategorie), DIWOO-conformant document metadata in sitemap pages, public robots.txt discovery, and the robots.txt `hasWooSitemap` gate fix.

## Context

OpenCatalogi is the WOO publication platform for Dutch government organisations. KOOP/DIWOO — the national open-government index — discovers publications by crawling sitemap files listed in robots.txt. OpenCatalogi generates:

1. A **sitemap index** per `(catalogSlug, categoryCode)` pair listing paginated batches of publications.
2. A **DIWOO sitemap** per page, where each publication's attached files appear as `diwoo:Document` XML elements carrying the DIWOO metadata required by the national standard.
3. A **robots.txt** listing the sitemap index URLs for all WOO-enabled catalogs.

All three endpoints are public (no authentication required). The `SitemapService`, `RobotsController`, and `SitemapController` in OpenCatalogi implement this functionality. This spec documents the normative behaviour, the `hasWooSitemap` gate, the DIWOO metadata mapping, and the error paths.

**Relation to existing OpenCatalogi infrastructure:**
- `lib/Controller/SitemapController.php` — handles sitemap index and DIWOO sitemap requests
- `lib/Controller/RobotsController.php` — handles robots.txt; **BUG**: does not check `hasWooSitemap`
- `lib/Service/SitemapService.php` — `buildSitemapIndex()`, `buildSitemap()`, `mapDiwooDocument()`, `isValidSitemapRequest()`
- `lib/Service/SettingsService.php` — provides register/schema slugs for catalog queries
- OpenRegister `ObjectService::searchObjectsPaginated()` — publication pagination
- OpenRegister `FileService::getFiles()` / `formatFiles()` — file metadata per publication
- `Catalog` entity — carries `hasWooSitemap: bool`, `slug: string`, `schemas: int[]`
- `Publication` entity — carries `tooiCategorieNaam`, `tooiCategorieUri`, `organisation`, `owner`
- `File` entity — carries `downloadUrl`, `extension`, `owner`, `published`

**Relation to other specs:**
- `auto-publishing` spec: share link creation that populates `file.downloadUrl`. Without it, DIWOO `<loc>` values may be empty.
- `file-management` spec: `FileService` share link creation used by both auto-publishing and sitemap generation.
- `woo-transparency` spec: WOO reading room, inventarislijst, and weigeringsgronden — separate concern.

---

## Requirements

### Requirement: REQ-WOO-001 — XML sitemap index per catalog and WOO category

The system MUST generate a valid XML sitemap index for a given catalog slug and WOO information category code.

#### Scenario: REQ-WOO-001-A — Sitemap index returns paginated entries

- GIVEN a catalog with slug `woo-publicaties-amsterdam` and `hasWooSitemap: true`
- AND the catalog's `schemas` array contains the schema ID that maps to `sitemapindex-diwoo-infocat014.xml`
- AND 2500 publications exist in that catalog and category
- WHEN a GET request is made to `/api/woo-publicaties-amsterdam/sitemaps/sitemapindex-diwoo-infocat014.xml`
- THEN the response MUST have HTTP status 200
- AND Content-Type MUST be `application/xml`
- AND the response body MUST be a `<sitemapindex>` document using the `https://www.sitemaps.org/schemas/sitemap/0.9` namespace
- AND the document MUST contain exactly 3 `<sitemap>` entries (pages 1, 2, 3 for 2500 publications at 1000 per page)
- AND each `<sitemap>` MUST contain a `<loc>` pointing to the publications endpoint with `?page=N`
- AND each `<sitemap>` MUST contain a `<lastmod>` set to the `updated` timestamp of the first (most recent) publication in that page's batch

#### Scenario: REQ-WOO-001-B — Empty catalog returns empty sitemap index

- GIVEN a catalog with `hasWooSitemap: true` and a valid schema
- AND zero publications exist for that catalog and category
- WHEN the sitemap index endpoint is called
- THEN the response MUST have HTTP status 200
- AND the `<sitemapindex>` element MUST contain zero `<sitemap>` children

#### Scenario: REQ-WOO-001-C — Publications ordered by updated descending

- GIVEN a catalog with 1001 publications where the most recently updated is publication P
- WHEN the sitemap index is generated
- THEN the first `<sitemap>` entry's `<lastmod>` MUST equal P's `updated` timestamp
- AND the second `<sitemap>` entry MUST reference the 1001st publication batch

---

### Requirement: REQ-WOO-002 — DIWOO-conformant sitemap publications page

The system MUST generate a valid DIWOO Document XML page for a given catalog, WOO category, and page number.

#### Scenario: REQ-WOO-002-A — Publications page returns DIWOO Document elements

- GIVEN a catalog with `hasWooSitemap: true` and 5 publications each with 2 attached files
- WHEN a GET request is made to `/api/woo-publicaties-amsterdam/sitemaps/sitemapindex-diwoo-infocat014.xml/publications?page=1`
- THEN the response MUST have HTTP status 200
- AND Content-Type MUST be `application/xml`
- AND the response body MUST be wrapped in `<diwoo:Documents>` with namespace declarations for `https://www.sitemaps.org/schemas/sitemap/0.9`, the DIWOO namespace, and the XSD schema location
- AND the document MUST contain exactly 10 `<diwoo:Document>` elements (5 publications × 2 files)

#### Scenario: REQ-WOO-002-B — Each DIWOO Document carries correct metadata

- GIVEN a publication with:
  - `created: "2024-11-01T08:00:00+01:00"`
  - `updated: "2025-01-15T14:30:00+01:00"`
  - `published: "2024-11-01T09:00:00+01:00"`
  - `organisation: "https://identifier.overheid.nl/tooi/id/gemeente/gm0363"`
  - `owner: "Gemeente Amsterdam"`
  - `tooiCategorieNaam: "Woo-verzoeken en -besluiten"`
  - `tooiCategorieUri: "https://identifier.overheid.nl/tooi/def/thes/kern/c_c4b3359f"`
- AND the publication has one file with:
  - `downloadUrl: "https://nextcloud.example.nl/s/abc123/download/besluit.pdf"`
  - `extension: "pdf"`
  - `owner: "Gemeente Amsterdam Juridische Zaken"`
  - `published: "2025-01-10T10:00:00+01:00"`
- WHEN the publications sitemap page is generated
- THEN the `<diwoo:Document>` for this file MUST contain:
  - `<loc>https://nextcloud.example.nl/s/abc123/download/besluit.pdf</loc>`
  - `<lastmod>2025-01-10T10:00:00+01:00</lastmod>` (file.published)
  - `<diwoo:creatiedatum>2024-11-01T08:00:00+01:00</diwoo:creatiedatum>`
  - `<diwoo:publisher resource="https://identifier.overheid.nl/tooi/id/gemeente/gm0363">Gemeente Amsterdam Juridische Zaken</diwoo:publisher>`
  - `<diwoo:format resource="{europa.eu PDF URI}">pdf</diwoo:format>`
  - `<diwoo:informatiecategorie resource="https://identifier.overheid.nl/tooi/def/thes/kern/c_c4b3359f">Woo-verzoeken en -besluiten</diwoo:informatiecategorie>`
  - `<diwoo:soortHandeling>ontvangst</diwoo:soortHandeling>`
  - `<diwoo:atTime>2025-01-10T10:00:00+01:00</diwoo:atTime>` (file.published)

#### Scenario: REQ-WOO-002-C — Fallback when file.published is absent

- GIVEN a file with no `published` field
- AND the parent publication has `updated: "2025-02-20T10:00:00+01:00"` and `published: "2025-02-18T08:00:00+01:00"`
- WHEN the DIWOO Document is mapped
- THEN `<lastmod>` MUST use `publication.updated`: `"2025-02-20T10:00:00+01:00"`
- AND `<diwoo:atTime>` MUST use `publication.published`: `"2025-02-18T08:00:00+01:00"`

#### Scenario: REQ-WOO-002-D — Fallback when file.owner is absent

- GIVEN a file with no `owner` field
- AND the parent publication has `owner: "Gemeente Rotterdam"`
- WHEN the DIWOO Document is mapped
- THEN `<diwoo:publisher>` text content MUST be `"Gemeente Rotterdam"`

#### Scenario: REQ-WOO-002-E — Pagination uses page parameter

- GIVEN a catalog with 1500 publications in a category
- WHEN page 2 is requested (`?page=2`)
- THEN the response MUST contain DIWOO Documents for publications 1001–1500
- AND the response for page 1 MUST contain publications 1–1000
- AND requesting page 3 MUST return an empty `<diwoo:Documents>` (no publications)

---

### Requirement: REQ-WOO-003 — Support all 17 WOO information categories

The system MUST accept and correctly process requests for all 17 WOO informatiecategorieen.

#### Scenario: REQ-WOO-003-A — All 17 category codes are valid

- GIVEN any of the following category codes:
  `sitemapindex-diwoo-infocat001.xml` through `sitemapindex-diwoo-infocat017.xml`
- WHEN a sitemap request is made with a valid catalog and configured schema
- THEN the response MUST have HTTP status 200
- AND no category code from this list MAY return a 400 "Invalid category code" error

#### Scenario: REQ-WOO-003-B — Category codes map to the correct schema names

- GIVEN a request for `sitemapindex-diwoo-infocat014.xml`
- WHEN the SitemapService validates the request
- THEN it MUST look up schema "Woo-verzoeken en -besluiten" in the catalog's schemas array
- AND the query MUST use that schema's integer ID as the filter

---

### Requirement: REQ-WOO-004 — Robots.txt lists WOO-enabled catalogs only

The system MUST generate a robots.txt file that includes sitemap URLs only for catalogs where `hasWooSitemap: true`.

#### Scenario: REQ-WOO-004-A — Catalogs with hasWooSitemap:true appear in robots.txt

- GIVEN a catalog with `slug: "woo-publicaties-amsterdam"` and `hasWooSitemap: true`
- WHEN a GET request is made to `/api/robots.txt`
- THEN the response body MUST contain 17 `Sitemap:` lines for this catalog (one per informatiecategorie)
- AND each line MUST follow the format `Sitemap: {baseUrl}/api/woo-publicaties-amsterdam/sitemaps/{categoryCode}`

#### Scenario: REQ-WOO-004-B — Catalogs with hasWooSitemap:false are excluded (bug fix)

- GIVEN a catalog with `slug: "intern-register"` and `hasWooSitemap: false`
- AND a second catalog with `slug: "woo-publicaties"` and `hasWooSitemap: true`
- WHEN a GET request is made to `/api/robots.txt`
- THEN the response body MUST NOT contain any `Sitemap:` line referencing `intern-register`
- AND the response MUST contain 17 `Sitemap:` lines referencing `woo-publicaties`

#### Scenario: REQ-WOO-004-C — Catalogs without a slug are excluded

- GIVEN a catalog with `hasWooSitemap: true` but no `slug` field set
- WHEN the robots.txt is generated
- THEN no `Sitemap:` lines MUST be emitted for this catalog

#### Scenario: REQ-WOO-004-D — Robots.txt is public

- GIVEN an unauthenticated HTTP client
- WHEN a GET request is made to `/api/robots.txt`
- THEN the response MUST have HTTP status 200
- AND no authentication challenge MUST be returned
- AND the Content-Type MUST be `text/plain`

#### Scenario: REQ-WOO-004-E — No WOO-enabled catalogs returns empty robots.txt

- GIVEN no catalogs have `hasWooSitemap: true`
- WHEN a GET request is made to `/api/robots.txt`
- THEN the response MUST have HTTP status 200
- AND the body MUST be empty or contain only a `User-agent: *` line

---

### Requirement: REQ-WOO-005 — Sitemap pagination at 1000 entries per page

The system MUST paginate publication results at a maximum of 1000 entries per page, conforming to the Sitemaps Protocol limit.

#### Scenario: REQ-WOO-005-A — Exactly 1000 publications fills one page

- GIVEN exactly 1000 publications for a catalog and category
- WHEN the sitemap index is generated
- THEN it MUST contain exactly 1 `<sitemap>` entry
- AND requesting `/publications?page=1` MUST return exactly 1000 `<diwoo:Document>` elements

#### Scenario: REQ-WOO-005-B — 1001 publications creates two pages

- GIVEN exactly 1001 publications
- WHEN the sitemap index is generated
- THEN it MUST contain exactly 2 `<sitemap>` entries
- AND page 1 MUST contain 1000 documents
- AND page 2 MUST contain 1 document

---

### Requirement: REQ-WOO-006 — DIWOO Document metadata mapping

The system MUST map publication and file metadata to the DIWOO Document XML structure using the defined field sources and fallback rules.

#### Scenario: REQ-WOO-006-A — soortHandeling is always "ontvangst"

- GIVEN any publication and file combination
- WHEN the DIWOO Document is generated
- THEN `<diwoo:soortHandeling>` MUST always have the value `ontvangst`

#### Scenario: REQ-WOO-006-B — format resource is a europa.eu file-type URI

- GIVEN a file with `extension: "pdf"`
- WHEN the DIWOO Document is generated
- THEN `<diwoo:format @resource>` MUST be the europa.eu file-type URI for PDF
- AND `<diwoo:format>` text MUST be `"pdf"` (lowercase)

#### Scenario: REQ-WOO-006-C — Empty downloadUrl produces empty loc

- GIVEN a file with no `downloadUrl` (share link not yet created)
- WHEN the DIWOO Document is generated
- THEN `<loc>` MUST be an empty element (no value)
- AND no error MUST be raised — the document MUST still be emitted

---

### Requirement: REQ-WOO-007 — Validation of category code and schema membership

The system MUST validate that the requested category code is known and that the mapped schema exists in the catalog's schema configuration before processing a sitemap request.

#### Scenario: REQ-WOO-007-A — Invalid category code returns 400

- GIVEN a request for `/api/woo-publicaties/sitemaps/invalid-category.xml`
- WHEN the sitemap index endpoint is called
- THEN the response MUST have HTTP status 400
- AND the response body MUST be an XML error document containing the text `"Invalid category code"`

#### Scenario: REQ-WOO-007-B — Valid category code but schema not in catalog returns 400

- GIVEN a valid category code `sitemapindex-diwoo-infocat015.xml` that maps to schema ID 15
- AND the catalog's `schemas` array does NOT contain ID 15
- WHEN the sitemap index endpoint is called
- THEN the response MUST have HTTP status 400
- AND the response body MUST contain `"Schema not configured in catalog"`

#### Scenario: REQ-WOO-007-C — Valid category code with schema in catalog proceeds normally

- GIVEN `sitemapindex-diwoo-infocat014.xml` maps to schema ID 14
- AND the catalog's `schemas` array contains ID 14
- WHEN the sitemap index endpoint is called
- THEN the response MUST have HTTP status 200
- AND the `<sitemapindex>` MUST be returned

---

### Requirement: REQ-WOO-008 — hasWooSitemap gate enforced on RobotsController (bug fix)

The `RobotsController` MUST check `hasWooSitemap: true` before emitting sitemap lines for a catalog. The current implementation omits this check.

#### Scenario: REQ-WOO-008-A — Fix: only catalogs with hasWooSitemap:true appear

- GIVEN two catalogs A (`hasWooSitemap: true`, slug `woo-pub`) and B (`hasWooSitemap: false`, slug `intern`)
- WHEN `RobotsController::getRobots()` processes the catalog list
- THEN it MUST skip catalog B entirely
- AND the output MUST contain 17 `Sitemap:` lines for `woo-pub`
- AND the output MUST contain zero `Sitemap:` lines for `intern`

#### Scenario: REQ-WOO-008-B — SitemapService.isValidSitemapRequest still independently checks hasWooSitemap

- GIVEN a catalog with `hasWooSitemap: false` and a valid slug and schema
- WHEN a direct request is made to the sitemap index endpoint for that catalog
- THEN `isValidSitemapRequest()` MUST return false
- AND the response MUST be a 400 or 403 error (catalog not eligible for WOO sitemap)

---

### Requirement: REQ-WOO-009 — All sitemap and robots endpoints are public

The sitemap and robots.txt endpoints MUST be accessible without authentication.

#### Scenario: REQ-WOO-009-A — Sitemap index endpoint is public

- GIVEN an unauthenticated HTTP client
- WHEN a GET request is made to `/api/{catalogSlug}/sitemaps/{categoryCode}`
- THEN the response MUST have HTTP status 200 or 400 (for invalid input)
- AND no HTTP 401 or 403 MUST be returned

#### Scenario: REQ-WOO-009-B — DIWOO sitemap publications endpoint is public

- GIVEN an unauthenticated HTTP client
- WHEN a GET request is made to `/api/{catalogSlug}/sitemaps/{categoryCode}/publications`
- THEN the response MUST have HTTP status 200 or 400
- AND no HTTP 401 or 403 MUST be returned

---

### Requirement: REQ-WOO-010 — File metadata completeness in DIWOO documents

Each DIWOO Document MUST include all required file metadata fields: download URL, format, creation date, publisher, and handling type.

#### Scenario: REQ-WOO-010-A — All required DIWOO fields are present

- GIVEN a fully populated publication with at least one file
- WHEN the DIWOO sitemap page is generated
- THEN every `<diwoo:Document>` MUST contain non-empty values for:
  - `<loc>`
  - `<lastmod>`
  - `<diwoo:creatiedatum>`
  - `<diwoo:publisher>`
  - `<diwoo:format>`
  - `<diwoo:informatiecategorie>`
  - `<diwoo:soortHandeling>`
  - `<diwoo:atTime>`

#### Scenario: REQ-WOO-010-B — Multiple files per publication each generate a separate DIWOO Document

- GIVEN a publication with 3 attached files (PDF, DOCX, XLSX)
- WHEN the DIWOO sitemap page is generated
- THEN exactly 3 `<diwoo:Document>` elements MUST be emitted for this publication
- AND each element MUST reference its own file's `downloadUrl` and `extension`

---

## Non-Requirements

- This spec does NOT cover WOO reading room, inventarislijst, or weigeringsgronden (covered by `woo-transparency` spec)
- This spec does NOT cover the auto-publishing share link creation that populates `file.downloadUrl` (covered by `auto-publishing` spec)
- This spec does NOT cover PLOOI national platform integration
- This spec does NOT cover WOO case lifecycle management (covered by Procest `woo-case-type` spec)
- This spec does NOT introduce new OpenRegister schemas or entities

---

## Current Implementation Status

- **Implemented (WOO-001 through WOO-007, WOO-009, WOO-010)**: SitemapService, SitemapController, RobotsController, and all three endpoints are functionally present in the codebase.
- **Bug (WOO-008)**: `RobotsController::getRobots()` does NOT check `hasWooSitemap` — it emits sitemap lines for all catalogs that have a slug, regardless of the field value. `SitemapService::isValidSitemapRequest()` correctly checks `hasWooSitemap` for individual sitemap requests, but the robots.txt generation path is unguarded.
- **Missing**: Unit tests for the robots.txt gate, DIWOO metadata mapping field sources/fallbacks, and sitemap pagination edge cases.

---

## Dependencies

- `lib/Service/SitemapService.php` — core sitemap generation; existing, no changes required
- `lib/Controller/RobotsController.php` — robots.txt generation; one-line fix required for `hasWooSitemap` gate
- `lib/Controller/SitemapController.php` — request routing; no changes required
- `lib/Service/SettingsService.php` — register/schema lookup; existing
- OpenRegister `ObjectService::searchObjectsPaginated()` — existing
- OpenRegister `FileService::getFiles()` / `formatFiles()` — existing
- Nextcloud `IURLGenerator` — existing

---

## Standards and References

- **DIWOO (Digitale Infrastructuur WOO)**: Technical infrastructure standard for WOO sitemap metadata
- **Sitemaps Protocol**: `https://www.sitemaps.org/protocol.html` — max 50,000 URLs or 50 MB per sitemap file; this spec limits to 1000 for performance reasons
- **KOOP/PLOOI**: Central Dutch government index that crawls robots.txt and sitemaps
- **WOO (Wet Open Overheid)**: Primary transparency law (effective 1 May 2022); defines the 17 information categories (informatiecategorieen)
- **TOOI (Thesaurus Overheidsinformatie)**: Provides `tooiCategorieUri` values used in `<diwoo:informatiecategorie @resource>`
- **europa.eu file-type vocabulary**: Provides format URIs used in `<diwoo:format @resource>`
