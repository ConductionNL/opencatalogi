# Tasks: public-api-openapi-document

- [ ] Freeze delta spec `specs/api-documentation/spec.md` (ADDED API-DOC-001..003); `openspec validate public-api-openapi-document` green
- [ ] Author `openapi.json` (OpenAPI 3.1): all `@PublicPage` routes from `appinfo/routes.php` — search (+`_content`), catalogi, publications wildcard surface, DCAT (+content negotiation), sitemaps/robots, federation, directory, pages/menus/themes/glossary, schema.org; envelope-granularity response schemas; EUPL-1.2; version = info.xml
  - Spec ref: API-DOC-001
  - Acceptance: `python3 -c "import json; json.load(open('openapi.json'))"` parses; no dsonextcloud content remains
- [ ] PHPUnit bidirectional parity test `tests/unit/OpenApiParityTest.php` (routes ↔ documented paths, allowlist with reasons, version-sync assertion vs info.xml)
  - Spec ref: API-DOC-002
  - Acceptance: test fails when a public route is removed from the document (prove once by mutation, then restore)
- [ ] Public `GET /api/openapi.json` endpoint: route in `appinfo/routes.php`, controller method with `#[PublicPage]` + CORS decoration, serve-time `info.version` substitution
  - Spec ref: API-DOC-003
  - Acceptance: anonymous curl with Origin header returns 200 + CORS headers + installed version
- [ ] E2E `tests/e2e/spec-coverage/openapi-document.spec.ts`: anonymous fetch asserts 200, JSON, version, one known path present
  - Spec ref: API-DOC-003
- [ ] `@spec` tags; hydra gates green locally (route-auth on the new public method, spec-coverage, e2e-coverage)
