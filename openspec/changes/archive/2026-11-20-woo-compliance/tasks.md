# Tasks: woo-compliance

## Task 1: Deduplication Check

- [x] 1.1 Search `openspec/specs/` and `openregister/lib/Service/` for any existing sitemap, robots.txt, or DIWOO mapping capability that overlaps with this change
  - **Expected finding**: No overlap. `SitemapService`, `RobotsController`, and `SitemapController` are OpenCatalogi-specific. No equivalent sitemap generation exists in OpenRegister's shared service layer.
  - Document findings (even "no overlap found") in a brief comment on this task when complete.
  - **Finding (build c2)**: no overlap. OpenRegister's `lib/Service/` has no sitemap, robots.txt or DiWoo code; the canonical `openspec/specs/woo-compliance/spec.md` is this capability's own spec.
- [x] 1.2 Verify `ObjectService::searchObjectsPaginated()` and `FileService::getFiles()` are the correct OpenRegister abstractions to use (not custom query builders)
  - **Acceptance criteria**: Code review confirms these are the only data-access paths used in `SitemapService`; no direct SQL or custom pagination logic exists.
  - **Finding (build c2)**: confirmed. `SitemapService`, `SitemapIndexBuilder` and `SitemapDirectives` read only through `ObjectService::searchObjectsPaginated()` (RBAC on) and `FileService::getFiles()`/`formatFiles()`; no query builder or SQL. Paging is OpenRegister's `_limit`/`_page`, with `next` deciding whether a further page exists.

---

## Task 2: Fix RobotsController hasWooSitemap gate (WOO-008)

- [x] 2.1 Open `lib/Controller/RobotsController.php` and locate the catalog iteration loop in `getRobots()`
  - **Spec ref**: `specs/woo-compliance/spec.md` REQ-WOO-004, REQ-WOO-008
- [x] 2.2 Add a filter that skips any catalog where `hasWooSitemap !== true` (or where the field is absent/falsy)
  - **Acceptance criteria**: Only catalogs with `hasWooSitemap: true` AND a non-empty `slug` contribute `Sitemap:` lines to the output
  - The existing slug check MUST be preserved alongside the new `hasWooSitemap` check
- [x] 2.3 Add `@spec openspec/changes/woo-compliance/tasks.md#task-2` PHPDoc tag to the modified method (per ADR-003 spec traceability requirement)

---

## Task 3: Unit test — robots.txt hasWooSitemap gate

- [x] 3.1 Create or extend `tests/Unit/Controller/RobotsControllerTest.php`
  - **Spec ref**: REQ-WOO-004-A, REQ-WOO-004-B, REQ-WOO-004-C, REQ-WOO-008-A
- [x] 3.2 Write test: catalog with `hasWooSitemap: true` AND valid slug → 17 `Sitemap:` lines in output
- [x] 3.3 Write test: catalog with `hasWooSitemap: false` AND valid slug → zero `Sitemap:` lines (bug fix regression guard)
- [x] 3.4 Write test: catalog with `hasWooSitemap: true` but NO slug → zero `Sitemap:` lines
- [x] 3.5 Write test: mixed list (one true, one false, one missing slug) → only 17 lines for the qualifying catalog
- [x] 3.6 Write test: no qualifying catalogs → empty body or only `User-agent: *`
- [x] 3.7 Confirm all new tests pass: `composer test -- --filter RobotsControllerTest`

---

## Task 4: Unit test — DIWOO metadata mapping

- [x] 4.1 Create or extend `tests/Unit/Service/SitemapServiceTest.php`
  - **Spec ref**: REQ-WOO-002-B through REQ-WOO-002-D, REQ-WOO-006-A through REQ-WOO-006-C, REQ-WOO-010-A, REQ-WOO-010-B
- [x] 4.2 Write test: all primary source fields populated → each DIWOO XML field uses the documented primary source
  - Fields to assert: `loc`, `lastmod`, `creatiedatum`, `publisher @resource`, `publisher text`, `format @resource`, `format text`, `informatiecategorie @resource`, `informatiecategorie text`, `soortHandeling`, `atTime`
- [x] 4.3 Write test: `file.published` absent → `lastmod` falls back to `publication.updated`; `atTime` falls back to `publication.published`
- [x] 4.4 Write test: `file.owner` absent → `publisher` text falls back to `publication.owner`
- [x] 4.5 Write test: `file.downloadUrl` empty/absent → `<loc>` is empty string; no exception thrown
- [x] 4.6 Write test: `soortHandeling` is always `"ontvangst"` regardless of input
- [x] 4.7 Write test: `format` text is lowercase extension (e.g. `"PDF"` input → `"pdf"` output)
- [x] 4.8 Write test: publication with 3 files → 3 `<diwoo:Document>` elements generated
- [x] 4.9 Confirm all new tests pass: `composer test -- --filter SitemapServiceTest`
- Build note (c2): 4.2, 4.3 (atTime) and 4.6 (the default) were already covered by `testMapDiwooDocument`, `testMapDiwooDocumentAtTimeFallsBackToPublicatiedatum`, `testADocumentsLastmodFollowsItsMetadataAndItsFile` and `testMapDiwooDocumentOmitsUnresolvedAxesAndReports`. Three cases follow the current design rather than this list: `lastmod` is the latest of the file's modified and published times and the publication's update (REQ-WSD-001), the publisher is the organisation and never an owner (4.4 is `testNoOwnerEverBecomesThePublisher`), and `soortHandeling` honours a declared value, `ontvangst` only when there is none (diwoo-sitemap-structure). Added: `testAFileWithoutTimesTakesThePublicationsUpdateAsLastmod`, `testAFileWithoutADownloadUrlMapsToAnEmptyLoc` (the page lists only files with a download URL), `testTheFormatTextIsTheLowercaseExtension`, `testEachFileOfAPublicationIsOneDocument`.

