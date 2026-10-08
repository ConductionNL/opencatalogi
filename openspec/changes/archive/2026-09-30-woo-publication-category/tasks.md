# Tasks: woo-publication-category

## 1. Schema and read path

- [x] 1.1 Add `wooCategory` to the publication schema fragment (REQ-WPC-001). Verify: validate a real publication payload with and without the property against the real fragment, in `tests/Unit/Settings/PublicationWooCategoryTest.php`.
- [x] 1.2 Make the category sitemap select by `wooCategory`, keep the title lookup as fallback (REQ-WPC-002). Verify: `tests/Unit/Service/SitemapServiceTest.php` with three publications in two categories, one without a value.
- [x] 1.3 Read `wooCategory` first in `mapDiwooDocument()` (REQ-WPC-002). Verify: same test class, asserting the DiWoo category element.

## 2. Writing

- [x] 2.1 Add `GET /api/woo/categories` (admin) and the category select on the publication page (Publication data widget, see design corrections) (REQ-WPC-003). Verify: `tests/Unit/Controller/WooControllerTest.php` and `tests/e2e/woo-category.spec.ts`.
- [x] 2.2 Replace the hardcoded `verzoek` with `infocat014` in batch publish (REQ-WPC-004). Verify: `tests/Unit/Service/WooServiceTest.php` reads the published row back.

## 3. Docs and strings

- [x] 3.1 English and Dutch strings, docs page, `openspec validate woo-publication-category --strict`.
