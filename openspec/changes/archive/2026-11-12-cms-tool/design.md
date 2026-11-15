# Design: cms-tool

## Architecture Overview

The CMS Tool introduces a thin integration layer between OpenRegister's agent runtime and OpenCatalogi's existing content schemas. It contains no new data model — it writes to the `page` and `menu` schemas already defined in `lib/Settings/publication_register.json`.

```
OpenRegister Agent Runtime
  │
  ├── dispatches ToolRegistrationEvent
  │     └── ToolRegistrationListener.handle()
  │           └── event.registerTool('opencatalogi.cms', CMSTool, metadata)
  │
  └── calls CMSTool.executeFunction(name, params)
        └── match name → createPage | listPages | createMenu | listMenus | addMenuItem
              └── ObjectService.saveObject() / findAll()
                    └── publication register → page / menu schema
```

### Registration Flow

```
Application.php register()
  └── IEventDispatcher.addServiceListener(ToolRegistrationEvent::class, ToolRegistrationListener::class)

OpenRegister dispatches ToolRegistrationEvent
  └── ToolRegistrationListener.handle(event)
        └── event.registerTool(
              id: 'opencatalogi.cms',
              tool: CMSTool,
              metadata: { name, description, icon, app }
            )
```

### Component Map

| Component | Location | Responsibility |
|-----------|----------|----------------|
| `CMSTool` | `lib/Tool/CMSTool.php` | Implements `ToolInterface`; defines OpenAI-compatible function schemas; executes CMS operations |
| `ToolRegistrationListener` | `lib/Listener/ToolRegistrationListener.php` | Registers `CMSTool` with id `opencatalogi.cms` when `ToolRegistrationEvent` fires |
| `Application` | `lib/AppInfo/Application.php` | Binds `ToolRegistrationListener` to `ToolRegistrationEvent` in the DI container |

### Tool Registration Metadata

| Field | Value |
|-------|-------|
| ID | `opencatalogi.cms` |
| Name | `CMS Tool` |
| Description | `Manage website content: create and manage pages, menus, and menu items for OpenCatalogi` |
| Icon | `icon-category-office` |
| App | `opencatalogi` |

## API Design — Function Definitions

All functions follow the OpenAI function-calling schema. They are returned by `CMSTool.getFunctions()` and dispatched by `CMSTool.executeFunction()`.

### `cms_create_page`

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `title` | string | Yes | Page title; auto-generates `slug` if `slug` is omitted |
| `summary` | string | No | Brief summary shown in listings |
| `description` | string | No | Full HTML or markdown content |
| `slug` | string | No | URL-friendly identifier (`^[a-z0-9-]+$`) |

**Returns:** `{ success: true, data: { pageId, title, slug } }`

### `cms_list_pages`

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `limit` | integer | No | Max pages to return (default: 50) |

**Returns:** `{ success: true, data: { count, pages: [{ id, title, slug, summary }] } }`

### `cms_create_menu`

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `title` | string | Yes | Menu label |
| `position` | number | No | Display order; 0 = first |
| `items` | array | Yes | Menu items; each must have `order`, `name`, `link` |
| `groups` | array | No | Nextcloud groups with access |
| `hideBeforeLogin` | boolean | No | Hide for anonymous users |

**Returns:** `{ success: true, data: { menuId, title, position, itemCount } }`

### `cms_list_menus`

No parameters.

**Returns:** `{ success: true, data: { count, menus: [{ id, title, position, itemCount }] } }`

### `cms_add_menu_item`

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `menuId` | string | Yes | UUID of target menu |
| `name` | string | Yes | Display label |
| `link` | string | No | External URL (required if `pageId` absent) |
| `pageId` | string | No | Internal page UUID (required if `link` absent) |
| `order` | integer | No | Display position in menu |

**Returns:** `{ success: true, data: { menuItemId, name, menuId } }`

## Database Changes

None. The tool writes to the existing `page` and `menu` schemas in the `publication` register (`lib/Settings/publication_register.json`). No schema migrations are needed.

## Key Design Decisions

### Decision 1: Implement `ToolInterface` — not a custom API endpoint

**Choice:** The CMS Tool is a `ToolInterface` plugin, not a new REST controller.

