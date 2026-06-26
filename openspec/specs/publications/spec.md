---
status: done
retrofit_extensions:
  - PUB-016
  - PUB-017
  - PUB-018
---

**Status**: in-progress
**Scope**: opencatalogi
**OpenSpec changes**:

- [add-publications-fts-engine](../../changes/add-publications-fts-engine/)

# Publications

## Purpose

@e2e exclude retrofit spec — public publications HTTP API contract (catalog-scoped lists, detail, attachments, downloads, relations) verified by Newman API tests, not browser-UI observable.

Publications are the core content objects in OpenCatalogi. They represent individual published documents, records, or data entries within a catalog. The publications API provides public, read-only access to publications scoped by catalog slug, including support for attachments, file downloads, and object relation traversal (uses/used-by). Publications are consumed by external frontends like tilburg-woo-ui.
## Requirements
### Requirement: List publications scoped to a catalog slug with pagination and facets (PUB-001)
The system MUST list publications scoped to a catalog slug with pagination and facets.

**Priority:** Must **Status:** Implemented

#### Scenario: List publications in a catalog
- GIVEN a catalog with slug "publications" with schemas [1, 2] and registers [1]
- WHEN a GET request is made to `/api/publications`
- THEN the catalog MUST be resolved by slug and publications returned scoped to its schemas/registers with pagination and facets

### Requirement: Retrieve a single publication by catalog slug and object UUID (PUB-002)
The system MUST retrieve a single publication by catalog slug and object UUID.

**Priority:** Must **Status:** Implemented

#### Scenario: Get a single publication
- GIVEN a publication with UUID "xyz-789" within a catalog
- WHEN a GET request is made to `/api/{catalogSlug}/xyz-789`
- THEN the matching publication MUST be rendered and returned

### Requirement: Publication list endpoint must filter by the catalog's configured registers and schemas (PUB-003)
The publication list endpoint MUST filter by the catalog's configured registers and schemas.

**Priority:** Must **Status:** Implemented

#### Scenario: List filtered by catalog registers and schemas
- GIVEN a catalog configured with specific registers and schemas
- WHEN its publications are listed
- THEN searchObjectsPaginated MUST be called with the catalog's `_schemas` and `_register`

### Requirement: Support multi-schema catalogs with UNION-based search across multiple magic tables (PUB-004)
The system MUST support multi-schema catalogs with UNION-based search across multiple magic tables.

**Priority:** Must **Status:** Implemented

#### Scenario: Multi-schema catalog strips non-universal ordering
- GIVEN a catalog spanning schemas with different property names
- WHEN ordering by a non-universal field is requested
- THEN only universal order fields (uuid, created, updated, published, depublished) MUST be allowed and non-universal order fields stripped

### Requirement: Support `_extend` parameter for including related object data (PUB-005)
The system SHALL support the `_extend` parameter for including related object data. It SHOULD expand the requested relations in the response.

**Priority:** Should **Status:** Implemented

#### Scenario: Extend a publication with related data
- GIVEN a request including an `_extend` parameter
- WHEN the publication is rendered
- THEN the requested related object data MUST be included in the response

### Requirement: Retrieve publication attachments (files linked to a publication) (PUB-006)
The system MUST retrieve publication attachments (files linked to a publication).

**Priority:** Must **Status:** Implemented

#### Scenario: Get publication attachments
- GIVEN a published publication with attached files
- WHEN a GET request is made to `/api/{catalogSlug}/{id}/attachments`
- THEN the publication MUST be verified in the catalog and its file metadata returned

### Requirement: Download publication files (PUB-007)
The system MUST allow downloading publication files.

**Priority:** Must **Status:** Implemented

#### Scenario: Download a publication file
- GIVEN a publication with downloadable files
- WHEN a GET request is made to `/api/{catalogSlug}/{id}/download`
- THEN the file content MUST be returned for download

### Requirement: Retrieve outgoing relations (objects this publication references) via `/uses` (PUB-008)
The system MUST retrieve outgoing relations (objects this publication references) via `/uses`.

