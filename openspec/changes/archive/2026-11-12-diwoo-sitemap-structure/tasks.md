# Tasks: diwoo-sitemap-structure

## 1. Authority fixtures

- [x] 1.1 Vendor the official example sitemaps `sitemap-alleenverplicht.xml` and `sitemap-uitgebreid.xml` verbatim next to the 0.9.8 XSD, with source URL and sha256 in `SOURCE.md` and `-text` in `.gitattributes`. Verify: `SitemapServiceTest::testTheOfficialExampleSitemapsValidate` checks the hashes and finds no error but the examples' fictitious municipalities.

## 2. Structure

- [x] 2.1 The page is a `urlset` with one `url` per file holding `loc`, `lastmod`, `diwoo:Document` in that order (WOO-002). Verify: `SitemapServiceTest::testEachUrlHoldsLocLastmodAndTheDocumentInThatOrder`, `::testAPageWithoutFilesIsAnEmptyUrlset`.
- [x] 2.2 `lastmod` and `diwoo:atTime` are UTC-read `xs:dateTime` values (WOO-006). Verify: `SitemapServiceTest::testDatesAreWrittenAsUtcDateTimes`, run with `TZ=UTC` and `TZ=Europe/Amsterdam`.
- [x] 2.3 `diwoo:titelcollectie/diwoo:officieleTitel` from the publication title; a missing title is reported as `officieleTitel` (WOO-006, WOO-TOOI-004). Verify: `SitemapServiceTest::testAPublicationWithoutATitleIsReported`.

## 3. Golden

- [x] 3.1 Replace the recorded-errors golden with one that validates every document of a rendered page green against the vendored XSD (WOO-006). Verify: `SitemapServiceTest::testEveryDocumentOfAPageValidatesAgainstTheDiwooXsd`, which fails on the code before this change; the documents golden is re-recorded and re-run plain.
