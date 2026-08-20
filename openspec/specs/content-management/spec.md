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

@e2e exclude retrofit spec — public CMS HTTP API contract (pages, menus, themes, glossary) verified by Newman API tests, not browser-UI observable.

OpenCatalogi includes a lightweight CMS layer for managing static content on catalog websites. This includes pages (static content with block-based structure), menus (hierarchical navigation), themes (publication categorization/cards), and glossary terms (definitions). All content types are stored as OpenRegister objects and served via public CORS-enabled API endpoints for consumption by external frontends like tilburg-woo-ui.
## Requirements

<!-- Pages -->

### Requirement: List all pages with pagination via public API (CMS-001)
The system MUST list all pages with pagination via public API.

**Priority:** Must **Status:** Implemented

#### Scenario: List pages publicly
- GIVEN pages exist in the configured page schema/register
- WHEN a GET request is made to `/api/pages`
- THEN all pages MUST be returned with pagination metadata

### Requirement: Retrieve a single page by slug (CMS-002)
The system MUST retrieve a single page by slug.

**Priority:** Must **Status:** Implemented

#### Scenario: Get a page by slug
- GIVEN a page with slug "about-us" exists
- WHEN a GET request is made to `/api/pages/about-us`
- THEN the page MUST be found via a slug filter and its data including content blocks returned

### Requirement: Pages support block-based content structure (contents array with type, data, groups) (CMS-003)
Pages MUST support block-based content structure (contents array with type, data, groups).

**Priority:** Must **Status:** Implemented

#### Scenario: Page stores block-based content
- GIVEN a page with a `contents` array
- WHEN the page is retrieved
- THEN each content block MUST carry its type, data, and groups

### Requirement: Pages support group-based access control (groups, hideAfterLogin, hideBeforeLogin) (CMS-004)
Pages SHALL support group-based access control via `groups`, `hideAfterLogin`, and `hideBeforeLogin`. The system SHOULD honour these visibility fields.

**Priority:** Should **Status:** Implemented

#### Scenario: Page visibility controlled by group fields
- GIVEN a page with `groups`, `hideAfterLogin`, or `hideBeforeLogin` set
- WHEN the page is served
- THEN those access-control fields MUST be present so a frontend can apply group-based visibility

### Requirement: Page configuration stored in IAppConfig as `page_schema` and `page_register` (CMS-005)
Page configuration MUST be stored in IAppConfig as `page_schema` and `page_register`.

**Priority:** Must **Status:** Implemented

#### Scenario: Resolve page schema and register from config
- GIVEN the app reads page configuration
- WHEN it resolves the page schema and register
- THEN it MUST read IAppConfig keys `page_schema` and `page_register`

### Requirement: CORS headers included on all page endpoints (CMS-006)
CORS headers MUST be included on all page endpoints.

**Priority:** Must **Status:** Implemented

#### Scenario: CORS headers on page endpoints
- GIVEN a cross-origin frontend
- WHEN it requests any `/api/pages` endpoint
- THEN the response MUST include CORS headers

<!-- Menus -->

### Requirement: List all menus with pagination via public API (CMS-010)
The system MUST list all menus with pagination via public API.

**Priority:** Must **Status:** Implemented

#### Scenario: List menus publicly
- GIVEN menus exist in the configured menu schema/register
- WHEN a GET request is made to `/api/menus`
- THEN all menus MUST be returned with pagination metadata

### Requirement: Retrieve a single menu by ID (CMS-011)
The system MUST retrieve a single menu by ID.

**Priority:** Must **Status:** Implemented

#### Scenario: Get a menu by ID
- GIVEN a menu with a known ID exists
- WHEN a GET request is made to `/api/menus/{id}`
- THEN that menu MUST be returned

### Requirement: Menus support hierarchical items with sub-items (CMS-012)
Menus MUST support hierarchical items with sub-items.

**Priority:** Must **Status:** Implemented

#### Scenario: Menu items can nest sub-items
- GIVEN a menu whose items contain nested items
- WHEN the menu is retrieved
- THEN the nested sub-items MUST be preserved in the hierarchy

### Requirement: Menu items support group-based visibility (groups, hideAfterLogin, hideBeforeLogin) (CMS-013)
Menu items SHALL support group-based visibility via `groups`, `hideAfterLogin`, and `hideBeforeLogin`. The system SHOULD honour these visibility fields per item.

**Priority:** Should **Status:** Implemented

#### Scenario: Menu item visibility controlled by group fields
- GIVEN a menu item with `groups`, `hideAfterLogin`, or `hideBeforeLogin` set
- WHEN the menu is served
- THEN those fields MUST be present so a frontend can apply per-item group visibility

