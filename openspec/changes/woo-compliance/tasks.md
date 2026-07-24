# Tasks: woo-compliance

## Task 1: Deduplication Check

- [ ] 1.1 Search `openspec/specs/` and `openregister/lib/Service/` for any existing sitemap, robots.txt, or DIWOO mapping capability that overlaps with this change
  - **Expected finding**: No overlap. `SitemapService`, `RobotsController`, and `SitemapController` are OpenCatalogi-specific. No equivalent sitemap generation exists in OpenRegister's shared service layer.
  - Document findings (even "no overlap found") in a brief comment on this task when complete.
- [ ] 1.2 Verify `ObjectService::searchObjectsPaginated()` and `FileService::getFiles()` are the correct OpenRegister abstractions to use (not custom query builders)
  - **Acceptance criteria**: Code review confirms these are the only data-access paths used in `SitemapService`; no direct SQL or custom pagination logic exists.

---

## Task 2: Fix RobotsController hasWooSitemap gate (WOO-008)

- [ ] 2.1 Open `lib/Controller/RobotsController.php` and locate the catalog iteration loop in `getRobots()`
  - **Spec ref**: `specs/woo-compliance/spec.md` REQ-WOO-004, REQ-WOO-008
- [ ] 2.2 Add a filter that skips any catalog where `hasWooSitemap !== true` (or where the field is absent/falsy)
  - **Acceptance criteria**: Only catalogs with `hasWooSitemap: true` AND a non-empty `slug` contribute `Sitemap:` lines to the output
  - The existing slug check MUST be preserved alongside the new `hasWooSitemap` check
- [ ] 2.3 Add `@spec openspec/changes/woo-compliance/tasks.md#task-2` PHPDoc tag to the modified method (per ADR-003 spec traceability requirement)

---

## Task 3: Unit test — robots.txt hasWooSitemap gate

- [ ] 3.1 Create or extend `tests/Unit/Controller/RobotsControllerTest.php`
  - **Spec ref**: REQ-WOO-004-A, REQ-WOO-004-B, REQ-WOO-004-C, REQ-WOO-008-A
- [ ] 3.2 Write test: catalog with `hasWooSitemap: true` AND valid slug → 17 `Sitemap:` lines in output
- [ ] 3.3 Write test: catalog with `hasWooSitemap: false` AND valid slug → zero `Sitemap:` lines (bug fix regression guard)
- [ ] 3.4 Write test: catalog with `hasWooSitemap: true` but NO slug → zero `Sitemap:` lines
- [ ] 3.5 Write test: mixed list (one true, one false, one missing slug) → only 17 lines for the qualifying catalog
- [ ] 3.6 Write test: no qualifying catalogs → empty body or only `User-agent: *`
- [ ] 3.7 Confirm all new tests pass: `composer test -- --filter RobotsControllerTest`

---

## Task 4: Unit test — DIWOO metadata mapping

- [ ] 4.1 Create or extend `tests/Unit/Service/SitemapServiceTest.php`
  - **Spec ref**: REQ-WOO-002-B through REQ-WOO-002-D, REQ-WOO-006-A through REQ-WOO-006-C, REQ-WOO-010-A, REQ-WOO-010-B
- [ ] 4.2 Write test: all primary source fields populated → each DIWOO XML field uses the documented primary source
  - Fields to assert: `loc`, `lastmod`, `creatiedatum`, `publisher @resource`, `publisher text`, `format @resource`, `format text`, `informatiecategorie @resource`, `informatiecategorie text`, `soortHandeling`, `atTime`
- [ ] 4.3 Write test: `file.published` absent → `lastmod` falls back to `publication.updated`; `atTime` falls back to `publication.published`
- [ ] 4.4 Write test: `file.owner` absent → `publisher` text falls back to `publication.owner`
- [ ] 4.5 Write test: `file.downloadUrl` empty/absent → `<loc>` is empty string; no exception thrown
- [ ] 4.6 Write test: `soortHandeling` is always `"ontvangst"` regardless of input
- [ ] 4.7 Write test: `format` text is lowercase extension (e.g. `"PDF"` input → `"pdf"` output)
- [ ] 4.8 Write test: publication with 3 files → 3 `<diwoo:Document>` elements generated
- [ ] 4.9 Confirm all new tests pass: `composer test -- --filter SitemapServiceTest`

---

## Task 5: Unit test — sitemap index pagination

- [ ] 5.1 Write test in `SitemapServiceTest.php`:
  - **Spec ref**: REQ-WOO-001-A, REQ-WOO-005-A, REQ-WOO-005-B
  - Mock 2500 publications → assert 3 `<sitemap>` entries in index
  - Assert each entry's `<loc>` contains the correct `?page=N`
  - Assert `<lastmod>` of each entry is the `updated` of the first publication in that batch
- [ ] 5.2 Write test: exactly 1000 publications → 1 `<sitemap>` entry
- [ ] 5.3 Write test: 1001 publications → 2 `<sitemap>` entries; page 2 has 1 document
- [ ] 5.4 Write test: 0 publications → `<sitemapindex>` with 0 children, HTTP 200

---

## Task 6: Unit test — isValidSitemapRequest validation

- [ ] 6.1 Write test in `SitemapServiceTest.php`:
  - **Spec ref**: REQ-WOO-007-A, REQ-WOO-007-B, REQ-WOO-007-C, REQ-WOO-008-B
  - Invalid category code → returns false / triggers 400 response
  - Valid code but schema absent from catalog.schemas → returns false / triggers 400 response
  - Valid code AND schema in catalog AND `hasWooSitemap: true` → returns true
  - `hasWooSitemap: false` on catalog → returns false

---

## Task 7: Verify all 17 WOO category codes are handled

- [ ] 7.1 Confirm the INFO_CAT lookup map in `SitemapService` contains all 17 entries as listed in `specs/woo-compliance/spec.md` and `design.md`
  - **Spec ref**: REQ-WOO-003-A, REQ-WOO-003-B
- [ ] 7.2 Write a parameterised test (data provider) that calls `isValidSitemapRequest()` with each of the 17 category codes and a correctly configured catalog → each MUST pass
- [ ] 7.3 Write test: category code `sitemapindex-diwoo-infocat000.xml` (out of range) → must fail

---

## Task 8: Seed data verification

- [ ] 8.1 Check `lib/Settings/opencatalogi_register.json` for mock catalog objects
  - If no catalog with `hasWooSitemap: true` exists, add one (e.g. `woo-publicaties-amsterdam`) and one with `hasWooSitemap: false` (e.g. `intern-register-denhaag`) following the examples in `design.md`
  - Use `@self` envelope with `register: "opencatalogi"`, `schema: "catalog"`, unique `slug`
  - **Acceptance criteria**: At least 1 `hasWooSitemap: true` catalog and 1 `hasWooSitemap: false` catalog exist in the register template for testability

---

## Task 9: Documentation

- [ ] 9.1 Add or update `docs/features/woo-compliance.md` documenting:
  - The three public endpoints with example requests and responses
  - The `hasWooSitemap` configuration field and its effect on robots.txt
  - The DIWOO metadata mapping table (field sources and fallbacks)
  - The 17 WOO informatiecategorieen and their category codes
  - The dependency on auto-publishing for populated `downloadUrl` values

---

## Task 10: Spec sync

- [ ] 10.1 After the change is verified and all tasks are done, sync the delta spec back to the canonical location via `/opsx:sync`
  - Source: `openspec/changes/woo-compliance/specs/woo-compliance/spec.md`
  - Target: `openspec/specs/woo-compliance/spec.md` (create if absent)
