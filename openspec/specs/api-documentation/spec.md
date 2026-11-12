---
status: done
---

# API Documentation (OpenAPI)

## Purpose

@e2e exclude pure backend/static-document spec except API-DOC-003, which carries its own @e2e reference; no other browser-observable UI surface.

OpenCatalogi ships an accurate, maintained OpenAPI 3.0 document (`openapi.json`)
describing its public, CORS-enabled API surface, served at
`GET /api/openapi.json`. A bidirectional parity test pins the document to
`appinfo/routes.php` so the two cannot drift. This replaced the dead
`dsonextcloud` template stub (change `public-api-openapi-document`,
2026-07-23) and exists for procurement credibility and
developer.overheid.nl API-register listing.

## Requirements

### Requirement: Accurate OpenAPI 3.0 document describes the public API (API-DOC-001)

**Priority:** Must **Status:** Implemented

The repository MUST ship an OpenAPI 3.0.3 document (`openapi.json`), the
version the NL API Design Rules require (/core/doc-openapi), that
describes OpenCatalogi's actual public API surface: every route registered in
`appinfo/routes.php` whose controller method is annotated `@PublicPage` /
`#[PublicPage]` MUST appear as a documented path, with request parameters
(including pagination, `_extend`, facet and `_content` query parameters where
supported) and response schemas that match the served envelopes. The document
MUST carry the app's real metadata: title "OpenCatalogi", license EUPL-1.2,
and a version equal to the `<version>` in `appinfo/info.xml`. Its server URL
ends in `/api/v1`, so documented paths are the route URLs without the `/api`
prefix. The public paths that implement an external standard are described in
`openapi.standards.json`, whose server is the unversioned base `/api`
(API-DOC-007); API-DOC-002 compares the public routes against both documents.
The document MUST pass the Spectral ruleset of the NL API Design Rules
(`https://static.developer.overheid.nl/adr/ruleset.yaml`). Content stemming from other
applications MUST NOT be present.

#### Scenario: stale foreign stub is gone

- GIVEN the shipped `openapi.json`,
- WHEN its paths are inspected,
- THEN no path may reference `dsonextcloud` or any non-OpenCatalogi app,
- AND `info.license` MUST identify EUPL-1.2,
- AND `info.version` MUST equal the version in `appinfo/info.xml`.

> @e2e exclude Static document contract; covered by PHPUnit parity test.

### Requirement: Public routes and documented paths cannot drift (API-DOC-002)

**Priority:** Must **Status:** Implemented

A PHPUnit test MUST compare the set of public routes parsed from
`appinfo/routes.php` against the paths documented in `openapi.json` in both
directions. A public route absent from the document, or a documented path
with no corresponding route, MUST fail the test unless listed in an explicit
in-test allowlist entry carrying a reason string.

#### Scenario: adding an undocumented public route breaks the build

- GIVEN a new `@PublicPage` route added to `appinfo/routes.php`,
- AND no corresponding path added to `openapi.json` nor allowlist entry,
- WHEN the unit test suite runs,
- THEN the parity test MUST fail naming the missing path.

> @e2e exclude Build-time guard, no runtime surface; PHPUnit.

### Requirement: The document is served publicly with CORS (API-DOC-003)

**Priority:** Must **Status:** Implemented

The system MUST serve the OpenAPI document at `GET /api/openapi.json` as a
public, CORS-enabled endpoint (same cross-origin posture as the rest of the
public API per the cross-origin-api-access spec), with `info.version`
substituted at serve time from the installed app version.

#### Scenario: anonymous client fetches the document cross-origin

- GIVEN an anonymous request with an `Origin` header,
- WHEN `GET /apps/opencatalogi/api/openapi.json` is requested,
- THEN the response MUST be HTTP 200 JSON with CORS headers,
- AND `info.version` MUST equal the installed app version.

@e2e tests/e2e/spec-coverage/openapi-document.spec.ts

### Requirement: Every API response carries the API version (API-DOC-004)

**Priority:** Must **Status:** Implemented

Every response of an endpoint under `/api/` MUST carry an `API-Version` header
with the installed app version, which is also the served document's
`info.version` (/core/version-header). Responses outside `/api/` MUST NOT
carry it.

#### Scenario: a public API call names its version

