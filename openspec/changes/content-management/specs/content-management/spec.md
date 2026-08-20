---
status: reviewed
---

# Content Management

## Purpose

OpenCatalogi includes a lightweight CMS layer for managing static content on catalog websites. This includes pages (static content with block-based structure), menus (hierarchical navigation), themes (publication categorization/cards), and glossary terms (definitions). All content types are stored as OpenRegister objects and served via public CORS-enabled API endpoints for consumption by external frontends like tilburg-woo-ui.

## Requirements

### REQ-CMS-001: Pages public list endpoint

The system MUST expose a public, paginated list of pages.

#### Scenario: List pages with pagination

- GIVEN pages exist in the configured page schema and register
- WHEN a GET request is made to `/api/pages`
- THEN the response MUST include a `results` array with page objects
- AND pagination metadata MUST be present: `total`, `page`, `pages`, `limit`, `offset`
- AND the response MUST include CORS headers
- AND no authentication MUST be required

---

### REQ-CMS-002: Pages single-item endpoint

The system MUST expose retrieval of a single page by its slug.

#### Scenario: Get page by slug

- GIVEN a page object with slug `about-us` exists in the page schema/register
- WHEN a GET request is made to `/api/pages/about-us`
- THEN the page is found via `searchObjectsPaginated` with slug filter and `_limit=1`
- AND the page data including `contents` blocks is returned
- AND CORS headers are included in the response

#### Scenario: Get page with nested slug

- GIVEN a page with slug `about/team` exists
- WHEN a GET request is made to `/api/pages/about/team`
- THEN the route pattern `.+` captures the full nested slug
- AND the correct page object is returned

#### Scenario: Get page that does not exist

- GIVEN no page with slug `does-not-exist` is present
- WHEN a GET request is made to `/api/pages/does-not-exist`
- THEN the response MUST return HTTP 404
- AND a `message` field MUST be present in the response body

---

### REQ-CMS-003: Pages block-based content structure

Pages MUST support a block-based content structure.

#### Scenario: Page with multiple content blocks

- GIVEN a page with two content blocks of different types
- WHEN the page is retrieved via `/api/pages/{slug}`
- THEN the `contents` array MUST contain objects each with `type`, `id`, and `data` fields
- AND optional `groups`, `hideAfterLogin`, and `hideBeforeLogin` fields MUST be present on each block

---

### REQ-CMS-004: Pages group-based access control

Pages and their content blocks MUST support group-based access control flags.

#### Scenario: Page with login-visibility flags

- GIVEN a page with `hideBeforeLogin: true`
- WHEN the page object is returned via the API
- THEN the `hideBeforeLogin` field MUST be `true`
- AND consuming frontends MUST use this field to suppress display for anonymous users
- AND the API endpoint itself does NOT enforce the filter — filtering is frontend responsibility

---

### REQ-CMS-005: Pages IAppConfig configuration

Page endpoint configuration MUST be stored in IAppConfig.

#### Scenario: Pages resolved from IAppConfig

- GIVEN `page_schema` and `page_register` are set in IAppConfig
- WHEN the pages controller handles a request
- THEN `searchObjectsPaginated` MUST be called with the configured schema and register values

---

### REQ-CMS-006: Pages CORS preflight

All page endpoints MUST include CORS preflight support.

#### Scenario: OPTIONS preflight for pages list

- GIVEN an external frontend sends a preflight request
- WHEN an OPTIONS request is made to `/api/pages`
- THEN the response MUST return HTTP 200
- AND the response MUST include `Access-Control-Allow-Origin` and related CORS headers

---

### REQ-CMS-010: Menus public list endpoint

The system MUST expose a public, paginated list of menus.

#### Scenario: List menus

- GIVEN menus exist in the configured menu schema and register
- WHEN a GET request is made to `/api/menus`
- THEN the response MUST include a `results` array with menu objects
- AND pagination metadata MUST be present
- AND CORS headers MUST be included

---

### REQ-CMS-011: Menus single-item endpoint

The system MUST expose retrieval of a single menu by its ID.

#### Scenario: Get menu by ID

- GIVEN a menu with a known ID exists
- WHEN a GET request is made to `/api/menus/{id}`
- THEN the full menu object including its `items` tree is returned
- AND CORS headers are included

---

### REQ-CMS-012: Menus hierarchical items

Menu objects MUST support hierarchical nested items.

#### Scenario: Menu with nested sub-items

