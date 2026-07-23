# Design: public-api-openapi-document

## Approach
Static, hand-maintained OpenAPI 3.1 document + a bidirectional parity test.
Static beats runtime generation here: the public envelopes are stable and
deliberately versioned, PHP attribute-driven generators would add a dependency
and still miss response shapes, and the parity test provides the anti-rot
guarantee that is the actual point.

## Decisions

### D1 — Parity is route-name based with an allowlist
The test parses `appinfo/routes.php` (it returns a plain array — include it,
no regex), filters to controller methods carrying PublicPage, converts NC
route patterns (`{catalogSlug}`) to OpenAPI path templates, and diffs against
`openapi.json` paths. Allowlist entries need a reason string; the wildcard
publication routes get one canonical documented form.

### D2 — Serve-time version substitution
`GET /api/openapi.json` reads the static file once, overwrites
`info.version` with `IAppManager::getAppVersion('opencatalogi')`, and returns
JSONResponse with the standard CORS decoration. The shipped file's version is
kept in sync by the parity test comparing it to `appinfo/info.xml` — serve-time
substitution covers installed-but-not-rebuilt instances.

### D3 — Response schemas at envelope granularity
Document the envelope contracts (results/total/page/pages/facets, `@self`
metadata object, error shape) with `additionalProperties: true` for
schema-driven publication payloads — publication properties are
register-defined per instance and MUST NOT be frozen in the static document.

## Alternatives considered
- **zircote/swagger-php attribute generation** — new composer dep, needs
  attributes on 13 controllers, still hand-writes response schemas. Rejected.
- **Documenting admin surface too** — doubles the work, procurement value is
  in the public surface. Follow-up change.
