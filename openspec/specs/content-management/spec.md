---
status: reviewed
---

# Content Management

## Purpose

OpenCatalogi includes a lightweight CMS layer for managing static content on catalog websites. This includes pages (static content with block-based structure), menus (hierarchical navigation), themes (publication categorization/cards), and glossary terms (definitions). All content types are stored as OpenRegister objects and served via public CORS-enabled API endpoints for consumption by external frontends like tilburg-woo-ui.

## Requirements

<!-- Pages -->

### Requirement: List all pages with pagination via public API
The system MUST list all pages with pagination via public API.

**ID:** CMS-001 — Priority: Must — Status: Implemented

### Requirement: Retrieve a single page by slug
The system MUST retrieve a single page by slug.

**ID:** CMS-002 — Priority: Must — Status: Implemented

### Requirement: Pages support block-based content structure (contents array with type, data, groups)
Pages MUST support block-based content structure (contents array with type, data, groups).

**ID:** CMS-003 — Priority: Must — Status: Implemented

### Requirement: Pages support group-based access control (groups, hideAfterLogin, hideBeforeLogin)
Pages SHOULD support group-based access control (groups, hideAfterLogin, hideBeforeLogin).

**ID:** CMS-004 — Priority: Should — Status: Implemented

### Requirement: Page configuration stored in IAppConfig as `page_schema` and `page_register`
Page configuration MUST be stored in IAppConfig as `page_schema` and `page_register`.

**ID:** CMS-005 — Priority: Must — Status: Implemented

### Requirement: CORS headers included on all page endpoints
CORS headers MUST be included on all page endpoints.

**ID:** CMS-006 — Priority: Must — Status: Implemented

<!-- Menus -->

### Requirement: List all menus with pagination via public API
The system MUST list all menus with pagination via public API.

**ID:** CMS-010 — Priority: Must — Status: Implemented

### Requirement: Retrieve a single menu by ID
The system MUST retrieve a single menu by ID.

**ID:** CMS-011 — Priority: Must — Status: Implemented

### Requirement: Menus support hierarchical items with sub-items
Menus MUST support hierarchical items with sub-items.

**ID:** CMS-012 — Priority: Must — Status: Implemented

### Requirement: Menu items support group-based visibility (groups, hideAfterLogin, hideBeforeLogin)
Menu items SHOULD support group-based visibility (groups, hideAfterLogin, hideBeforeLogin).

**ID:** CMS-013 — Priority: Should — Status: Implemented

### Requirement: Menu configuration stored in IAppConfig as `menu_schema` and `menu_register`
Menu configuration MUST be stored in IAppConfig as `menu_schema` and `menu_register`.

**ID:** CMS-014 — Priority: Must — Status: Implemented

### Requirement: Default fallback: menu schema ID 7, register ID 1 when not configured
The system SHOULD default to menu schema ID 7 and register ID 1 when not configured.

**ID:** CMS-015 — Priority: Should — Status: Implemented

### Requirement: CORS headers included on all menu endpoints
CORS headers MUST be included on all menu endpoints.

**ID:** CMS-016 — Priority: Must — Status: Implemented

<!-- Themes -->

### Requirement: List all themes with pagination and facets via public API
The system MUST list all themes with pagination and facets via public API.

**ID:** CMS-020 — Priority: Must — Status: Implemented

### Requirement: Retrieve a single theme by ID
The system MUST retrieve a single theme by ID.

**ID:** CMS-021 — Priority: Must — Status: Implemented

### Requirement: Theme configuration stored in IAppConfig as `theme_schema` and `theme_register`
Theme configuration MUST be stored in IAppConfig as `theme_schema` and `theme_register`.

**ID:** CMS-022 — Priority: Must — Status: Implemented

### Requirement: Themes include display fields (image, icon, link, url, sort, isExternal)
Themes MUST include display fields (image, icon, link, url, sort, isExternal).

**ID:** CMS-023 — Priority: Must — Status: Implemented

### Requirement: CORS headers included on all theme endpoints
CORS headers MUST be included on all theme endpoints.

**ID:** CMS-024 — Priority: Must — Status: Implemented