**Rationale:** OpenRegister's agent runtime discovers tools via `ToolRegistrationEvent`. Implementing `ToolInterface` means zero new HTTP routes, zero CSRF/CORS annotations, and automatic integration with every agent the user configures. A dedicated REST endpoint would require agents to call OpenCatalogi directly, breaking agent portability across apps.

### Decision 2: Use `ObjectService.saveObject()` for all writes

**Choice:** Delegate all persistence to `ObjectService` with `register='publication'` and `schema='page'/'menu'/'menuItem'`.

**Rationale:** ObjectService provides RBAC enforcement, audit trail generation, and schema validation for free. Writing to PostgreSQL via custom queries would bypass all of these. Consistent with ADR-001: all domain data goes through OpenRegister's ObjectService.

### Decision 3: `__call` magic method for LLPhant snake_case dispatch

**Choice:** Strip the `cms_` prefix, convert snake_case to camelCase, and JSON-encode array results.

**Rationale:** LLPhant (the PHP LLM framework used by OpenRegister) calls tools by their snake_case function name (e.g., `cms_create_page`). PHP methods follow PSR-1 camelCase convention. The `__call` bridge strips the prefix, converts case, type-casts arguments via reflection, and returns JSON because LLPhant expects string tool results.

### Decision 4: Organisation boundary from injected `Agent`

**Choice:** Set `organisation` from `$this->agent->getOrganisation()` on every saved object.

**Rationale:** Multi-tenancy requires that agent-created content is scoped to the agent's organisation. Agents run under a specific user/organisation context set via `setAgent()`. Using the session user as the ownership fallback ensures the content is auditable to a real Nextcloud user.

### Decision 5: Menu item validation before persist

**Choice:** Validate each item in `items[]` for `order`, `name`, and `link` before calling `saveObject`.

**Rationale:** ObjectService schema validation runs server-side, but error messages from deep inside the ORM are not LLM-friendly. Early validation returns a structured `{ success: false, error: "..." }` that the LLM can present back to the user.

## Reuse Analysis

Per ADR-001 requirements, the following OpenRegister services were checked for overlap before writing custom logic:

| Capability | OpenRegister existing | Decision |
|------------|----------------------|----------|
| Object persistence | `ObjectService.saveObject()` | **Reused** — all writes delegate here |
| Object listing | `ObjectService.findAll()` | **Reused** — all reads delegate here |
| RBAC enforcement | `PropertyRbacHandler` (inside ObjectService) | **Reused** — automatic |
| Slug generation | No dedicated `SlugFormat` in OpenRegister | **Custom** — `generateSlug()` (12 lines): lowercase + regex replace |
| Type casting from LLM | No LLM argument handler in OpenRegister | **Custom** — reflection-based casting in `__call` |

No logic was duplicated. The slug generator and argument caster are CMSTool-specific; no OpenRegister equivalent exists.

## Seed Data

The CMS Tool operates on the existing `page` and `menu` schemas. The seed objects below are defined inline here for documentation; they should be present in `lib/Settings/publication_register.json` under `components.objects[]` using the `@self` envelope.

### Page seed objects

```json
[
  {
    "@self": { "register": "publication", "schema": "page", "slug": "seed-over-ons" },
    "title": "Over ons",
    "slug": "over-ons",
    "summary": "Informatie over de gemeentelijke softwarecatalogus en haar beheerders.",
    "contents": [
      { "type": "text", "id": "blok-1", "data": { "body": "<p>Welkom op de softwarecatalogus van onze gemeente. Hier vindt u een overzicht van alle applicaties en diensten die in gebruik zijn.</p>" } }
    ]
  },
  {
    "@self": { "register": "publication", "schema": "page", "slug": "seed-contact" },
    "title": "Contact",
    "slug": "contact",
    "summary": "Neem contact op met de beheerders van de softwarecatalogus.",
    "contents": [
      { "type": "text", "id": "blok-1", "data": { "body": "<p>Voor vragen over de catalogus kunt u mailen naar <a href=\"mailto:ict@gemeente.nl\">ict@gemeente.nl</a>.</p>" } }
    ]
  },
  {
    "@self": { "register": "publication", "schema": "page", "slug": "seed-privacyverklaring" },
    "title": "Privacyverklaring",
    "slug": "privacyverklaring",
    "summary": "Hoe wij omgaan met uw persoonsgegevens.",
    "contents": [
      { "type": "text", "id": "blok-1", "data": { "body": "<p>Wij verwerken persoonsgegevens conform de Algemene Verordening Gegevensbescherming (AVG).</p>" } }
    ],
    "hideBeforeLogin": false
  },
  {
    "@self": { "register": "publication", "schema": "page", "slug": "seed-handleiding" },
    "title": "Handleiding",
    "slug": "handleiding",
    "summary": "Zo gebruikt u de softwarecatalogus.",
    "contents": [
      { "type": "text", "id": "blok-1", "data": { "body": "<p>Deze handleiding legt uit hoe u publicaties zoekt, filtert en beoordeelt.</p>" } }
    ]
  },
  {
    "@self": { "register": "publication", "schema": "page", "slug": "seed-toegankelijkheid" },
    "title": "Toegankelijkheidsverklaring",
    "slug": "toegankelijkheidsverklaring",
    "summary": "Verklaring over de toegankelijkheid van deze website.",
    "contents": [
      { "type": "text", "id": "blok-1", "data": { "body": "<p>Deze website voldoet aan de eisen van WCAG 2.1 niveau AA.</p>" } }
    ]
  }
]
```