---

## Task 5: Unit test — sitemap index pagination

- [x] 5.1 Write test in `SitemapServiceTest.php`:
  - **Spec ref**: REQ-WOO-001-A, REQ-WOO-005-A, REQ-WOO-005-B
  - Mock 2500 publications → assert 3 `<sitemap>` entries in index
  - Assert each entry's `<loc>` contains the correct `?page=N`
  - Assert `<lastmod>` of each entry is the `updated` of the first publication in that batch
- [x] 5.2 Write test: exactly 1000 publications → 1 `<sitemap>` entry
- [x] 5.3 Write test: 1001 publications → 2 `<sitemap>` entries; page 2 has 1 document
- [x] 5.4 Write test: 0 publications → `<sitemapindex>` with 0 children, HTTP 200
- Build note (c2): `tests/Unit/Service/SitemapIndexPaginationTest.php` over `SitemapIndexBuilder` with a search that pages N publications. 5.4 found a defect: an empty index rendered one empty `<sitemap />` (which the sitemap schema refuses); `SitemapIndexBuilder::response()` now leaves the element out, as an empty page already did, and the two empty cases of `tests/fixtures/sitemap-index-golden.json` record the corrected body.

---

## Task 6: Unit test — isValidSitemapRequest validation

- [x] 6.1 Write test in `SitemapServiceTest.php`:
  - **Spec ref**: REQ-WOO-007-A, REQ-WOO-007-B, REQ-WOO-007-C, REQ-WOO-008-B
  - Invalid category code → returns false / triggers 400 response
  - Valid code but schema absent from catalog.schemas → returns false / triggers 400 response
  - Valid code AND schema in catalog AND `hasWooSitemap: true` → returns true
  - `hasWooSitemap: false` on catalog → returns false

---

## Task 7: Verify all 17 WOO category codes are handled

- [x] 7.1 Confirm the INFO_CAT lookup map in `SitemapService` contains all 17 entries as listed in `specs/woo-compliance/spec.md` and `design.md`
  - **Spec ref**: REQ-WOO-003-A, REQ-WOO-003-B
- [x] 7.2 Write a parameterised test (data provider) that calls `isValidSitemapRequest()` with each of the 17 category codes and a correctly configured catalog → each MUST pass
- [x] 7.3 Write test: category code `sitemapindex-diwoo-infocat000.xml` (out of range) → must fail

---

## Task 8: Seed data verification

- [x] 8.1 Check `lib/Settings/opencatalogi_register.json` for mock catalog objects
  - If no catalog with `hasWooSitemap: true` exists, add one (e.g. `woo-publicaties-amsterdam`) and one with `hasWooSitemap: false` (e.g. `intern-register-denhaag`) following the examples in `design.md`
  - Use `@self` envelope with `register: "opencatalogi"`, `schema: "catalog"`, unique `slug`
  - **Acceptance criteria**: At least 1 `hasWooSitemap: true` catalog and 1 `hasWooSitemap: false` catalog exist in the register template for testability
  - **Finding (build c2)**: `lib/Settings/opencatalogi_register.json` no longer exists. `lib/Settings/opencatalogi_mock_register.json` holds three catalogues with `hasWooSitemap: false` (`abc`, `bcd`, `cde`) and the example set `lib/Settings/example-sets/zuiddrecht.json` the catalogue `zuiddrecht` with `hasWooSitemap: true`, so both cases ship; nothing added.

---

## Task 9: Documentation

- [x] 9.1 Add or update `docs/features/woo-compliance.md` documenting:
  - The three public endpoints with example requests and responses
  - The `hasWooSitemap` configuration field and its effect on robots.txt
  - The DIWOO metadata mapping table (field sources and fallbacks)
  - The 17 WOO informatiecategorieen and their category codes
  - The dependency on auto-publishing for populated `downloadUrl` values

---

## Task 10: Spec sync

- [x] 10.1 After the change is verified and all tasks are done, sync the delta spec back to the canonical location via `/opsx:sync`
  - Source: `openspec/changes/woo-compliance/specs/woo-compliance/spec.md`
  - Target: `openspec/specs/woo-compliance/spec.md` (create if absent)
  - Synced: the canonical spec already carries REQ-WOO-001..010 as WOO-001..WOO-010, since extended by later changes (WOO-TOOI-001..004, WOO-HR-*, REQ-WPC-*). The per-requirement scenarios of this proposal-era spec (e.g. "soortHandeling is always ontvangst") are superseded by WOO-TOOI-003 and stay in the archive rather than contradict the canonical text.
