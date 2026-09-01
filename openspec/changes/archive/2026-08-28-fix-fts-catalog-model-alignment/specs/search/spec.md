## ADDED Requirements

### Requirement: Accept `_catalog` and `_catalogi[]` scope-narrowing params (SCH-PFTS-CAT-001)

The `/apps/opencatalogi/api/search` endpoint SHALL accept an optional `_catalog` query parameter (single catalog UUID or slug) and an optional `_catalogi[]` array parameter (multiple catalog UUIDs or slugs). When either parameter is provided, the search scope SHALL be limited to the union of registers and schemas declared by the matching catalog(s). When both are absent, the default scope applies (see SCH-PFTS-CAT-002). Clients MAY NOT widen scope via `_schema`, `_registers`, or `fq` on this endpoint (those parameters remain stripped, per existing discipline). Links to CAT-010, PUB-003, PUB-004.

#### Scenario: Single catalog scope via `_catalog`
- **WHEN** a caller sends `GET /apps/opencatalogi/api/search?_search=term&_catalog=my-catalog`
- **THEN** the search scope is limited to the registers and schemas declared by the catalog with slug `my-catalog`
- **AND** objects from schemas not in that catalog are absent from the results

#### Scenario: Multi-catalog scope via `_catalogi[]`
- **WHEN** a caller sends `GET /apps/opencatalogi/api/search?_search=term&_catalogi[]=cat-a&_catalogi[]=cat-b`
- **THEN** the search scope is the union of all registers and schemas declared by catalogs `cat-a` and `cat-b`
- **AND** objects from either catalog are present in the results

#### Scenario: Disallowed scope widening via `_schema`
- **WHEN** a caller sends `GET /apps/opencatalogi/api/search?_search=term&_schema=42`
- **THEN** the `_schema` parameter is silently stripped
- **AND** the scope is resolved from the catalog model as normal

### Requirement: Default scope is union of listed and published catalogs (SCH-PFTS-CAT-002)

When neither `_catalog` nor `_catalogi[]` is provided, the `/apps/opencatalogi/api/search` endpoint SHALL compute its scope as the union of all catalogs where `listed: true` AND the catalog object itself is published (passes its own `read` authorization rules under public context). Schemas without any explicit `read` authorization configuration SHALL be excluded from the anonymous search scope and the system SHALL log a warning for each such schema encountered. Links to CAT-010, PUB-003.

#### Scenario: Default scope includes all listed published catalogs
- **WHEN** a caller sends `GET /apps/opencatalogi/api/search?_search=term` with no `_catalog` or `_catalogi[]` params
- **THEN** the search scope is the union of registers and schemas from all catalogs with `listed: true` that are themselves published
- **AND** objects from schemas in non-listed or unpublished catalogs are absent from the results

#### Scenario: Schema without explicit read rules is excluded
- **WHEN** a schema in a listed published catalog has no `authorization.read` configuration
- **THEN** that schema is excluded from the anonymous search scope
- **AND** the system logs a warning identifying the schema by ID and slug

### Requirement: Catalog-derived scope replaces app-config scope (SCH-PFTS-CAT-003)

The `/apps/opencatalogi/api/search` endpoint SHALL NOT use `publication_register`, `publication_schema`, or `document_schema` app-config values to determine search scope. Scope SHALL be derived entirely from the catalog model via `buildCatalogSearchQuery()` + `resolveSchemaAndRegisterObjects()`. A misconfigured or missing app-config value SHALL NOT cause the endpoint to return an empty result set. Links to CAT-010, PUB-003, PUB-004.

#### Scenario: Scope independent of app-config
- **WHEN** the `publication_register` / `publication_schema` / `document_schema` app-config values are absent or incorrect
- **THEN** the search still returns results from the catalog-model-derived scope
- **AND** the endpoint does not return HTTP 200 with an empty result set due to a missing config value

#### Scenario: Multi-schema catalog returns results from all schemas
- **WHEN** a catalog declares three schemas (e.g. `publication`, `document`, `besluit`)
- **AND** the caller sends `GET /apps/opencatalogi/api/search?_search=term`
- **THEN** results from all three schemas are present in the response
- **AND** each result carries `@self.schema` set to the correct schema slug

## MODIFIED Requirements

### Requirement: Public search endpoint enforces uniform visibility for all callers (SCH-PFTS-001)

The `/apps/opencatalogi/api/search` endpoint SHALL return identical result sets regardless of the caller's authentication state. Authenticated callers (including Nextcloud admins and object owners) SHALL see the same results as anonymous callers. This SHALL be enforced by passing `_rbac_as_public: true` alongside `_rbac: true` to `ObjectService::searchObjectsPaginated()` (consuming the `rbac-as-public-toggle` primitive from OpenRegister, per ADR-022). The previous mechanism — a PHP post-filter (`isObjectPublic()`) — is removed; visibility is now enforced in SQL by the RBAC engine using only the `public` group's matching rules from each schema's `authorization.read` configuration. This change preserves the security intent of SCH-PFTS-001 while extending it to arbitrary schemas (not only `publication` and `document`).

#### Scenario: Admin caller sees same results as anonymous caller
- **WHEN** an authenticated Nextcloud admin sends `GET /apps/opencatalogi/api/search?_search=term`
- **THEN** the result set is identical to that returned for an anonymous (unauthenticated) caller with the same query
- **AND** the admin's own unpublished objects are absent from the results

#### Scenario: Object owner cannot see their own unpublished objects via search
- **WHEN** a user who owns a draft `publication` object (publicatiedatum in the future) sends `GET /apps/opencatalogi/api/search?_search=term`
- **THEN** the draft object is absent from the results
- **AND** the result set matches what an anonymous caller would see

#### Scenario: Published objects within window are visible to all callers
- **WHEN** a `publication` object has `publicatiedatum` in the past and `depublicatiedatum` absent or in the future
- **AND** any caller (anonymous or authenticated) sends `GET /apps/opencatalogi/api/search?_search=term`
- **THEN** the publication is present in the results

#### Scenario: Depublished objects are absent for all callers
- **WHEN** a `publication` object has `depublicatiedatum` in the past
- **AND** any caller sends `GET /apps/opencatalogi/api/search?_search=term`
- **THEN** the publication is absent from the results

#### Scenario: `total` reflects the true visible count
- **WHEN** any caller sends `GET /apps/opencatalogi/api/search?_search=term`
- **THEN** the `total` field in the response equals the actual count of objects visible under public RBAC
- **AND** `total` is NOT an undercount caused by PHP post-filtering

#### Scenario: `facets` and `facetable` are populated for anonymous callers
- **WHEN** an anonymous caller sends `GET /apps/opencatalogi/api/search?_search=term`
- **THEN** the `facets` and `facetable` fields are present and populated in the response
- **AND** facet counts reflect only publicly visible objects