**Priority:** Must **Status:** Implemented

#### Scenario: Traverse outgoing relations
- GIVEN a publication "abc" that references objects "def" and "ghi"
- WHEN a GET request is made to `/api/{catalogSlug}/abc/uses`
- THEN the referenced objects MUST be returned with RBAC enabled

### Requirement: Retrieve incoming relations (objects that reference this publication) via `/used` (PUB-009)
The system MUST retrieve incoming relations (objects that reference this publication) via `/used`.

**Priority:** Must **Status:** Implemented

#### Scenario: Traverse incoming relations
- GIVEN objects that reference publication "abc"
- WHEN a GET request is made to `/api/{catalogSlug}/abc/used`
- THEN the referencing objects MUST be returned

### Requirement: All public endpoints must include CORS headers (PUB-010)
All public endpoints MUST include CORS headers.

**Priority:** Must **Status:** Implemented

#### Scenario: CORS headers on publication endpoints
- GIVEN a cross-origin frontend
- WHEN it requests any public publication endpoint
- THEN the response MUST include CORS headers

### Requirement: Return 404 with descriptive error when catalog slug or publication ID not found (PUB-011)
The system MUST return 404 with a descriptive error when the catalog slug or publication ID is not found.

**Priority:** Must **Status:** Implemented

#### Scenario: Unknown catalog slug or publication returns 404
- GIVEN a request for an unknown catalog slug or publication ID
- WHEN the endpoint resolves it
- THEN the system MUST return HTTP 404 with a descriptive error message

### Requirement: Publication endpoints use wildcard `{catalogSlug}` routes (must be last in route order) (PUB-012)
Publication endpoints MUST use wildcard `{catalogSlug}` routes, which MUST be placed last in route order.

**Priority:** Must **Status:** Implemented

#### Scenario: Wildcard routes registered last
- GIVEN the `{catalogSlug}` wildcard routes with requirement `[a-z0-9-]+`
- WHEN routes are registered
- THEN they MUST be placed last so they do not shadow named routes like `/api/themes` or `/api/glossary`

### Requirement: Support filter parameter extraction from various formats (single, array, OR/AND operators) (PUB-013)
The system SHALL support filter parameter extraction from various formats (single value, array, OR/AND operators). It SHOULD normalise these into a list of integer values.

**Priority:** Should **Status:** Implemented

#### Scenario: Filter extraction with OR syntax
- GIVEN a request with `?schemas[or]=1,2,3`
- WHEN extractFilterValues() processes the filter
- THEN it MUST return `[1, 2, 3]` as integer values usable for multi-schema filtering

### Requirement: Fallback object location lookup across all magic tables when catalog register/schema search fails (PUB-014)
The system SHALL perform a fallback object-location lookup across all magic tables when the catalog register/schema search fails. It SHOULD use this fallback only after the fast path misses.

**Priority:** Should **Status:** Implemented

#### Scenario: Fallback location lookup on miss
- GIVEN a publication not found in the catalog's register/schema combinations
- WHEN the single-publication endpoint resolves it
- THEN findObjectLocation() MUST search all magic tables via a UNION ALL query to locate the object's register/schema

### Requirement: Schema authorization (RBAC) is enabled on the publication list for conditional access rules (PUB-015)
Schema authorization (RBAC) SHALL be enabled on the publication list so conditional access rules apply. It SHOULD enforce schema-level access conditions.

**Priority:** Should **Status:** Implemented

#### Scenario: RBAC applied on the publication list
- GIVEN schemas with conditional access rules
- WHEN the publication list is served
- THEN schema authorization MUST be enabled so conditional access rules are evaluated

### Requirement: Publish a publication object from the frontend store (PUB-016)
The frontend object store SHALL publish a publication by POSTing to the OpenRegister
publish endpoint `/index.php/apps/openregister/api/objects/{register}/{schema}/{id}/publish`.
The register and schema identifiers are resolved from the object's `@self` metadata
(falling back to top-level `register`/`schema`) and reduced to bare IDs via `extractId`.
On success the store replaces the active `publication` object with the server's response
and removes the object from the current multi-select selection. Per-object loading and
error state are tracked under the keys `publish_{id}`.