### Requirement: Menu configuration stored in IAppConfig as `menu_schema` and `menu_register` (CMS-014)
Menu configuration MUST be stored in IAppConfig as `menu_schema` and `menu_register`.

**Priority:** Must **Status:** Implemented

#### Scenario: Resolve menu schema and register from config
- GIVEN the app reads menu configuration
- WHEN it resolves the menu schema and register
- THEN it MUST read IAppConfig keys `menu_schema` and `menu_register`

### Requirement: Default fallback: menu schema ID 7, register ID 1 when not configured (CMS-015)
The system SHALL default to menu schema ID 7 and register ID 1 when menu configuration is absent. It SHOULD apply this fallback transparently.

**Priority:** Should **Status:** Implemented

#### Scenario: Menu defaults applied when unconfigured
- GIVEN `menu_schema` and `menu_register` are not configured in IAppConfig
- WHEN a GET request is made to `/api/menus`
- THEN the controller MUST fall back to schema ID 7 and register ID 1

### Requirement: CORS headers included on all menu endpoints (CMS-016)
CORS headers MUST be included on all menu endpoints.

**Priority:** Must **Status:** Implemented

#### Scenario: CORS headers on menu endpoints
- GIVEN a cross-origin frontend
- WHEN it requests any `/api/menus` endpoint
- THEN the response MUST include CORS headers

<!-- Themes -->

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

### Requirement: Page management UI with embedded content blocks (CMS-036)
The system SHALL provide a page management frontend comprising a `ViewPageModal` (read a
page and its content blocks) and a `PageContentForm` modal for adding/editing a content
block. Content blocks are stored as nested data on the parent `page` object: saving a
block persists the whole page via `objectStore.updateObject('page', id, page)`, and
`DeletePageContentDialog` removes a block by updating the page object with the block
removed. Modals/dialogs are toggled through the navigation store (`page` modal,
`deletePageContent` dialog).

The FAQ item list and content-block item list in `PageContentForm` MUST provide a
keyboard-and-screen-reader-operable way to reorder items ("Move up"/"Move down" buttons),
in addition to the existing pointer-only `vue-draggable-plus` drag handle. Reordering MUST
NOT depend solely on a drag gesture (WCAG 2.1 AA, Success Criterion 2.1.1 — landed via
`keyboard-operable-reorder-controls`).

**Priority:** Should **Status:** Implemented

#### Scenario: Add or edit a page content block
- GIVEN the page content form is open for a page
- WHEN the user saves the content block
- THEN the parent page MUST be persisted via `objectStore.updateObject('page', id, page)`

#### Scenario: Delete a page content block
- GIVEN a content block on a page
- WHEN the delete-page-content dialog confirms removal
- THEN the page MUST be updated with the block removed via `updateObject('page', ...)`

#### Scenario: Keyboard user reorders a FAQ item or content block
- GIVEN a page with 2 or more FAQ items or content blocks in the content form
- WHEN a keyboard-only user tabs to an item's "Move up" or "Move down" button and activates
  it with Enter or Space
- THEN the item MUST swap position with its neighbor
- AND the "Move up" button on the first item, and the "Move down" button on the last item,
  MUST be disabled

#### Scenario: Drag handle is keyboard-discoverable
- GIVEN a keyboard user tabs through a FAQ or content-block row
- WHEN focus reaches the drag handle
- THEN the handle MUST receive visible focus and expose an accessible name that identifies
  it as a reorder control and directs the user to the move buttons as the keyboard-operable
  alternative

### Requirement: Menu management UI with embedded menu items (CMS-037)
The system SHALL provide a menu management frontend comprising a `ViewMenuModal` (read a
menu and its items), a `MenuItemForm` modal for adding/editing items, a
`DeleteMenuItemModal`, and a `CopyMenuDialog`. Menu items are stored as nested data on the
parent `menu` object: saving or deleting an item persists the whole menu via
`objectStore.updateObject('menu', id, menu)`. Copy-menu clones the active menu with a
`(kopie)` title via `objectStore.createObject('menu', clone)`. Modals/dialogs are toggled
through the navigation store.

**Priority:** Should **Status:** Implemented

#### Scenario: Add or edit a menu item
- GIVEN the menu item form is open for a menu
- WHEN the user saves the item
- THEN the parent menu MUST be persisted via `objectStore.updateObject('menu', id, menu)`

#### Scenario: Copy a menu
- GIVEN an active menu
- WHEN the copy-menu dialog is confirmed
- THEN a new menu MUST be created via `objectStore.createObject('menu', clone)` with a `(kopie)` title

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
