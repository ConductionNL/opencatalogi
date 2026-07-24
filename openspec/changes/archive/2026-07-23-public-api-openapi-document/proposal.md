---
kind: code
depends_on: []
---

# Proposal: public-api-openapi-document

## Summary
Replace the dead `openapi.json` stub with an accurate, maintained OpenAPI 3.1
document describing OpenCatalogi's public API surface, serve it from a public
CORS-enabled endpoint (`GET /api/openapi.json`), and add a parity test that
fails whenever a public route in `appinfo/routes.php` is missing from the
document (or vice versa) so the document cannot rot again.

## Motivation
The repo's `openapi.json` is a leftover template from another app entirely:
version `0.0.1`, license `agpl`, and its only path is
`/ocs/v2.php/apps/dsonextcloud/api`. Meanwhile the real public API is large
and deliberately public: search, catalog-scoped publications, attachments and
downloads, DCAT feeds with content negotiation, DIWOO sitemaps, federation
endpoints, and the CMS read APIs (pages/menus/themes/glossary) — all
CORS-enabled. Market research (2026-07-23) makes accurate API documentation a
procurement credential: developer.overheid.nl's API-register auto-validates
registered OpenAPI documents and generates client collections, and the NL API
Strategy (Common Ground) expects a published, accurate spec. A misleading
spec is worse than none — it actively fails vendor assessments. This is also
the cheapest fix in the wave's "positioning drift" risk cluster (logged as a
spectr insight alongside the AGPL/EUPL doc inconsistency).

## Scope
- Author `openapi.json` (OpenAPI 3.1) covering every `@PublicPage` route in
  `appinfo/routes.php`: search (incl. `_content`), catalogi, publications
  wildcard surface (`/api/{catalogSlug}`, `/{id}`, `/uses`, `/used`,
  `/attachments`, `/download`), DCAT (`/api/dcat`,
  `/api/catalogs/{slug}/dcat` with content-negotiation documented),
  sitemaps/robots, federation, directory, pages/menus/themes/glossary,
  schema.org endpoints. Correct metadata: title OpenCatalogi, EUPL-1.2
  license, version sourced from `appinfo/info.xml`.
- New public route `GET /api/openapi.json` serving the document with CORS
  headers, version field substituted from the installed app version.
- PHPUnit parity test: the set of public GET routes in `appinfo/routes.php`
  must equal the documented paths (modulo an explicit allowlist with
  reasons), so drift breaks the build.
- Remove the `dsonextcloud` stub content entirely.

## Out of scope
- Documenting admin-gated endpoints (retention, settings, WOO batch, setup)
  — follow-up; the public surface is the procurement-critical one.
- Registration with developer.overheid.nl (manual organisational step).
- Runtime OpenAPI generation from attributes (heavier; static + parity test
  gives the same guarantee at lower complexity).

## Impact
- Replaced: `openapi.json`. New: route + controller method (or `UiController`
  addition), `tests/unit/OpenApiParityTest.php`.
- Specs: new capability `api-documentation` (API-DOC-001..003).