**Priority:** Must **Status:** Implemented

#### Scenario: Publish an unpublished publication
- GIVEN a publication object with resolvable `id`, `register`, and `schema`
- WHEN `objectStore.publishObject(object)` is called
- THEN a POST request MUST be sent to the OpenRegister `.../{id}/publish` endpoint
- AND the returned object MUST replace the active `publication` if it matches the object's id
- AND the object MUST be removed from the selected-objects list if currently selected

#### Scenario: Publish with missing register/schema metadata
@e2e exclude internal store guard — this is a pre-request validation throw inside objectStore with no browser-rendered UI feedback; covered by Jest store unit test.
- GIVEN a publication object lacking `id`, `register`, or `schema`
- WHEN `objectStore.publishObject(object)` is called
- THEN the store MUST throw an error before issuing any HTTP request

### Requirement: Depublish a publication object from the frontend store (PUB-017)
The frontend object store SHALL depublish a publication by POSTing to the OpenRegister
depublish endpoint `/index.php/apps/openregister/api/objects/{register}/{schema}/{id}/depublish`,
mirroring the publish flow: register/schema resolved from `@self`, active publication
replaced with the server response on success, the object removed from the current
selection, and loading/error state tracked under `depublish_{id}` keys.

**Priority:** Must **Status:** Implemented

#### Scenario: Depublish a published publication
- GIVEN a published publication object with resolvable `id`, `register`, and `schema`
- WHEN `objectStore.depublishObject(object)` is called
- THEN a POST request MUST be sent to the OpenRegister `.../{id}/depublish` endpoint
- AND the returned object MUST replace the active `publication` if it matches the object's id

#### Scenario: Depublish failure surfaces an error
@e2e exclude store error-state tracking — the error is recorded in Pinia store state under a key; no reliably-automatable error-state UI is rendered without a failing API endpoint in the test environment; covered by Jest store unit test.
- GIVEN the depublish endpoint returns a non-OK HTTP status
- WHEN `objectStore.depublishObject(object)` is called
- THEN the store MUST record the error under `depublish_{id}` and re-throw it

### Requirement: Provide a publish/depublish confirmation dialog (PUB-018)
The system SHALL provide a `PublishPublicationDialog` shown when the navigation store's
dialog is `publishPublication`. The dialog reads the active `publication` from the object
store, displays a "Publish publication" or "Depublish publication" heading based on the
publication's status, and renders a confirmation prompt with Publish/Cancel actions plus
success and error note cards.

**Priority:** Should **Status:** Implemented

#### Scenario: Open the publish dialog for an unpublished publication
- GIVEN the active publication has a status other than `Published`
- WHEN the navigation store dialog is set to `publishPublication`
- THEN the dialog MUST render with a "Publish publication" heading and the publication title
- AND a primary Publish button MUST be shown

#### Scenario: Open the dialog for a published publication
- GIVEN the active publication has status `Published`
- WHEN the dialog is opened
- THEN the dialog MUST render with a "Depublish publication" heading

> **Notes (observed-but-buggy — not fixed by this retrofit):**
> The dialog's confirm handler `handleCopy()` does NOT call `publishObject`/`depublishObject`.
> It reads the active **menu** object, clones it with a `(kopie)` title, and calls
> `objectStore.createObject('menu', ...)` — clearly copy-pasted from a "copy menu" dialog.
> So clicking Publish currently copies a menu instead of publishing the publication.
> Additionally, the `catch (error)` block shadows the outer `error` ref and then assigns
> `error.value`, which throws on the shadowed local. REQ PUB-018 specifies the *intended*
> publish/depublish confirmation behavior; the handler bug is tracked separately and must
> be fixed in a code change, not silently re-specified here.