- GIVEN a menu with items that have nested `items` arrays
- WHEN the menu is retrieved via `/api/menus/{id}`
- THEN the `items` array MUST contain objects each with `order`, `name`, `link`, `description`, `icon`, `groups`, `hideAfterLogin`, `hideBeforeLogin`, and `items` fields
- AND nested sub-items MUST be included in the same response

---

### REQ-CMS-013: Menu items group-based visibility

Menu items MUST support group-based visibility flags.

#### Scenario: Menu item with login-visibility flags

- GIVEN a menu item with `hideBeforeLogin: true`
- WHEN the menu is returned via the API
- THEN the `hideBeforeLogin` field MUST be `true` on that item
- AND consuming frontends MUST use this field to suppress display for anonymous users

---

### REQ-CMS-014: Menus IAppConfig configuration

Menu endpoint configuration MUST be stored in IAppConfig.

#### Scenario: Menus resolved from IAppConfig

- GIVEN `menu_schema` and `menu_register` are set in IAppConfig
- WHEN the menus controller handles a request
- THEN `searchObjectsPaginated` MUST be called with the configured schema and register values

---

### REQ-CMS-015: Menus default fallback

When IAppConfig keys are absent the menus controller MUST fall back to defaults.

#### Scenario: List menus with default fallback

- GIVEN `menu_schema` and `menu_register` are NOT configured in IAppConfig
- WHEN a GET request is made to `/api/menus`
- THEN the controller MUST fall back to schema ID `7` and register ID `1`
- AND menus MUST still be fetched using `searchObjectsPaginated`

---

### REQ-CMS-016: Menus CORS preflight

All menu endpoints MUST include CORS preflight support.

#### Scenario: OPTIONS preflight for menus list

- GIVEN an external frontend sends a preflight request
- WHEN an OPTIONS request is made to `/api/menus`
- THEN the response MUST return HTTP 200 with CORS headers

---

### REQ-CMS-020: Themes public list endpoint with facets

The system MUST expose a public, paginated list of themes including facets.

#### Scenario: List themes with facets

- GIVEN themes exist in the configured theme schema/register
- WHEN a GET request is made to `/api/themes`
- THEN themes MUST be returned with pagination metadata: `results`, `total`, `limit`, `offset`, `page`, `pages`
- AND facets MUST be included if present in the search results
- AND if the search result wraps facets in a `facets` key they MUST be unwrapped to the top-level response

---

### REQ-CMS-021: Themes single-item endpoint

The system MUST expose retrieval of a single theme by its ID.

#### Scenario: Get theme by ID

- GIVEN a theme with a known ID exists
- WHEN a GET request is made to `/api/themes/{id}`
- THEN the full theme object is returned
- AND CORS headers are included

---

### REQ-CMS-022: Themes IAppConfig configuration

Theme endpoint configuration MUST be stored in IAppConfig.

#### Scenario: Themes resolved from IAppConfig

- GIVEN `theme_schema` and `theme_register` are set in IAppConfig
- WHEN the themes controller handles a request
- THEN `searchObjectsPaginated` MUST be called with the configured values

---

### REQ-CMS-023: Themes display fields

Theme objects MUST carry display fields for frontend card rendering.

#### Scenario: Theme with full display metadata

- GIVEN a theme with all display fields populated
- WHEN the theme is returned via the API
- THEN the response MUST include `image`, `icon`, `link`, `url`, `sort`, and `isExternal` fields

---

### REQ-CMS-024: Themes CORS preflight

All theme endpoints MUST include CORS preflight support.

#### Scenario: OPTIONS preflight for themes list

- GIVEN an external frontend sends a preflight request
- WHEN an OPTIONS request is made to `/api/themes`
- THEN the response MUST return HTTP 200 with CORS headers

---

### REQ-CMS-030: Glossary public list endpoint

The system MUST expose a public, paginated list of glossary terms.

#### Scenario: List glossary with database source

- GIVEN glossary terms exist in the configured glossary schema/register
- WHEN a GET request is made to `/api/glossary`
- THEN the query MUST include `_source: database` to bypass Solr
- AND `published=false` MUST be passed because glossary does not use the publishing workflow
- AND results MUST include pagination metadata and optional facets

---

### REQ-CMS-031: Glossary single-item endpoint

The system MUST expose retrieval of a single glossary term by its ID.

#### Scenario: Get glossary term by ID

- GIVEN a glossary term with a known ID exists
- WHEN a GET request is made to `/api/glossary/{id}`
- THEN the full term object is returned including `title`, `summary`, `description`, `externalLink`, and `keywords`
- AND CORS headers are included

---

### REQ-CMS-032: Glossary IAppConfig configuration

Glossary endpoint configuration MUST be stored in IAppConfig.

