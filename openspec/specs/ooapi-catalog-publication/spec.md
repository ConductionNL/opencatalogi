# ooapi-catalog-publication Specification

## Purpose
TBD - created by archiving change ooapi-catalog-publication. Update Purpose after archive.
## Requirements
### Requirement: Per-catalog OOAPI 5.0 resource endpoints (OOAPI-001)

The system MUST serve `GET /api/catalogs/{catalogSlug}/ooapi/v5/organizations`,
`.../organizations/{id}`, `.../programs`, `.../programs/{id}`, `.../courses`,
`.../courses/{id}`, `.../courses/{courseId}/offerings`, and
`.../offerings/{id}` for a catalog. Endpoints MUST include CORS headers per
the `cross-origin-api-access` spec and MUST be registered in
`appinfo/routes.php`. Unlike DCAT/schema.org, these endpoints are NOT
anonymous-public — see OOAPI-007.

#### Scenario: List an institution's courses

- GIVEN a catalog "hva-onderwijs" with `hasOoapi: true` and 40 materialized
  `course` objects in its configured OOAPI scope
- WHEN an authenticated OOAPI consumer requests
  `GET /api/catalogs/hva-onderwijs/ooapi/v5/courses`
- THEN the response MUST be `200` with a paginated list of OOAPI 5.0 `course`
  resources

#### Scenario: OOAPI disabled for a catalog

- GIVEN a catalog whose `hasOoapi` configuration is `false` (the default)
- WHEN any of its OOAPI endpoints are requested
- THEN the response MUST be `404` with a descriptive error body
- AND the catalog MUST NOT appear in the federation directory's
  `ooapiEndpoint` advertisement (OOAPI-008)

#### Scenario: Unknown catalog slug or resource id

- GIVEN no catalog with slug "nope" exists, or a resource id that does not
  resolve within an OOAPI-enabled catalog
- WHEN its OOAPI endpoint is requested
- THEN the response MUST be `404` with a descriptive error (consistent with
  PUB-011)

### Requirement: `organization` resource renders the existing Organisation object live (OOAPI-002)

The `organization` resource MUST be rendered live from OpenCatalogi's
existing Organisation object (the same object used for DCAT publisher
completion and the PUB-CON-001 contacts leaf) via the `x-ooapi` schema
annotation (OOAPI-004). No new storage, register, or schema is introduced for
`organization`; it reuses the `organisations` `RegisterResolverService`
context already established by `opencatalogi-adopt-or-abstractions`.

#### Scenario: Organisation rendered as an OOAPI organization

- GIVEN an Organisation object configured as a catalog's owning institution
- WHEN `GET /api/catalogs/{slug}/ooapi/v5/organizations/{id}` is requested
- THEN the response MUST be an OOAPI 5.0 `organization` resource whose fields
  come from the `x-ooapi` mapping declared on the Organisation schema
- AND no OpenConnector materialization step is involved in producing it

### Requirement: `course`/`program`/`offering` resources are materialized, not rendered live from scholiq (OOAPI-003)