### Requirement: Maps leaf widget on geo publications (PUB-MAP-001)
The system MUST surface the geometry of a publication's `geo` GeoJSON property by
**placing the OpenRegister maps leaf widget** on the publication detail page via
the app manifest (`detail.config` widgets, ADR-024 / ADR-036) — NOT by building a
bespoke Leaflet/map component in OpenCatalogi (hydra ADR-022). The widget binds to
`publication.geo` and renders points / areas / routes on a map.

> @e2e exclude OR maps-leaf manifest-placement + graceful-degradation contract — the rendered map is produced by the external OpenRegister maps leaf (integration registry, ADR-022/036), not by opencatalogi, which only declares the widget placement in the manifest and MUST NOT ship a bespoke Leaflet component. The assertion is the manifest placement + the "maps integration required" / clean-empty degradation when the leaf or geo data is absent (the default state in any instance without the leaf installed). Verified by vitest over the manifest placement and the degradation branch; the detail page is reachable via spa-deep-link-routing::open-a-deep-link-directly.

#### Scenario: Publication with geo data shows a map
- GIVEN a publication whose `geo` property contains valid GeoJSON
- WHEN a user opens the publication detail page
- THEN the maps leaf widget renders the geometry on a map
- AND OpenCatalogi does NOT ship a bespoke map component for this

#### Scenario: Publication without geo data
- GIVEN a publication with no `geo` data (or invalid GeoJSON)
- WHEN the publication detail page renders
- THEN the maps widget hides or shows a clean empty state (no error)

#### Scenario: Maps leaf absent
- GIVEN the OpenRegister maps leaf / integration is not available
- WHEN the publication detail page renders
- THEN the maps widget degrades gracefully ("maps integration required")

### Requirement: Contacts leaf widget on the Organisation detail (PUB-CON-001)
The system MUST surface an Organisation's contact persons / addresses by
**placing the OpenRegister contacts leaf widget** on the Organisation
object-detail surface via the app manifest (ADR-024 / ADR-036) — NOT via ad-hoc
free-text contact fields or a bespoke contact component (hydra ADR-022). The
Organisation is the contactable bestuursorgaan behind publications.

> @e2e exclude OR contacts-leaf manifest-placement + graceful-degradation contract — the contact list is produced by the external OpenRegister contacts leaf (integration registry, ADR-022/036), not by opencatalogi, which only declares the widget placement and MUST NOT keep a parallel contact model. The assertion is the manifest placement + the "contacts integration required" degradation when the leaf is absent (the default state without the leaf installed). Verified by vitest over the manifest placement and the degradation branch; the Organisation detail page is reachable via spa-deep-link-routing::open-a-deep-link-directly.

#### Scenario: View an Organisation's linked contacts
- GIVEN an Organisation with linked OR contacts
- WHEN a user opens the Organisation detail page
- THEN the contacts leaf widget lists the linked contact persons / addresses
- AND OpenCatalogi does NOT maintain a parallel contact model for this

#### Scenario: Contacts leaf absent
- GIVEN the OpenRegister contacts leaf / integration is not available
- WHEN the Organisation detail page renders
- THEN the contacts widget degrades gracefully ("contacts integration required")

### Requirement: Optional photos and bookmarks leaf widgets on publications (PUB-MEDIA-001)
The system MUST surface any publication image-attachment gallery or curated
external-link list on the publication detail page by **placing the OpenRegister
photos and bookmarks leaf widgets** via the app manifest (ADR-024 / ADR-036) —
NOT by building bespoke gallery / link components (hydra ADR-022). These
placements are optional and each MUST be gated independently on its leaf's
availability; neither placement MUST block the maps (PUB-MAP-001) or contacts
(PUB-CON-001) placements.

> @e2e exclude OR photos/bookmarks-leaf optional manifest-placement + graceful-degradation contract — the gallery / link list is produced by the external OpenRegister photos and bookmarks leaves (integration registry, ADR-022/036), not by opencatalogi, which only declares optional, independently-gated placements and MUST NOT ship bespoke gallery/link components. The assertion is the manifest placement + each widget being omitted when its leaf is absent without affecting the required widgets (the default state without the leaves installed). Verified by vitest over the manifest placement and the per-leaf gating branch.

