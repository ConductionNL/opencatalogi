---
status: proposed
---

# DiWoo sitemap structure

## MODIFIED Requirements

### Requirement: Generate XML sitemap with DIWOO Document metadata for publications (WOO-002)
The system MUST generate a category sitemap page in the DiWoo 0.9.8 sitemap structure: a sitemaps.org `urlset` with one `url` per downloadable file of the page's publications. The `urlset` MUST declare the sitemaps.org namespace as its default namespace, the `diwoo` namespace `https://standaarden.overheid.nl/diwoo/metadata/`, the `xsi` namespace, and an `xsi:schemaLocation` naming the sitemaps.org schema and the DiWoo schema of the version the app declares. Each `url` MUST hold, in this order, `loc` (the file's download URL), `lastmod` and one `diwoo:Document`. The page MUST hold the same documents, in the same order, as before this structure was adopted.

**Priority:** Must **Status:** Implemented

#### Scenario: DIWOO sitemap generated for publications
<!-- @e2e exclude Public XML contract; proven by SitemapServiceTest::testEveryDocumentOfAPageValidatesAgainstTheDiwooXsd and ::testEachUrlHoldsLocLastmodAndTheDocumentInThatOrder, which render a page through buildSitemap(). -->
- **GIVEN** publications with downloadable files exist for a catalog and WOO category
- **WHEN** a GET request is made to the `.../publications` sitemap endpoint
- **THEN** the response root MUST be a `urlset` in the sitemaps.org namespace
- **AND** each file MUST be one `url` whose children are `loc`, `lastmod` and `diwoo:Document`, in that order
- **AND** `diwoo:DiWoo` MUST NOT contain `loc` or `lastmod`

#### Scenario: an empty page is still a valid urlset
<!-- @e2e exclude Public XML contract; proven by SitemapServiceTest::testAPageWithoutFilesIsAnEmptyUrlset. -->
- **GIVEN** a catalog and category whose page has no downloadable files
- **WHEN** the sitemap page is requested
- **THEN** the response MUST be a `urlset` with no `url` children

### Requirement: Map publication + file metadata to DIWOO Document XML structure (WOO-006)
The system MUST map each downloadable file and its publication to a `diwoo:Document` holding one `diwoo:DiWoo` that validates against the official DiWoo 0.9.8 XSD whenever the publication has a title and its publisher, information category and handling type resolve (WOO-TOOI-001 to WOO-TOOI-003). `diwoo:DiWoo` MUST carry `diwoo:creatiedatum`, `diwoo:publisher`, `diwoo:titelcollectie` with `diwoo:officieleTitel` (the publication's title), `diwoo:format`, `diwoo:classificatiecollectie` and `diwoo:documenthandelingen`. `diwoo:atTime` and the sitemap `lastmod` MUST be `xs:dateTime` values with a time zone offset. A stored date or time without a zone MUST be read as UTC, so the output is the same on every server.

**Priority:** Must **Status:** Implemented

#### Scenario: publication and file metadata mapped to DIWOO
<!-- @e2e exclude Public XML contract; proven by SitemapServiceTest::testEveryDocumentOfAPageValidatesAgainstTheDiwooXsd against the vendored, hash-checked 0.9.8 XSD. -->
- **GIVEN** a publication with a title, a resolvable category, handling type and organisation, and attached files
- **WHEN** the DIWOO sitemap is generated
- **THEN** every `diwoo:Document` on the page MUST validate against the DiWoo 0.9.8 XSD without errors
- **AND** `diwoo:officieleTitel` MUST be the publication's title

#### Scenario: a date without a time zone
<!-- @e2e exclude Formatting rule; proven by SitemapServiceTest::testDatesAreWrittenAsUtcDateTimes, run under two time zones. -->
- **GIVEN** a file published `2024-03-01` and a publication date `2024-02-02 08:00:00`
- **WHEN** the documents are generated on a server in any time zone
- **THEN** `lastmod` and `diwoo:atTime` MUST read `2024-03-01T00:00:00+00:00` and `2024-02-02T08:00:00+00:00`

#### Scenario: the harness accepts what the standard accepts
<!-- @e2e exclude Test-harness control; proven by SitemapServiceTest::testTheOfficialExampleSitemapsValidate on the verbatim example sitemaps of the standard. -->
- **GIVEN** the example sitemaps the DiWoo 0.9.8 standard publishes
- **WHEN** their documents are validated the way the app's own pages are
- **THEN** their only errors MUST be the fictitious municipalities (`gm9999`, `gm9998`) they are published under, which the official organisation list does not hold

### Requirement: Bundled TOOI/DiWoo value lists and a DIWOO validator (WOO-TOOI-004)

The OpenCatalogi register bundle MUST ship the TOOI/DiWoo value lists
(informatiecategorieën, organisatie-identificatoren, soortHandeling) as
reference data, and admin/publisher settings MUST provide a "Validate DIWOO
output" action that runs the sitemap mapping in a dry-run mode and reports, per
document, any axis that could not resolve to an official value-list URI, and
every document whose publication has no title (axis `officieleTitel`). The
validator MUST be advisory — it MUST NOT prevent the sitemap from being served.

#### Scenario: value lists resolvable at render time
<!-- @e2e exclude Bundled reference data; proven by TooiVocabularyServiceTest and SitemapServiceTest resolving the lists at render time. -->
- **GIVEN** the OpenCatalogi register bundle is installed
- **WHEN** a DIWOO sitemap is generated
- **THEN** the informatiecategorie, organisatie, and soortHandeling value lists
  MUST be resolvable for binding

#### Scenario: validator reports an unresolved axis
<!-- @e2e exclude Dry-run report contract; proven by SitemapServiceTest::testMapDiwooDocumentOmitsUnresolvedAxesAndReports. -->
- **GIVEN** a catalog with one publication whose organisation lacks a `tooiIdentifier`
- **WHEN** the publisher runs "Validate DIWOO output"
- **THEN** the report MUST list that document with the unresolved `publisher` axis
- **AND** the sitemap endpoint MUST still serve XML for the catalog

#### Scenario: validator reports a publication without a title
<!-- @e2e exclude Dry-run report contract; proven by SitemapServiceTest::testAPublicationWithoutATitleIsReported. -->
- **GIVEN** a publication with a downloadable file and no title
- **WHEN** the publisher runs "Validate DIWOO output"
- **THEN** the report MUST list that document with the axis `officieleTitel`
- **AND** the sitemap page MUST still list the document
