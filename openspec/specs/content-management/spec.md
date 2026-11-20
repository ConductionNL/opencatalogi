---
status: done
retrofit_extensions:
  - CMS-036
  - CMS-037
  - CMS-038
  - CMS-039
  - CMS-040
---

# Content Management

## Purpose

@e2e exclude retrofit spec — public CMS HTTP API contract (themes, glossary) verified by Newman API tests, not browser-UI observable.

OpenCatalogi includes a lightweight CMS layer for managing static content on catalog websites. This includes themes (publication categorization/cards) and glossary terms (definitions). Pages and menus moved to Portaliq (cms-moves-to-portaliq, decision 138); `occ opencatalogi:cms:migrate-to-portaliq` moves existing ones. All content types are stored as OpenRegister objects and served via public CORS-enabled API endpoints for consumption by external frontends like tilburg-woo-ui.

## Requirements

### Requirement: List all themes with pagination and facets via public API (CMS-020)
The system MUST list all themes with pagination and facets via public API.

**Priority:** Must **Status:** Implemented

#### Scenario: List themes with facets
- GIVEN themes exist in the configured theme schema/register
- WHEN a GET request is made to `/api/themes`
- THEN themes MUST be returned with pagination metadata and facets when present

### Requirement: Retrieve a single theme by ID (CMS-021)
The system MUST retrieve a single theme by ID.

**Priority:** Must **Status:** Implemented

#### Scenario: Get a theme by ID
- GIVEN a theme with a known ID exists
- WHEN a GET request is made to `/api/themes/{id}`
- THEN that theme MUST be returned

### Requirement: Theme configuration stored in IAppConfig as `theme_schema` and `theme_register` (CMS-022)
Theme configuration MUST be stored in IAppConfig as `theme_schema` and `theme_register`.

**Priority:** Must **Status:** Implemented

#### Scenario: Resolve theme schema and register from config
- GIVEN the app reads theme configuration
- WHEN it resolves the theme schema and register
- THEN it MUST read IAppConfig keys `theme_schema` and `theme_register`

### Requirement: Themes include display fields (image, icon, link, url, sort, isExternal) (CMS-023)
Themes MUST include display fields (image, icon, link, url, sort, isExternal).

**Priority:** Must **Status:** Implemented

#### Scenario: Theme exposes display fields
- GIVEN a theme object
- WHEN it is retrieved
- THEN it MUST expose its display fields image, icon, link, url, sort, and isExternal

### Requirement: CORS headers included on all theme endpoints (CMS-024)
CORS headers MUST be included on all theme endpoints.

**Priority:** Must **Status:** Implemented

#### Scenario: CORS headers on theme endpoints
- GIVEN a cross-origin frontend
- WHEN it requests any `/api/themes` endpoint
- THEN the response MUST include CORS headers

<!-- Glossary -->

### Requirement: List all glossary terms with pagination and facets via public API (CMS-030)
The system MUST list all glossary terms with pagination and facets via public API.

**Priority:** Must **Status:** Implemented

#### Scenario: List glossary terms with facets
- GIVEN glossary terms exist
- WHEN a GET request is made to `/api/glossary`
- THEN terms MUST be returned with pagination metadata and optional facets

### Requirement: Retrieve a single glossary term by ID (CMS-031)
The system MUST retrieve a single glossary term by ID.

**Priority:** Must **Status:** Implemented

#### Scenario: Get a glossary term by ID
- GIVEN a glossary term with a known ID exists
- WHEN a GET request is made to `/api/glossary/{id}`
- THEN that term MUST be returned

### Requirement: Glossary configuration stored in IAppConfig as `glossary_schema` and `glossary_register` (CMS-032)
Glossary configuration MUST be stored in IAppConfig as `glossary_schema` and `glossary_register`.

**Priority:** Must **Status:** Implemented

#### Scenario: Resolve glossary schema and register from config
- GIVEN the app reads glossary configuration
- WHEN it resolves the glossary schema and register
- THEN it MUST read IAppConfig keys `glossary_schema` and `glossary_register`

### Requirement: Glossary queries force `_source: database` (no Solr dependency) (CMS-033)
Glossary queries MUST force `_source: database` (no Solr dependency).

**Priority:** Must **Status:** Implemented

#### Scenario: Glossary query bypasses Solr
- GIVEN a glossary list query
- WHEN it is built
- THEN it MUST include `_source: database` so it does not depend on Solr

### Requirement: Glossary terms do not use publishing workflow (published=false in queries) (CMS-034)
Glossary terms MUST NOT use the publishing workflow (published=false in queries).

**Priority:** Must **Status:** Implemented

#### Scenario: Glossary query does not require publication
- GIVEN a glossary list query
- WHEN it is built
- THEN it MUST pass published=false so glossary terms bypass the publishing workflow

### Requirement: CORS headers included on all glossary endpoints (CMS-035)
CORS headers MUST be included on all glossary endpoints.

**Priority:** Must **Status:** Implemented

#### Scenario: CORS headers on glossary endpoints
- GIVEN a cross-origin frontend
- WHEN it requests any `/api/glossary` endpoint
- THEN the response MUST include CORS headers