#### Scenario: Photos widget shows an image gallery
- GIVEN a publication with image attachments
- AND the photos leaf is available
- WHEN a user opens the publication detail page
- THEN the photos leaf widget renders the images as a gallery

#### Scenario: Bookmarks widget shows curated links
- GIVEN a publication with curated external links
- AND the bookmarks leaf is available
- WHEN a user opens the publication detail page
- THEN the bookmarks leaf widget lists the links

#### Scenario: Optional leaf absent
- GIVEN the photos or bookmarks leaf is not available
- WHEN the publication detail page renders
- THEN that optional widget is omitted without affecting the required widgets

## Data Model

The publication schema is defined in `publication_register.json`.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| title | string | Yes | The title of the publication |
| summary | string | No | Brief description of the publication |
| description | string | No | Detailed description of the publication |
| organization | string | No | Reference to the owning organization (facetable) |
| themes | array(string) | No | List of theme references (facetable) |

Note: Publications also inherit OpenRegister system fields (`@self.uuid`, `@self.created`, `@self.updated`, `@self.schema`, `@self.register`, `@self.files`, etc.). Publication visibility is NOT an OR system field: it is governed by the object's own `publicatiedatum`/`depublicatiedatum` fields under OR's RBAC predicate `{group:public, match:{publicatiedatum:{$lte:$now}}}`. The former object-level `@self.published`/`@self.depublished` predicate has been removed from OpenRegister core.

## User Interface