### Menu seed objects

```json
[
  {
    "@self": { "register": "publication", "schema": "menu", "slug": "seed-hoofdmenu" },
    "title": "Hoofdmenu",
    "position": 0,
    "items": [
      { "order": 0, "name": "Home", "link": "/" },
      { "order": 1, "name": "Catalogi", "link": "/catalogi" },
      { "order": 2, "name": "Publicaties", "link": "/publicaties" },
      { "order": 3, "name": "Over ons", "link": "/over-ons" },
      { "order": 4, "name": "Contact", "link": "/contact" }
    ]
  },
  {
    "@self": { "register": "publication", "schema": "menu", "slug": "seed-footermenu" },
    "title": "Footermenu",
    "position": 1,
    "items": [
      { "order": 0, "name": "Privacyverklaring", "link": "/privacyverklaring" },
      { "order": 1, "name": "Toegankelijkheidsverklaring", "link": "/toegankelijkheidsverklaring" },
      { "order": 2, "name": "Handleiding", "link": "/handleiding" }
    ]
  },
  {
    "@self": { "register": "publication", "schema": "menu", "slug": "seed-beheerdersmenu" },
    "title": "Beheerdersmenu",
    "position": 2,
    "groups": ["admin", "beheerders"],
    "hideBeforeLogin": true,
    "items": [
      { "order": 0, "name": "Instellingen", "link": "/beheer/instellingen" },
      { "order": 1, "name": "Gebruikers", "link": "/beheer/gebruikers" }
    ]
  }
]
```

## Security Considerations

- **Authentication**: `CMSTool` is only invoked by the OpenRegister agent runtime, which requires a valid Nextcloud session or a configured agent user. There are no public endpoints.
- **Organisation isolation**: Every saved object carries the agent's `organisation` UUID. `ObjectService` enforces tenant isolation automatically.
- **Input validation**: Required parameters are validated before any database call. The LLM receives structured error responses rather than PHP stack traces.
- **RBAC**: All data operations go through `ObjectService`, which applies `PropertyRbacHandler` and `PermissionHandler` automatically.
- **No admin bypass**: The tool does not use `_rbac: false` or `_multitenancy: false` flags — standard RBAC applies to all agent-created objects.

## NL Design System

Not applicable. The CMS Tool is a backend-only integration class. No new Vue components are introduced.

## Trade-offs

### Considered: Separate controller endpoints per function

**Rejected**: REST endpoints require routes, CSRF tokens, CORS headers, and authentication middleware. The `ToolInterface` pattern is zero-route and integrates with every agent automatically.

### Considered: Generic tool handler on OpenRegister side

**Rejected**: OpenCatalogi's page/menu schemas are application-specific. A generic handler in OpenRegister would need to know OpenCatalogi's register/schema names, creating unwanted coupling. The tool pattern keeps domain logic in the owning application.

### Risk: ObjectService API differences across OpenRegister versions

**Mitigation**: The implementation uses named parameters (`object:`, `extend:`, `register:`, `schema:`) to remain compatible with different `ObjectService` method signatures. The code comment documents this explicitly.
