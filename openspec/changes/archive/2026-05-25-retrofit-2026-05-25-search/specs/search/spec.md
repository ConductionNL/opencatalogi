---
retrofit_extensions:
  - SCH-016
  - SCH-017
  - SCH-018
  - SCH-019
---

# Search

## ADDED Requirements

### Requirement: Frontend search store queries federated publications (SCH-016)
The frontend search store SHALL query publications via the federation endpoint
`GET /index.php/apps/opencatalogi/api/federation/publications`, building query parameters
from the current search term (`_search`), pagination (`_page` / `_limit`), active filters,
ordering (`_order[field]=direction`), and the federation flags `_facetable=true`,
`_aggregate=true`, plus `_extend[]` of `@self.schema` (and `@self.register`). Loading and
error state are tracked on the store; results, total, and facet data are stored for the UI.

**Priority:** Must **Status:** Implemented

#### Scenario: Run a publication search
- GIVEN a search term and optional filters
- WHEN `searchStore.searchPublications()` is called
- THEN a request MUST be sent to `/api/federation/publications` with `_search`, pagination,
  `_facetable=true`, `_aggregate=true`, and the active filters/ordering encoded
- AND results, total, and facets MUST be stored on success

### Requirement: Facet discovery and active-facet query building (SCH-017)
The frontend search store SHALL discover facetable fields via
`discoverFacetableFields()` (populating the facetable-fields map and tracking
`facetsLoading`), and SHALL translate the user's enabled facets into request parameters via
`buildFacetQuery()`, including `@self` metadata facets, so that enabling a facet narrows the
next search.

**Priority:** Should **Status:** Implemented

#### Scenario: Discover facetable fields
- GIVEN the search view loads
- WHEN `discoverFacetableFields()` runs
- THEN the store's facetable-fields map MUST be populated and `facetsLoading` toggled

#### Scenario: Build a facet query from active facets
- GIVEN one or more active facets
- WHEN a search runs
- THEN `buildFacetQuery()` MUST encode them (including `@self` facets) into the request

### Requirement: Search UI components (SCH-018)
The system SHALL provide a search frontend comprising a `SearchSideBar` (facet filter
controls), a `SearchResults` component (renders the result list), and a `FacetComponent`
(renders an individual facet filter and toggles it on the store).

**Priority:** Should **Status:** Implemented

#### Scenario: Toggle a facet from the UI
- GIVEN a facet rendered by `FacetComponent`
- WHEN the user enables it
- THEN the store's active facets MUST update and a re-search MUST be triggerable

### Requirement: Internal/admin publication search endpoint (SCH-019)
The system SHALL expose an internal `SearchController` whose `index` action
(`GET /api/search`, `@NoAdminRequired` / `@NoCSRFRequired`) returns a list of publications
across all catalogs (optionally filtered by `catalogId`) by delegating to
`PublicationService::index`. This is documented as an internal endpoint for testing and
administrative purposes.

**Priority:** Should **Status:** Implemented

#### Scenario: List publications via the internal search endpoint
- GIVEN an authenticated request to `GET /api/search`
- WHEN `SearchController::index` runs
- THEN it MUST delegate to `PublicationService::index` and return the JSON publication list

> **Notes (observed orphan — not fixed by this retrofit):**
> `src/store/modules/search.js` exists alongside the live `src/store/modules/search.ts`,
> but `src/store/store.js` imports `./modules/search` which resolves to the `.ts` file.
> The `.js` copy is not referenced anywhere and is dead/orphaned code (the coverage report
> flags it as a "possible duplicate"). These REQs describe the live `.ts` store; the orphan
> `.js` file is intentionally **not** annotated. Removing it is a separate code change.
