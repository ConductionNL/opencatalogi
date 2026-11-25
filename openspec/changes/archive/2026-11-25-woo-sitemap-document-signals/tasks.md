# Tasks: woo-sitemap-document-signals

## 1. Pin the element names

- [x] 1.1 Read the DiWoo metadata XSD 0.9.1 and record the relation element names and their position in a fixture under `tests/Unit/Service/fixtures/` (REQ-WSD-002, REQ-WSD-003). Verify: the fixture validates against the XSD in `tests/Unit/Service/SitemapServiceTest.php`.

## 2. lastmod

- [x] 2.1 Compute `lastmod` as the latest of file `modified`, file `published` and publication `@self.updated` in `mapDiwooDocument()` (REQ-WSD-001). Verify: `SitemapServiceTest` with a publication updated after its file was published.

## 3. Relations

- [x] 3.1 Emit `isPartOf` with the publication's public API URL on every document of a multi-file publication (REQ-WSD-002). Verify: `SitemapServiceTest` with one and with three files.
- [x] 3.2 Emit `hasParts` on the file labelled `main-document` and point the other files' `isPartOf` at it (REQ-WSD-003). Verify: `SitemapServiceTest`, and the generated page validates against the XSD.
- [x] 3.3 Report a publication with two or more `main-document` labels in the DiWoo validator output (REQ-WSD-003). Verify: `SitemapServiceTest::testValidatorReportsTwoMainDocuments`.

## 4. Seed data, docs and strings

- [x] 4.1 Upload a decision and two annexes to a test publication, label the decision `main-document`, and read the sitemap page (REQ-WSD-003). Verify: a named manual check with `curl` on the category sitemap page, output pasted in the PR.
  - Ran 2026-11-25 on a sandbox Nextcloud 34 + MariaDB: a Woo publication (infocat002) with `besluit.pdf` labelled `main-document` and two annexes, taken through review to published. The anonymous category sitemap page lists three documents: the decision carries `diwoo:hasParts` naming both annexes, each annex carries `diwoo:isPartOf` pointing at the decision (REQ-WSD-002, REQ-WSD-003). Output in the PR body. Note: the page holds back any document whose publisher TOOI is not a real organisation code (the XSD checks the TOOI list), so a fictitious code such as `gm9999` empties it.
- [x] 4.2 Document the `main-document` label for editors in `docs/`. Verify: `npm run lint`.
