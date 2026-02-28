# Publications

## Purpose

Publications are the core content objects in OpenCatalogi. They represent individual published documents, records, or data entries within a catalog. The publications API provides public, read-only access to publications scoped by catalog slug, including support for attachments, file downloads, and object relation traversal (uses/used-by). Publications are consumed by external frontends like tilburg-woo-ui.

## Requirements

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| PUB-001 | List publications scoped to a catalog slug with pagination and facets | Must | Implemented |
| PUB-002 | Retrieve a single publication by catalog slug and object UUID | Must | Implemented |
| PUB-003 | Publication list endpoint must filter by the catalog's configured registers and schemas | Must | Implemented |
| PUB-004 | Support multi-schema catalogs with UNION-based search across multiple magic tables | Must | Implemented |
| PUB-005 | Support `_extend` parameter for including related object data | Should | Implemented |
| PUB-006 | Retrieve publication attachments (files linked to a publication) | Must | Implemented |
| PUB-007 | Download publication files | Must | Implemented |
| PUB-008 | Retrieve outgoing relations (objects this publication references) via `/uses` | Must | Implemented |
| PUB-009 | Retrieve incoming relations (objects that reference this publication) via `/used` | Must | Implemented |
| PUB-010 | All public endpoints must include CORS headers | Must | Implemented |
| PUB-011 | Return 404 with descriptive error when catalog slug or publication ID not found | Must | Implemented |
| PUB-012 | Publication endpoints use wildcard `{catalogSlug}` routes (must be last in route order) | Must | Implemented |
| PUB-013 | Support filter parameter extraction from various formats (single, array, OR/AND operators) | Should | Implemented |
| PUB-014 | Fallback object location lookup across all magic tables when catalog register/schema search fails | Should | Implemented |
| PUB-015 | Schema authorization (RBAC) is enabled on the publication list for conditional access rules | Should | Implemented |

## Data Model

The publication schema is defined in `publication_register.json`.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| title | string | Yes | The title of the publication |
| summary | string | No | Brief description of the publication |
| description | string | No | Detailed description of the publication |
| organization | string | No | Reference to the owning organization (facetable) |
| themes | array(string) | No | List of theme references (facetable) |

Note: Publications also inherit OpenRegister system fields (`@self.uuid`, `@self.created`, `@self.updated`, `@self.published`, `@self.depublished`, `@self.schema`, `@self.register`, `@self.files`, etc.).

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