<!-- Glossary -->

### Requirement: List all glossary terms with pagination and facets via public API
The system MUST list all glossary terms with pagination and facets via public API.

**ID:** CMS-030 — Priority: Must — Status: Implemented

### Requirement: Retrieve a single glossary term by ID
The system MUST retrieve a single glossary term by ID.

**ID:** CMS-031 — Priority: Must — Status: Implemented

### Requirement: Glossary configuration stored in IAppConfig as `glossary_schema` and `glossary_register`
Glossary configuration MUST be stored in IAppConfig as `glossary_schema` and `glossary_register`.

**ID:** CMS-032 — Priority: Must — Status: Implemented

### Requirement: Glossary queries force `_source: database` (no Solr dependency)
Glossary queries MUST force `_source: database` (no Solr dependency).

**ID:** CMS-033 — Priority: Must — Status: Implemented

### Requirement: Glossary terms do not use publishing workflow (published=false in queries)
Glossary terms MUST NOT use the publishing workflow (published=false in queries).

**ID:** CMS-034 — Priority: Must — Status: Implemented

### Requirement: CORS headers included on all glossary endpoints
CORS headers MUST be included on all glossary endpoints.

**ID:** CMS-035 — Priority: Must — Status: Implemented

## Data Model

### Page Schema

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| title | string | Yes | The title of the page |
| slug | string | Yes | URL-friendly identifier (pattern: `^[a-z0-9-]+$`) |
| contents | array(object) | No | Block-based content: each block has type, id, data, groups, hideAfterLogin, hideBeforeLogin |
| groups | array(string) | No | Nextcloud groups that can access the page |
| hideAfterLogin | boolean | No | Hide page for logged-in users |
| hideBeforeLogin | boolean | No | Hide page for anonymous users |

### Menu Schema

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| title | string | Yes | The name of the menu |
| position | number | No | Sort order for display |
| items | array(object) | No | Menu items: each has order, name, link, description, icon, groups, hideAfterLogin, hideBeforeLogin, and nested items |
| groups | array(string) | No | Nextcloud groups that can access the menu |
| hideBeforeLogin | boolean | No | Hide menu for anonymous users |

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

- **PageIndex.vue** (`/pages`) - Page management list
- **ViewPageModal.vue** - View page content
- **PageContentForm.vue** - Edit page content blocks
- **MenuIndex.vue** (`/menus`) - Menu management
- **ViewMenuModal.vue** - View menu structure
- **MenuItemForm.vue** - Edit menu items
- **ThemeIndex.vue** (`/themes`) - Theme management
- **ThemeModal.vue** / **ViewThemeModal.vue** - Create/view themes
- **GlossaryIndex.vue** (`/glossary`) - Glossary management
- **GlossaryModal.vue** / **ViewGlossaryModal.vue** - Create/view glossary terms

## API Endpoints

### Pages

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/pages` | List all pages (public, paginated) |
| GET | `/api/pages/{slug}` | Get page by slug (slug supports `.+` pattern for nested slugs) |
| OPTIONS | `/api/pages` | CORS preflight |
| OPTIONS | `/api/pages/{slug}` | CORS preflight |

### Menus

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/menus` | List all menus (public, paginated) |
| GET | `/api/menus/{id}` | Get menu by ID |
| OPTIONS | `/api/menus` | CORS preflight |
| OPTIONS | `/api/menus/{id}` | CORS preflight |

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

### Scenario: Get page by slug
- GIVEN a page object with slug "about-us" exists in the page schema/register
- WHEN a GET request is made to `/api/pages/about-us`
- THEN the page is found via searchObjectsPaginated with slug filter and _limit=1
- AND the page data including contents blocks is returned
- AND CORS headers are included

### Scenario: List menus with default fallback
- GIVEN menu_schema and menu_register are not configured in IAppConfig
- WHEN a GET request is made to `/api/menus`
- THEN the controller falls back to schema ID 7 and register ID 1
- AND menus are fetched using searchObjectsPaginated

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
- **PagesController, MenusController, ThemesController, GlossaryController** - Request handling with CORS
