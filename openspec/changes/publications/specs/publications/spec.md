---
status: implemented
---

# Publications Specification

## Purpose

Publications are the core content objects in OpenCatalogi. They represent individual published documents, records, or data entries within a catalog. The Publications API provides public, read-only access to publications scoped by catalog slug, including support for attachments, file downloads, and object relation traversal (uses/used-by). Publications are consumed by external frontends like tilburg-woo-ui.

## Context

OpenCatalogi exposes a wildcard `{catalogSlug}` route that resolves a catalog by its human-readable slug and returns its publications. A catalog may span multiple OpenRegister schemas and registers; the controller maps the slug to a `Catalog` entity (with its configured `schemas` and `registers` lists) and forwards those constraints to `ObjectService::searchObjectsPaginated`.

For single-object requests the controller first tries the catalog's own schemas/registers (fast path). If the object is not found there it falls back to `findObjectLocation`, a private method that scans every `oc_openregister_table_*` table via a UNION ALL SQL query to locate the register/schema combination — handling the case where an object was moved or the catalog configuration is stale.

**Relation to other specs:**
- `catalogs` spec: manages the catalog entity including slug assignment and schema/register configuration.
- `woo-transparency` spec: WOO-specific publication workflow built on top of the publications infrastructure.
- `search` spec: full-text and faceted search across publications; this spec covers only the public read API.

## Requirements

### REQ-PUB-001: List publications scoped to a catalog slug

The system MUST provide a paginated, faceted list of publications scoped to a catalog identified by its slug.

#### Scenario: Successful list request

- GIVEN a catalog with slug `gemeentenieuws` exists, configured with `schemas: [3, 5]` and `registers: [1]`
- WHEN a GET request is made to `/api/gemeentenieuws`
- THEN the response status MUST be `200 OK`
- AND `ObjectService::searchObjectsPaginated` MUST be called with `_schemas: [3, 5]`, `_register: 1`, `_rbac: true`
- AND the response body MUST include pagination fields: `results`, `total`, `page`, `pages`, `limit`, `offset`
- AND the response body MUST include a top-level `@catalog` object with `slug`, `title`, `schemas`, and `registers`
- AND the response MUST include `Access-Control-Allow-Origin` and related CORS headers

#### Scenario: Catalog not found returns 404

- GIVEN no catalog with slug `onbekend-portaal` exists
- WHEN a GET request is made to `/api/onbekend-portaal`
- THEN the response status MUST be `404 Not Found`
- AND the response body MUST contain a descriptive `message` field (no stack trace or internal SQL)

### REQ-PUB-002: Retrieve a single publication by catalog slug and UUID

The system MUST return full publication data for a single object identified by UUID within a catalog.

#### Scenario: Object found on fast path

- GIVEN a catalog with slug `vergunningen` configured with `schemas: [2]`, `registers: [1]`
- AND a publication with UUID `b3f9e721-1234-4abc-8def-000000000001` exists in schema 2, register 1
- WHEN a GET request is made to `/api/vergunningen/b3f9e721-1234-4abc-8def-000000000001`
- THEN `ObjectService::find` MUST be called with register `1`, schema `2`, UUID `b3f9e721-…`
- AND the response MUST include the rendered object (including `_extend` expansions if requested)
- AND the response status MUST be `200 OK`

#### Scenario: Object found via fallback location scan

