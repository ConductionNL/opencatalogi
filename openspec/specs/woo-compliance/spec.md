---
status: reviewed
---

# WOO Compliance (Sitemaps, Robots, DIWOO)

## Purpose

OpenCatalogi supports Dutch WOO (Wet Open Overheid) compliance by generating XML sitemaps and robots.txt files that conform to the DIWOO metadata standard. This enables government organizations to make their publications discoverable by the Dutch government's central search index (KOOP/DIWOO). Sitemaps are generated per catalog and per WOO information category (informatiecategorie), mapping publications to the DIWOO XML schema with proper metadata including creation dates, publishers, file formats, and document handling information.

## Requirements

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| WOO-001 | Generate XML sitemap index per catalog per WOO information category | Must | Implemented |
| WOO-002 | Generate XML sitemap with DIWOO Document metadata for publications | Must | Implemented |
| WOO-003 | Support all 17 WOO information categories (informatiecategorieen) | Must | Implemented |
| WOO-004 | Generate robots.txt with sitemap URLs for all WOO-enabled catalogs | Must | Implemented |
| WOO-005 | Paginate sitemaps (max 1000 entries per page) | Must | Implemented |
| WOO-006 | Map publication + file metadata to DIWOO Document XML structure | Must | Implemented |
| WOO-007 | Validate that requested category belongs to the catalog's schemas | Must | Implemented |
| WOO-008 | Only catalogs with `hasWooSitemap: true` appear in robots.txt | Must | Bug (RobotsController does NOT check hasWooSitemap) |
| WOO-009 | All sitemap/robots endpoints are public | Must | Implemented |
| WOO-010 | Include file metadata: download URL, format, creation date, publisher, handling type | Must | Implemented |

## Data Model

### WOO Information Categories (INFO_CAT)

The 17 mandatory WOO categories mapped to sitemap codes:

| Code | Category (Dutch) |
|------|-----------------|
| sitemapindex-diwoo-infocat001.xml | Wetten en algemeen verbindende voorschriften |
| sitemapindex-diwoo-infocat002.xml | Overige besluiten van algemene strekking |
| sitemapindex-diwoo-infocat003.xml | Ontwerpen van wet- en regelgeving met adviesaanvraag |
| sitemapindex-diwoo-infocat004.xml | Organisatie en werkwijze |
| sitemapindex-diwoo-infocat005.xml | Bereikbaarheidsgegevens |
| sitemapindex-diwoo-infocat006.xml | Bij vertegenwoordigende organen ingekomen stukken |
| sitemapindex-diwoo-infocat007.xml | Vergaderstukken Staten-Generaal |
| sitemapindex-diwoo-infocat008.xml | Vergaderstukken decentrale overheden |
| sitemapindex-diwoo-infocat009.xml | Agenda's en besluitenlijsten bestuurscolleges |
| sitemapindex-diwoo-infocat010.xml | Adviezen |
| sitemapindex-diwoo-infocat011.xml | Convenanten |
| sitemapindex-diwoo-infocat012.xml | Jaarplannen en jaarverslagen |
| sitemapindex-diwoo-infocat013.xml | Subsidieverplichtingen anders dan met beschikking |
| sitemapindex-diwoo-infocat014.xml | Woo-verzoeken en -besluiten |
| sitemapindex-diwoo-infocat015.xml | Onderzoeksrapporten |
| sitemapindex-diwoo-infocat016.xml | Beschikkingen |
| sitemapindex-diwoo-infocat017.xml | Klachtoordelen |

### DIWOO Document Metadata Mapping

Each file attached to a publication generates a `diwoo:Document` with:

| DIWOO Field | Source |
|-------------|--------|
| loc | file.downloadUrl |
| lastmod | file.published or publication.@self.updated |
| diwoo:creatiedatum | publication.@self.created |
| diwoo:publisher @resource | publication.@self.organisation |
| diwoo:publisher #text | file.owner or publication.@self.owner |
| diwoo:format @resource | europa.eu file-type URI from file.extension |
| diwoo:format #text | file.extension (lowercase) |
| diwoo:informatiecategorie #text | publication.tooiCategorieNaam |
| diwoo:informatiecategorie @resource | publication.tooiCategorieUri |
| diwoo:soortHandeling | "ontvangst" (receipt) |
| diwoo:atTime | file.published or publication.@self.published |

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/{catalogSlug}/sitemaps/{categoryCode}` | Sitemap index for a catalog + WOO category |
| GET | `/api/{catalogSlug}/sitemaps/{categoryCode}/publications` | Sitemap with DIWOO Document entries (paginated, ?page=N) |
| GET | `/api/robots.txt` | Robots.txt with sitemap URLs for all WOO-enabled catalogs |

## Scenarios

### Scenario: Generate sitemap index
- GIVEN a catalog with slug "woo-publicaties" and hasWooSitemap=true
- AND the WOO category "sitemapindex-diwoo-infocat014.xml" maps to schema "Woo-verzoeken en -besluiten"
- WHEN a GET request is made to `/api/woo-publicaties/sitemaps/sitemapindex-diwoo-infocat014.xml`
- THEN the SitemapService validates the category code and catalog
- AND verifies the schema exists in the catalog's schema list
- AND queries publications ordered by updated DESC with limit 1000
- AND generates a `<sitemapindex>` XML with `<sitemap>` entries containing:
  - loc: URL to the publications sitemap page
  - lastmod: updated timestamp of the first (most recent) publication in that batch
- AND pagination creates additional sitemap entries for each batch of 1000

### Scenario: Generate DIWOO sitemap
- GIVEN publications exist for the specified catalog and WOO category
- WHEN a GET request is made to `/api/woo-publicaties/sitemaps/sitemapindex-diwoo-infocat014.xml/publications?page=1`
- THEN publications are fetched with register/schema filters (limit 1000, page N)
- AND for each publication, files are fetched via FileService
- AND each file generates a `diwoo:Document` XML element with proper DIWOO metadata
- AND the response is wrapped in `<diwoo:Documents>` with proper XML namespaces (sitemaps.org, DIWOO, XSD schema locations)

### Scenario: Generate robots.txt
- GIVEN catalogs exist, some with hasWooSitemap=true
- WHEN a GET request is made to `/api/robots.txt`
- THEN all catalogs are fetched from the catalog register/schema
- AND only catalogs with a slug are included (**Bug**: `hasWooSitemap` is NOT checked by `RobotsController` -- all catalogs with a slug get sitemap entries. The `SitemapService.isValidSitemapRequest()` checks `hasWooSitemap` for individual sitemap requests, but the robots.txt generation does not.)
- AND for each qualifying catalog, 17 sitemap URLs are generated (one per WOO category)
- AND the response is plain text with "Sitemap: {url}" lines

### Scenario: Invalid category code
- GIVEN a request with an invalid category code "invalid.xml"
- WHEN the sitemap endpoint is called
- THEN a 400 error XMLResponse is returned with "Invalid category code"

### Scenario: Schema not in catalog
- GIVEN a valid category code maps to schema ID 5
- BUT the catalog does not include schema 5 in its schemas array
- WHEN the sitemap endpoint is called
- THEN a 400 error XMLResponse is returned with "Schema not configured in catalog"

## Cross-References

- **Auto-publishing**: When `auto_publish_attachments` is enabled (see [auto-publishing spec](../auto-publishing/spec.md)), files get share links created automatically. These share links are used as `downloadUrl` values in DIWOO sitemap documents. Without auto-publishing or manual share link creation, the DIWOO sitemap `loc` fields may be empty.
- **File Management**: The [file management service](../file-management/spec.md) provides share link creation used by both auto-publishing and WOO sitemap generation.
- **Download Service**: The [download service](../download-service/spec.md) generates PDF/ZIP exports of publications, which is complementary to the WOO sitemap's XML-based discovery mechanism.

## Dependencies

- **SitemapService** - buildSitemapIndex(), buildSitemap(), mapDiwooDocument(), isValidSitemapRequest()
- **SettingsService** - getSettings() for register/schema lookups
- **OpenRegister ObjectService** - searchObjectsPaginated for publication queries
- **OpenRegister FileService** - getFiles(), formatFiles() for file metadata
- **Nextcloud IURLGenerator** - Base URL generation for sitemap URLs
- **XMLResponse** - Custom response class for XML output
- **TextResponse** - Custom response class for robots.txt output