#### Scenario: Glossary resolved from IAppConfig

- GIVEN `glossary_schema` and `glossary_register` are set in IAppConfig
- WHEN the glossary controller handles a request
- THEN `searchObjectsPaginated` MUST be called with the configured values

---

### REQ-CMS-033: Glossary database source enforcement

Glossary queries MUST always use the database source to avoid Solr dependency.

#### Scenario: Glossary bypasses Solr

- GIVEN a Solr index is configured for the OpenRegister instance
- WHEN a GET request is made to `/api/glossary`
- THEN the query parameter `_source: database` MUST be present regardless of Solr availability
- AND no Solr query MUST be issued for glossary terms

---

### REQ-CMS-034: Glossary publishing workflow bypass

Glossary terms MUST not participate in the publishing workflow.

#### Scenario: Glossary query skips published filter

- GIVEN glossary terms that have not been through a publish workflow step
- WHEN the glossary index endpoint is queried
- THEN `published=false` MUST be passed in the search query
- AND all terms in the register MUST be returned regardless of publish state

---

### REQ-CMS-035: Glossary CORS preflight

All glossary endpoints MUST include CORS preflight support.

#### Scenario: OPTIONS preflight for glossary list

- GIVEN an external frontend sends a preflight request
- WHEN an OPTIONS request is made to `/api/glossary`
- THEN the response MUST return HTTP 200 with CORS headers

---

## Data Model

### Page Schema

| Field | Type | Required | Description |
|---|---|---|---|
| title | string | Yes | The title of the page |
| slug | string | Yes | URL-friendly identifier (pattern: `^[a-z0-9-]+$`) |
| contents | array(object) | No | Block-based content: each block has `type`, `id`, `data`, `groups`, `hideAfterLogin`, `hideBeforeLogin` |
| groups | array(string) | No | Nextcloud groups that can access the page |
| hideAfterLogin | boolean | No | Hide page for logged-in users |
| hideBeforeLogin | boolean | No | Hide page for anonymous users |

### Menu Schema

| Field | Type | Required | Description |
|---|---|---|---|
| title | string | Yes | The name of the menu |
| position | number | No | Sort order for display |
| items | array(object) | No | Menu items: each has `order`, `name`, `link`, `description`, `icon`, `groups`, `hideAfterLogin`, `hideBeforeLogin`, and nested `items` |
| groups | array(string) | No | Nextcloud groups that can access the menu |
| hideBeforeLogin | boolean | No | Hide menu for anonymous users |

### Theme Schema

| Field | Type | Required | Description |
|---|---|---|---|
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
|---|---|---|---|
| title | string | Yes | The term being defined |
| summary | string | No | Brief definition (max 255 chars) |
| description | string | No | Detailed explanation (max 2555 chars) |
| externalLink | string | No | URL to external reference |
| keywords | array(string) | No | Related search terms and synonyms |

## API Endpoints

### Pages

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/pages` | List all pages (public, paginated) |
| GET | `/api/pages/{slug}` | Get page by slug (`.+` pattern supports nested slugs) |
| OPTIONS | `/api/pages` | CORS preflight |
| OPTIONS | `/api/pages/{slug}` | CORS preflight |

### Menus

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/menus` | List all menus (public, paginated) |
| GET | `/api/menus/{id}` | Get menu by ID |
| OPTIONS | `/api/menus` | CORS preflight |
| OPTIONS | `/api/menus/{id}` | CORS preflight |

### Themes

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/themes` | List all themes (public, paginated, with facets) |
| GET | `/api/themes/{id}` | Get theme by ID |
| OPTIONS | `/api/themes` | CORS preflight |
| OPTIONS | `/api/themes/{id}` | CORS preflight |

### Glossary

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/glossary` | List all glossary terms (public, paginated, with facets) |
| GET | `/api/glossary/{id}` | Get glossary term by ID |
| OPTIONS | `/api/glossary` | CORS preflight |
| OPTIONS | `/api/glossary/{id}` | CORS preflight |

## Dependencies

- **OpenRegister ObjectService** — `searchObjectsPaginated` for all content queries
- **Nextcloud IAppConfig** — Schema/register configuration for each content type
- **PagesController, MenusController, ThemesController, GlossaryController** — Request handling with CORS

## Non-Requirements

- Write (POST/PUT/DELETE) operations on any CMS content type via public API — admin UI only
- Full-text search indexing of CMS content via Solr (glossary explicitly bypasses it)
- Multi-language content variants or i18n content management
- Version history or publish/unpublish workflow for pages, menus, or themes