- GIVEN a publication with UUID `c4e0f832-5678-4cde-9fab-000000000002` that exists in schema 4, register 2 (not in the catalog's configured schemas/registers)
- WHEN a GET request is made to `/api/vergunningen/c4e0f832-5678-4cde-9fab-000000000002`
- THEN the controller MUST first try the catalog's schemas/registers and find nothing
- AND the controller MUST execute `findObjectLocation` — a UNION ALL query across all `oc_openregister_table_*` tables
- AND on locating the object in schema 4, register 2, `ObjectService::find` MUST be called with those values
- AND the response status MUST be `200 OK`

#### Scenario: Object not found anywhere returns 404

- GIVEN a UUID `00000000-0000-0000-0000-000000000000` that does not exist in any magic table
- WHEN a GET request is made to `/api/vergunningen/00000000-0000-0000-0000-000000000000`
- THEN `findObjectLocation` MUST scan all tables and return null
- AND the response status MUST be `404 Not Found`
- AND the response body MUST contain a descriptive `message` field

### REQ-PUB-003: Filter by catalog's configured registers and schemas

The list endpoint MUST restrict results to objects in the schemas and registers declared on the resolved catalog.

#### Scenario: Multi-register catalog

- GIVEN a catalog configured with `schemas: [1, 3, 7]` and `registers: [1, 2]`
- WHEN the list endpoint is called
- THEN `searchObjectsPaginated` MUST receive `_schemas: [1, 3, 7]` and `_registers: [1, 2]`
- AND objects from schemas outside `[1, 3, 7]` MUST NOT appear in the results

### REQ-PUB-004: Multi-schema catalogs support UNION-based search

When a catalog spans multiple schemas, the controller MUST pass all schema IDs so OpenRegister's UNION ALL table strategy is applied.

#### Scenario: Two-schema catalog search

- GIVEN a catalog with `schemas: [2, 5]`
- WHEN the list endpoint is called with `?_search=bestemmingsplan`
- THEN `searchObjectsPaginated` receives `_schemas: [2, 5]`
- AND the UNION ALL strategy in OpenRegister returns matching objects from both magic tables

### REQ-PUB-005: Support `_extend` parameter for related object data

The single-object endpoint MUST accept an `_extend` query parameter and pass it to `ObjectService::renderEntity` to inline related objects.

#### Scenario: Extend organization relation

- GIVEN a publication that has an `organization` field referencing another object
- WHEN a GET request is made to `/api/woo/b3f9e721-…?_extend[]=organization`
- THEN `ObjectService::renderEntity` MUST receive `_extend: ['organization']`
- AND the response MUST include the organization object nested under the `organization` key

### REQ-PUB-006: Retrieve publication attachments

The attachments endpoint MUST return the list of files linked to a publication.

#### Scenario: Publication with two attached files

- GIVEN a publication `abc-123` in catalog `raadsstukken` with two attached PDF files
- WHEN a GET request is made to `/api/raadsstukken/abc-123/attachments`
- THEN the publication MUST first be verified to exist in the catalog
- AND `PublicationService::attachments(uuid)` MUST be called
- AND the response MUST contain an array of file metadata objects (name, mimeType, size, downloadUrl)
- AND the response status MUST be `200 OK`

### REQ-PUB-007: Download publication files

The download endpoint MUST stream file contents to the client.

#### Scenario: Download a PDF attachment

- GIVEN a publication `abc-123` with an attached file `besluit.pdf`
- WHEN a GET request is made to `/api/raadsstukken/abc-123/download`
- THEN `PublicationService::download(uuid, request)` MUST be called
- AND the response MUST have `Content-Type: application/pdf`
- AND the response MUST stream the file bytes

### REQ-PUB-008: Retrieve outgoing relations via `/uses`

The uses endpoint MUST return objects that the publication references (forward relations).

#### Scenario: Publication references two objects

- GIVEN a publication `pub-abc` that references objects `obj-def` and `obj-ghi`
- WHEN a GET request is made to `/api/woo/pub-abc/uses`
- THEN `findObjectLocation(pub-abc)` MUST be called to determine the register/schema context
- AND `ObjectService::getObjectUses(register, schema, uuid, _rbac: true)` MUST be called
- AND the response MUST contain `obj-def` and `obj-ghi`

### REQ-PUB-009: Retrieve incoming relations via `/used`

The used endpoint MUST return objects that reference the publication (reverse relations).

#### Scenario: Publication is referenced by one other object

- GIVEN object `obj-xyz` references publication `pub-abc`
- WHEN a GET request is made to `/api/woo/pub-abc/used`
- THEN `ObjectService::getObjectUsedBy(register, schema, uuid, _rbac: true)` MUST be called
- AND the response MUST contain `obj-xyz`

### REQ-PUB-010: All public endpoints include CORS headers

Every response from the publications controller MUST include the appropriate CORS headers to allow cross-origin access.

#### Scenario: CORS headers on list response

- GIVEN a browser on `https://tilburg.nl` makes a cross-origin GET to `/api/publicaties`
- WHEN the response is returned
- THEN the response MUST include `Access-Control-Allow-Origin: *` (or a configured origin)
- AND `Access-Control-Allow-Methods` and `Access-Control-Allow-Headers` MUST be present

#### Scenario: CORS preflight response

- GIVEN a browser sends OPTIONS `/api/publicaties`
- WHEN the OPTIONS handler fires
- THEN the response status MUST be `200 OK`
- AND all required CORS headers MUST be present
- AND the response body MAY be empty

### REQ-PUB-011: 404 with descriptive error on missing resource

The controller MUST return `404 Not Found` with a human-readable message when either the catalog slug or the publication UUID cannot be resolved.

#### Scenario: Descriptive error, no internal details

- GIVEN a request for a non-existent catalog slug or UUID
- WHEN the controller returns 404
- THEN the response body MUST include a `message` field describing what was not found
- AND the response MUST NOT include PHP stack traces, SQL queries, or internal file paths

### REQ-PUB-012: Wildcard `{catalogSlug}` routes placed last in route order

The `{catalogSlug}` routes MUST be declared after all specific named routes in `appinfo/routes.php` to prevent the slug pattern from matching paths intended for other controllers.

#### Scenario: Route ordering prevents slug collision

- GIVEN `routes.php` declares `GET /api/themes` before `GET /api/{catalogSlug}`
- WHEN a GET request is made to `/api/themes`
- THEN the ThemesController MUST handle the request
- AND the PublicationsController MUST NOT intercept it

### REQ-PUB-013: Filter parameter extraction supports OR/AND syntax

The `extractFilterValues` method MUST normalise all supported filter input formats into a uniform integer array.

#### Scenario: OR syntax from tilburg-woo-ui

- GIVEN a request with query parameter `?schemas[or]=2,5,7`
- WHEN `extractFilterValues` processes the filter value `{ "or": "2,5,7" }`
- THEN the result MUST be `[2, 5, 7]` as integer values
- AND these values MUST be passed to `searchObjectsPaginated` as `_schemas`

#### Scenario: Single integer filter

- GIVEN a request with `?registers=1`
- WHEN `extractFilterValues(1)` is called
- THEN the result MUST be `[1]`

### REQ-PUB-014: Fallback object location via magic table scan

When the catalog's configured schemas/registers do not contain the requested object, the controller MUST scan all OpenRegister magic tables via UNION ALL to find the object's true location.

#### Scenario: Object located in non-catalog schema

- GIVEN an object `uuid-xyz` was moved from schema 2 (catalog's schema) to schema 9 (not in catalog)
- WHEN `GET /api/vergunningen/uuid-xyz` is requested
- THEN the fast-path check (schema 2, register 1) MUST return no result
- AND `findObjectLocation(uuid-xyz)` MUST query `information_schema.tables` for `oc_openregister_table_%` tables
- AND the UNION ALL query MUST find `uuid-xyz` in `oc_openregister_table_2_9`
- AND the response MUST return the object as if found normally

#### Scenario: `findObjectLocation` returns null for unknown UUID

- GIVEN a UUID that exists nowhere in the database
- WHEN `findObjectLocation` runs the UNION ALL query
- THEN the query MUST return zero rows
- AND the method MUST return `null`
- AND the controller MUST return `404`

### REQ-PUB-015: RBAC enabled on the publication list

The list endpoint MUST pass `_rbac: true` to `ObjectService::searchObjectsPaginated` so schema-level access-control rules are enforced.

#### Scenario: RBAC restricts schema access

- GIVEN a schema configured with an RBAC rule that limits visibility to group `woo-medewerkers`
- WHEN an unauthenticated (public) request hits the list endpoint
- THEN `searchObjectsPaginated` MUST receive `_rbac: true`
- AND objects governed by the RBAC rule MUST be excluded from the response for non-members

## Non-Requirements

- This spec does NOT cover admin CRUD for publications (create, update, delete) — handled by the standard OpenRegister + `@conduction/nextcloud-vue` CRUD pattern.
- This spec does NOT cover catalog creation or configuration.
- This spec does NOT cover the WOO document assessment workflow.
- This spec does NOT cover full-text / semantic search — covered by the `search` spec.

## Dependencies

- **OpenRegister `ObjectService`** — `searchObjectsPaginated`, `find`, `renderEntity`, `getObjectUses`, `getObjectUsedBy`
- **OpenCatalogi `CatalogiService`** — `getCatalogBySlug` (with in-process caching)
- **OpenCatalogi `PublicationService`** — `attachments()`, `download()`
- **Nextcloud `IDBConnection`** — direct SQL for `findObjectLocation` UNION ALL query
- **Nextcloud `IAppConfig`** — configuration for default schema/register mappings