`course`, `program`, and `offering` objects MUST be OpenCatalogi-owned OR
objects, written by an OpenConnector `ooapi-catalog` Synchronization target
(a sibling change, not built by this spec) consuming scholiq's
`DataExchangeJob` (`target: ooapi-catalog`). OpenCatalogi MUST NOT call
scholiq directly (no cross-instance HTTP polling, no direct database access)
to render these resources — the object being served MUST already exist in
OpenCatalogi's own OpenRegister instance before any request for it is made.
Once materialized, these objects MUST be queried through OR object search
(`searchObjects`/`zoeken-filteren`) exactly like every other OpenCatalogi
public surface — no raw SQL against OR's magic tables
(`opencatalogi-adopt-or-abstractions`'s no-raw-SQL rule applies unchanged).

#### Scenario: A published scholiq Course appears as an OOAPI course after sync

- GIVEN a scholiq `Course` transitions to `lifecycle: published` and its
  `DataExchangeJob` (`target: ooapi-catalog`) has been processed by
  OpenConnector's Synchronization
- WHEN `GET /api/catalogs/{slug}/ooapi/v5/courses/{id}` is requested for the
  materialized object
- THEN the response MUST be a `200` OOAPI 5.0 `course` resource
- AND OpenCatalogi MUST NOT have made any request to the scholiq instance to
  produce that response

#### Scenario: An archived scholiq Course disappears from the OOAPI feed

- GIVEN a materialized `course` object exists because its source `Course`
  was previously published
- WHEN the source `Course` is archived (queuing scholiq's matching unpublish
  `DataExchangeJob`) and OpenConnector's Synchronization processes the
  removal
- THEN the materialized `course` object MUST no longer be returned by
  `GET /api/catalogs/{slug}/ooapi/v5/courses/{id}` (`404`)
- AND it MUST NOT appear in the courses list

#### Scenario: OpenCatalogi contains no raw OR-storage SQL for OOAPI resources

- GIVEN this change is implemented
- WHEN `lib/` is scanned
- THEN no OOAPI-related source file contains the string
  `oc_openregister_table_` or a reference to `information_schema`

### Requirement: Schema-driven `x-ooapi` mapping annotation, no PHP-hardcoded resource shape (OOAPI-004)

The OpenCatalogi-schema-to-OOAPI-5.0-resource field mapping MUST be declared
via an `x-ooapi` schema extension (`resource` type + optional `mapping`),
mirroring how `x-dcat` and `x-schema-org` declare behaviour on schemas. For
`course`/`program`/`offering` (already OOAPI-shaped by OpenConnector's
mapping, design.md D2), `x-ooapi` MAY declare only the `resource` type with
an identity mapping. For `organization`, `x-ooapi` MUST declare a real field
map from Organisation's native property names to OOAPI 5.0's `organization`
resource fields. A schema without an `x-ooapi` annotation MUST NOT be offered
as an OOAPI resource (no default mapping — unlike DCAT-004's conservative
fallback, there is no sensible generic OOAPI shape).

#### Scenario: Annotated schema controls the OOAPI mapping

- GIVEN a `course` schema declaring
  `"x-ooapi": { "resource": "course", "mapping": { "primaryCode.code": "code", "name": "name" } }`
- WHEN one of its objects is rendered as an OOAPI resource
- THEN the resource's `primaryCode.code` MUST carry the object's `code` value
  and its `name` MUST carry the object's `name` value

#### Scenario: Unannotated schema is not offered as an OOAPI resource

- GIVEN a schema in an OOAPI-enabled catalog's configured scope without an
  `x-ooapi` annotation
- WHEN the OOAPI resource list is built for that catalog
- THEN objects of that schema MUST NOT appear in any OOAPI resource endpoint

### Requirement: RIO identifier passthrough when present (OOAPI-005)

The `x-ooapi` mapping MUST surface a materialized `course`/`program`/`offering`
object's RIO `opleidingseenheid` / `aangeboden opleiding` identifier on the
rendered OOAPI resource when the source object carries one (copied through by
OpenConnector's mapping per the scholiq contract), and MUST omit the RIO
identifier field entirely — never emit an empty/null placeholder — when the
source object has none, the common case for most PO/VO/MBO-corporate
institutions per `nl_standards` 521.

#### Scenario: Course with a RIO identifier

- GIVEN a materialized `course` object carrying a RIO `opleidingseenheid` id
- WHEN it is rendered as an OOAPI resource
- THEN the resource MUST carry that RIO identifier

#### Scenario: Course without a RIO identifier

- GIVEN a materialized `course` object with no RIO identifier (the common
  case)
- WHEN it is rendered as an OOAPI resource
- THEN the resource MUST NOT carry a RIO identifier field at all

### Requirement: Offerings nest under their course (OOAPI-006)

An `offering` object (materialized from scholiq's `Cohort`) MUST carry a
reference to its parent `course` (and, where applicable, `program`).
`GET /api/catalogs/{slug}/ooapi/v5/courses/{courseId}/offerings` MUST return
exactly the `offering` objects referencing that course, as a filtered view
over the same `offering` schema — not a separate storage shape.

#### Scenario: List a course's offerings

- GIVEN a course with 3 materialized `offering` objects referencing it
- WHEN `GET /api/catalogs/{slug}/ooapi/v5/courses/{courseId}/offerings` is
  requested
- THEN exactly those 3 offerings MUST be returned

#### Scenario: Offering detail carries its parent course reference

- GIVEN a single `offering` object
- WHEN `GET /api/catalogs/{slug}/ooapi/v5/offerings/{id}` is requested
- THEN the response MUST include the parent `course` (and `program`, when
  present) reference

### Requirement: OOAPI 5.0 pagination (OOAPI-007)

List endpoints (`organizations`, `programs`, `courses`, and course-scoped `offerings`) MUST paginate per OOAPI 5.0's pagination convention; the exact
envelope/parameter names MUST be pinned against the published OOAPI 5.0
OpenAPI definition at implementation time (design.md open question 1). This
requirement fixes the invariant, not the wire-level parameter names: results
MUST NOT exceed the requested page size, and consecutive pages MUST cover the
full result set with no duplicates or gaps.

#### Scenario: Large course list paginates without gaps or duplicates

- GIVEN an OOAPI-enabled catalog with 250 materialized `course` objects
- WHEN the course list is paged through to completion
- THEN every course MUST appear exactly once across all pages
- AND no page MUST exceed the requested page size

### Requirement: Consumer-credential authenticated access (OOAPI-008)

OOAPI 5.0 endpoints MUST NOT be anonymous-public. Access MUST be gated by a
consumer credential resolved through OR's existing schema authorization
(conditional `authorization.read` rules requiring `authenticated`, the same
mechanism PUB-015 already exercises) rather than a bespoke auth framework.
Full SURFconext OAuth2 client-credentials federation is explicitly OUT of
scope for this requirement (design.md D3) and MUST be tracked as a follow-up,
not silently implied as done.

#### Scenario: Anonymous request is rejected

- GIVEN an OOAPI-enabled catalog
- WHEN an unauthenticated request is made to any of its OOAPI endpoints
- THEN the response MUST NOT return `200` with resource data (401/403 per OR's
  schema authorization behaviour)

#### Scenario: Authenticated consumer with a valid credential succeeds

- GIVEN a consumer credential issued for catalog "hva-onderwijs" via
  admin-settings
- WHEN a request carrying that credential is made to
  `GET /api/catalogs/hva-onderwijs/ooapi/v5/courses`
- THEN the response MUST be `200` with the course list

### Requirement: Federation directory advertises the OOAPI endpoint (OOAPI-009)

The Listing object's projection MUST gain an optional `ooapiEndpoint`
property (mirroring `dcatEndpoint`, DCAT-009), populated for catalogs whose
`hasOoapi` is truthy, so remote instances and the national directory
discover where to reach an institution's OOAPI feed. This rides the existing
directory serve/broadcast projection (`DirectoryService`'s single
listing-projection helper) — no new broadcast channel, no new cron, and
retry/dead-letter behaviour remains exactly as governed by
FED-OR-001/FED-OR-002.

#### Scenario: Directory entry carries the OOAPI base URL

- GIVEN an OOAPI-enabled catalog listed in this instance's directory
- WHEN a remote instance fetches the directory
- THEN the catalog's listing MUST include `ooapiEndpoint` with the absolute
  base URL for that catalog's OOAPI 5.0 resources

#### Scenario: Disabled catalog advertises nothing

- GIVEN a catalog with `hasOoapi: false`
- WHEN the directory is served
- THEN its listing MUST NOT contain an `ooapiEndpoint` value

### Requirement: Admin configuration for OOAPI publication (OOAPI-010)

Admin settings MUST provide: a per-catalog `hasOoapi` enable toggle (default
off), the OOAPI resource register/schema configuration
(`ooapi_courses_register`/`_schema`, `ooapi_programs_register`/`_schema`,
`ooapi_offerings_register`/`_schema`, resolved via `RegisterResolverService`
per `opencatalogi-adopt-or-abstractions`; `organization` reuses the existing
`organisations` context), consumer-credential issuance/revocation for OOAPI-008,
and a "Validate OOAPI feed" action that checks generated resources against the
OOAPI 5.0 mandatory-property shape and reports violations per resource. Every
new config key introduced by this requirement MUST be added to the
`admin-settings` inventory table in the same change (per
`opencatalogi-adopt-or-abstractions`'s admin-config inventory rule).

#### Scenario: Enabling OOAPI for a catalog

- GIVEN an admin enables `hasOoapi` for catalog "hva-onderwijs" and
  configures its `ooapi_courses_register`/`_schema` (etc.)
- WHEN the settings are saved
- THEN `GET /api/catalogs/hva-onderwijs/ooapi/v5/courses` MUST start serving
  materialized course data
- AND the directory listing MUST include `ooapiEndpoint`

#### Scenario: Validation reports a missing mandatory property

- GIVEN an OOAPI-enabled catalog where one materialized `course` object lacks
  a mandatory OOAPI 5.0 field
- WHEN the admin runs "Validate OOAPI feed"
- THEN the result MUST list that resource's id with the violated property
- AND the feed itself MUST still be served (validation is advisory, not a
  serving gate — consistent with DCAT-010's precedent)

