# Tasks: woo-sitemap-document-signals

## 1. Pin the element names

- [ ] 1.1 Read the DiWoo metadata XSD 0.9.1 and record the relation element names and their position in a fixture under `tests/Unit/Service/fixtures/` (REQ-WSD-002, REQ-WSD-003). Verify: the fixture validates against the XSD in `tests/Unit/Service/SitemapServiceTest.php`.

## 2. lastmod

- [ ] 2.1 Compute `lastmod` as the latest of file `modified`, file `published` and publication `@self.updated` in `mapDiwooDocument()` (REQ-WSD-001). Verify: `SitemapServiceTest` with a publication updated after its file was published.

## 3. Relations

- [ ] 3.1 Emit `isPartOf` with the publication's public API URL on every document of a multi-file publication (REQ-WSD-002). Verify: `SitemapServiceTest` with one and with three files.
- [ ] 3.2 Emit `hasParts` on the file labelled `main-document` and point the other files' `isPartOf` at it (REQ-WSD-003). Verify: `SitemapServiceTest`, and the generated page validates against the XSD.
- [ ] 3.3 Report a publication with two or more `main-document` labels in the DiWoo validator output (REQ-WSD-003). Verify: `SitemapServiceTest::testValidatorReportsTwoMainDocuments`.

## 4. Seed data, docs and strings

- [ ] 4.1 Upload a decision and two annexes to a test publication, label the decision `main-document`, and read the sitemap page (REQ-WSD-003). Verify: a named manual check with `curl` on the category sitemap page, output pasted in the PR.
- [ ] 4.2 Document the `main-document` label for editors in `docs/`. Verify: `npm run lint`.