The Nextcloud admin UI provides:
- **PublicationIndex.vue** (`/publications/{catalogSlug}`) - Publications list filtered by catalog
- **PublicationDetail.vue** (`/publications/{catalogSlug}/{id}`) - Single publication detail view
- **PublicationList.vue** - Publication list component
- **PublicationTable.vue** - Tabular publication display
- **PublishPublicationDialog.vue** - Dialog for publishing/depublishing
- **Various object modals** - ViewObject, DeleteObject, MergeObject, LockObject, etc.

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/{catalogSlug}` | List publications in a catalog (public, paginated, with facets) |
| GET | `/api/{catalogSlug}/{id}` | Get single publication detail |
| GET | `/api/{catalogSlug}/{id}/attachments` | Get publication attachments/files |
| GET | `/api/{catalogSlug}/{id}/download` | Download publication files |
| GET | `/api/{catalogSlug}/{id}/uses` | Get outgoing relations (this publication references) |
| GET | `/api/{catalogSlug}/{id}/used` | Get incoming relations (other objects reference this) |
| OPTIONS | `/api/{catalogSlug}` | CORS preflight |
| OPTIONS | `/api/{catalogSlug}/{id}` | CORS preflight |
| OPTIONS | `/api/{catalogSlug}/{id}/uses` | CORS preflight |
| OPTIONS | `/api/{catalogSlug}/{id}/used` | CORS preflight |
| OPTIONS | `/api/{catalogSlug}/{id}/attachments` | CORS preflight |
| OPTIONS | `/api/{catalogSlug}/{id}/download` | CORS preflight |

All `{catalogSlug}` routes have the requirement `[a-z0-9-]+` and must be placed LAST in `routes.php` to avoid matching specific named routes like `/api/themes`, `/api/glossary`, etc.

## PublicationsController Helper Methods (Gap 22)

### findObjectLocation() - Magic Table Scanning

The `PublicationsController` contains a private `findObjectLocation(string $uuid)` method that scans all OpenRegister magic tables to find which register/schema combination an object belongs to:

1. **Discovery**: Queries `information_schema.tables` for all tables matching `oc_openregister_table_%`
2. **UNION ALL**: Builds a single SQL query that searches all magic tables simultaneously:
   ```sql
   (SELECT 1 AS register_id, 2 AS schema_id FROM oc_openregister_table_1_2 WHERE _uuid = 'abc-123')
   UNION ALL
   (SELECT 1 AS register_id, 3 AS schema_id FROM oc_openregister_table_1_3 WHERE _uuid = 'abc-123')
   ...
   LIMIT 1
   ```
3. **Table name parsing**: Register and schema IDs are extracted from table names via regex: `oc_openregister_table_(\d+)_(\d+)`
4. **Returns**: `{ register: int, schema: int }` or null if not found

This is used as a fallback when the catalog's register/schema combinations don't contain the requested object (e.g., object was moved or catalog config is stale).

### extractFilterValues() - Filter Syntax with [or] Support

The `extractFilterValues(mixed $filter)` method normalizes various filter formats into an array of integer values:

| Input Format | Example | Result |
|-------------|---------|--------|
| Single numeric | `1` | `[1]` |
| Simple array | `[1, 2, 3]` | `[1, 2, 3]` |
| OR operator (array) | `{ "or": [1, 2, 3] }` | `[1, 2, 3]` |
| OR operator (string) | `{ "or": "1,2,3" }` | `[1, 2, 3]` |
| AND operator (array) | `{ "and": [1, 2] }` | `[1, 2]` |
| AND operator (string) | `{ "and": "1,2" }` | `[1, 2]` |
| Comma-separated string | `"1,2,3"` | `[1, 2, 3]` |

This supports the `[or]` query parameter syntax used by frontends: `?schemas[or]=1,2,3`.

## Scenarios

### Scenario: List publications in a catalog
- GIVEN a catalog with slug "publications" exists with schemas [1, 2] and registers [1]
- WHEN a GET request is made to `/api/publications`
- THEN the catalog is resolved by slug (from cache or DB)
- AND ObjectService.searchObjectsPaginated is called with `_schemas: [1, 2]`, `_register: 1`
- AND results include pagination (results, total, page, pages, limit, offset)
- AND `@catalog` metadata is added to the response (slug, title, schemas, registers)
- AND CORS headers are included

### Scenario: Multi-schema catalog strips non-universal ordering
- GIVEN a catalog spanning schemas with different property names (e.g., "name" vs "naam")
- WHEN ordering by a non-universal field is requested
- THEN only universal order fields (uuid, created, updated, published, depublished) are allowed
- AND non-universal order fields are stripped from the query

### Scenario: Get single publication with fallback location
- GIVEN a publication with UUID "xyz-789" exists
- WHEN a GET request is made to `/api/publications/xyz-789`
- THEN the controller first tries the catalog's register/schema combinations (fast path)
- AND if not found, findObjectLocation() searches all magic tables via UNION ALL query (fallback)
- AND if found, renders the entity with `_extend` parameters
- AND returns 404 if not found in any location

### Scenario: Get publication attachments
- GIVEN a published publication with UUID "abc-123" and attached files
- WHEN a GET request is made to `/api/publications/abc-123/attachments`
- THEN the publication is verified to exist in the catalog
- AND PublicationService.attachments() returns the file metadata

### Scenario: Traverse publication relations
- GIVEN a publication "abc" that references objects "def" and "ghi"
- WHEN a GET request is made to `/api/publications/abc/uses`
- THEN ObjectService.getObjectUses() returns "def" and "ghi" with RBAC enabled
- AND register/schema context is set via findObjectLocation for magic table routing

### Scenario: Filter extraction with OR syntax
- GIVEN a request with `?schemas[or]=1,2,3`
- WHEN extractFilterValues() processes the filter
- THEN it returns `[1, 2, 3]` as integer values
- AND these can be used to filter across multiple schemas

## Dependencies

- **OpenRegister ObjectService** - searchObjectsPaginated, searchObjects, find, renderEntity, getObjectUses, getObjectUsedBy, buildSearchQuery
- **CatalogiService** - getCatalogBySlug for catalog resolution and caching
- **PublicationService** - attachments(), download() for file operations
- **IDBConnection** - Direct SQL for findObjectLocation across all magic tables
- **Nextcloud IAppConfig** - Configuration for schema/register mappings
