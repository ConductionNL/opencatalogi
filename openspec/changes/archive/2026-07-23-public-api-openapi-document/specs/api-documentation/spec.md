## ADDED Requirements

### Requirement: Accurate OpenAPI 3.1 document describes the public API (API-DOC-001)

The repository MUST ship an OpenAPI 3.1 document (`openapi.json`) that
describes OpenCatalogi's actual public API surface: every route registered in
`appinfo/routes.php` whose controller method is annotated `@PublicPage` /
`#[PublicPage]` MUST appear as a documented path, with request parameters
(including pagination, `_extend`, facet and `_content` query parameters where
supported) and response schemas that match the served envelopes. The document
MUST carry the app's real metadata: title "OpenCatalogi", license EUPL-1.2,
and a version equal to the `<version>` in `appinfo/info.xml`. Content stemming
from other applications MUST NOT be present.

#### Scenario: stale foreign stub is gone

- GIVEN the shipped `openapi.json`,
- WHEN its paths are inspected,
- THEN no path may reference `dsonextcloud` or any non-OpenCatalogi app,
- AND `info.license` MUST identify EUPL-1.2,
- AND `info.version` MUST equal the version in `appinfo/info.xml`.

> @e2e exclude Static document contract; covered by PHPUnit parity test.

### Requirement: Public routes and documented paths cannot drift (API-DOC-002)

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