- GIVEN OpenCatalogi is installed at version 1.2.3,
- WHEN a client calls any endpoint under `/index.php/apps/opencatalogi/api/`,
- THEN the response MUST carry `API-Version: 1.2.3`.

> @e2e exclude A response header, not browser behaviour; covered by PHPUnit ApiContractMiddlewareTest.

### Requirement: API errors are problem details (API-DOC-005)

**Priority:** Must **Status:** Implemented

A JSON error response (status 400 or higher) of an endpoint under `/api/` MUST
be served as `application/problem+json` with the members `type`, `title` and
`status`, plus `detail` when the body already carries a `detail`, `message` or
`error` text (/core/error-handling/problem-details). Every member the body
already had MUST be kept, so existing callers see no change. A body that
already uses `type`, `title` or `status` for something else, or that is a JSON
list, MUST be left as it is.

#### Scenario: a not-found error becomes problem details

- GIVEN an endpoint answers `404` with `{"error": "Thing not found"}`,
- WHEN the response leaves the app,
- THEN its content type MUST be `application/problem+json`,
- AND its body MUST be `{"type": "about:blank", "title": "Not Found", "status": 404, "detail": "Thing not found", "error": "Thing not found"}`.

> @e2e exclude A content type and a body shape, not browser behaviour; covered by PHPUnit ApiContractMiddlewareTest.

### Requirement: Every API path is served under its major version (API-DOC-006)

**Priority:** Must **Status:** Implemented

Every route under `/api/` MUST also be served under `/api/v1/`, with the same controller, requirements and defaults (/core/uri-version). A route the app already declares under `/api/v1/` keeps its path; an unversioned path whose versioned form such a route answers keeps no successor and is listed in `ApiPathAliases::WITHOUT_SUCCESSOR`. The unversioned path MUST keep answering exactly as before, and its responses MUST carry a `Deprecation` header and a `Link` header naming the `/api/v1/` successor with `rel="successor-version"` (/core/deprecation-schedule). The OpenAPI document MUST name `/index.php/apps/opencatalogi/api/v1` as its server and MUST describe the public versioned paths (API-DOC-002).

#### Scenario: An unversioned path names its successor

@e2e exclude response headers, not browser behaviour; covered by PHPUnit ApiContractMiddlewareTest and ApiPathAliasesTest.

- GIVEN a client calls `GET /index.php/apps/opencatalogi/api/search`
- THEN it MUST receive the same answer as before
- AND the response MUST carry `Deprecation: @1794441600`
- AND the response MUST carry `Link: </index.php/apps/opencatalogi/api/v1/search>; rel="successor-version"`

#### Scenario: The versioned path answers the same request

@e2e exclude a route table property; covered by PHPUnit ApiPathAliasesTest and OpenApiParityTest.

- GIVEN a route `/api/search`
- WHEN a client calls `/index.php/apps/opencatalogi/api/v1/search`
- THEN the same controller method MUST answer it, without a `Deprecation` header

### Requirement: Standard protocol paths keep their own formats (API-DOC-007)

**Priority:** Must **Status:** Implemented

Paths that implement a protocol defined elsewhere (Sitemaps (DiWoo), robots.txt, DCAT-AP, OOAPI, schema.org, Atom feeds, Federation between instances, Health check, Prometheus metrics) MUST NOT get a `/api/v1/` alias, a `Deprecation` header, an `API-Version` header or a problem details rewrite: their formats belong to their standard. They are listed in `lib/Middleware/Support/StandardProtocolPaths.php` and are described in `openapi.standards.json`, under the unversioned base `/index.php/apps/opencatalogi/api`, rather than in `openapi.json`.

#### Scenario: A protocol path is left alone

@e2e exclude a response shape, not browser behaviour; covered by PHPUnit ApiContractMiddlewareTest.

- GIVEN a client calls `/index.php/apps/opencatalogi/api/woo/sitemaps/1` and it answers `400` with `{"error": "x"}`
- THEN the body MUST stay `{"error": "x"}`
- AND the response MUST carry no `API-Version` and no `Deprecation` header

## Dependencies

- **appinfo/routes.php** — source of truth for the public route set
- **ApiDocumentationController** — serves the document with serve-time version substitution
- **tests/Unit/OpenApiParityTest.php** — bidirectional anti-rot guard
- **ApiContractMiddleware** — the `API-Version` header and problem details on every API response