### Requirement: Theme management UI (CMS-038)
The system SHALL provide a theme management frontend comprising a `ViewThemeModal` (read a
theme), an `AddPublicationThemeModal` that attaches a theme to a publication by updating
the publication via `objectStore.updateObject('publication', id, updatedPublication)`, and
a `DeleteMultipleThemesDialog` that bulk-deletes selected themes via repeated
`objectStore.deleteObject('theme', id)`. Modals/dialogs are toggled through the navigation
store.

**Priority:** Should **Status:** Implemented

#### Scenario: Attach a theme to a publication
- GIVEN the add-publication-theme modal is open
- WHEN the user confirms the theme selection
- THEN the publication MUST be updated via `objectStore.updateObject('publication', id, updatedPublication)`

#### Scenario: Bulk-delete themes
- GIVEN multiple themes are selected
- WHEN the delete-multiple-themes dialog is confirmed
- THEN each selected theme MUST be removed via `objectStore.deleteObject('theme', id)`

### Requirement: Glossary view UI (CMS-039)
The system SHALL provide a `ViewGlossaryModal` that reads and displays a glossary term
from the object store, toggled through the navigation store.

**Priority:** Should **Status:** Implemented

#### Scenario: View a glossary term
- GIVEN a glossary term is the active object
- WHEN the navigation store modal is set to the glossary modal
- THEN the term's details MUST be rendered read-only

### Requirement: Content-management presentation helpers (CMS-040)
The system SHALL provide frontend helper services for content presentation: `getTheme()`
returns `'light'` or `'dark'` by reading the document body's `data-theme-light` /
`data-theme-default` attributes (honouring `prefers-color-scheme` for the default theme,
defaulting to `'dark'`), and `getPublicationTypeId(url)` extracts the trailing path segment
of a publication-type URL as its id.

**Priority:** Should **Status:** Implemented

#### Scenario: Resolve the active Nextcloud theme
@e2e exclude pure JS helper — getTheme() reads a DOM attribute and returns a string; no browser-rendered UI surface; covered by Jest unit test.
- GIVEN the body carries `data-theme-light`
- WHEN `getTheme()` is called
- THEN it MUST return `'light'`

#### Scenario: Default theme follows the OS color scheme
@e2e exclude pure JS helper — getTheme() with data-theme-default uses matchMedia which cannot be reliably driven in Playwright headless; covered by Jest unit test.
- GIVEN the body carries `data-theme-default`
- WHEN `getTheme()` is called
- THEN it MUST return `'light'` if `prefers-color-scheme: light` matches, otherwise `'dark'`

#### Scenario: Extract a publication type id from a URL
@e2e exclude pure JS utility — getPublicationTypeId() extracts a string fragment from a URL with no browser-rendered UI surface; covered by Jest unit test.
- GIVEN a publication-type URL ending in `/42`
- WHEN `getPublicationTypeId(url)` is called
- THEN it MUST return `42`

## Data Model

### Theme Schema

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| title | string | Yes | The name of the theme |
| summary | string | Yes | Brief description of the theme |
| description | string | No | Detailed description |
| image | string | No | URL to the theme's image |
| content | string | No | HTML content for the theme card |
| link | string | No | Button/link text |
| url | string | No | Destination URL for the link |
| icon | string | No | Icon identifier |
| isExternal | boolean | No | Whether link opens in new tab |
| sort | integer | No | Sort order for display |

### Glossary Schema

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| title | string | Yes | The term being defined |
| summary | string | No | Brief definition (max 255 chars) |
| description | string | No | Detailed explanation (max 2555 chars) |
| externalLink | string | No | URL to external reference |
| keywords | array(string) | No | Related search terms and synonyms |

## User Interface

- **ThemeIndex.vue** (`/themes`) - Theme management
- **ThemeModal.vue** / **ViewThemeModal.vue** - Create/view themes
- **GlossaryIndex.vue** (`/glossary`) - Glossary management
- **GlossaryModal.vue** / **ViewGlossaryModal.vue** - Create/view glossary terms

## API Endpoints

### Themes

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/themes` | List all themes (public, paginated, with facets) |
| GET | `/api/themes/{id}` | Get theme by ID |
| OPTIONS | `/api/themes` | CORS preflight |
| OPTIONS | `/api/themes/{id}` | CORS preflight |

### Glossary

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/glossary` | List all glossary terms (public, paginated, with facets) |
| GET | `/api/glossary/{id}` | Get glossary term by ID |
| OPTIONS | `/api/glossary` | CORS preflight |
| OPTIONS | `/api/glossary/{id}` | CORS preflight |

## Scenarios

### Scenario: List themes with facets
- GIVEN themes exist in the configured theme schema/register
- WHEN a GET request is made to `/api/themes`
- THEN themes are returned with pagination metadata (results, total, limit, offset, page, pages)
- AND facets are included if present in the search results
- AND nested facets are unwrapped if wrapped in a `facets` key

### Scenario: List glossary with database source
- GIVEN glossary terms exist
- WHEN a GET request is made to `/api/glossary`
- THEN the query includes `_source: database` to bypass Solr
- AND published=false is passed (glossary does not use publishing workflow)
- AND results include pagination and optional facets

## Dependencies

- **OpenRegister ObjectService** - searchObjectsPaginated for all content queries
- **Nextcloud IAppConfig** - Schema/register configuration for each content type
- **ThemesController, GlossaryController** - Request handling with CORS
