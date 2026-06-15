---
status: done
---

# CMS Tool (AI Agent Integration)

## Purpose

@e2e exclude pure AI-agent tool spec — all scenarios test the ToolInterface PHP implementation (getFunctions, executeFunction, snake_case __call mapping) with no browser-observable surface; covered by PHPUnit instead.

The CMS Tool provides AI agents running within OpenRegister with the ability to manage CMS content in OpenCatalogi. It implements the OpenRegister `ToolInterface` and exposes OpenAI-compatible function definitions that allow language models to create and list pages, create and list menus, and add items to menus. The tool is registered automatically via a `ToolRegistrationEvent` listener during application bootstrap.

## Requirements

### Requirement: Implement OpenRegister `ToolInterface` with getName(), getDescription(), setAgent(), getFunctions(), executeFunction() (CMS-T-001)
The CMS tool MUST implement OpenRegister `ToolInterface` with getName(), getDescription(), setAgent(), getFunctions(), executeFunction().

**Priority:** Must **Status:** Implemented

#### Scenario: Tool exposes the ToolInterface contract
- GIVEN the CMSTool class
- WHEN OpenRegister inspects it
- THEN it MUST implement `ToolInterface` exposing getName(), getDescription(), setAgent(), getFunctions(), and executeFunction()

### Requirement: Provide `cms_create_page` function to create pages with title, summary, description, slug (CMS-T-002)
The CMS tool MUST provide a `cms_create_page` function to create pages with title, summary, description, slug.

**Priority:** Must **Status:** Implemented

#### Scenario: Create a page via the tool
- GIVEN an agent has been set via setAgent()
- WHEN it calls `executeFunction('cms_create_page', { title: 'About Us' })`
- THEN a `page` object MUST be saved via ObjectService and the result MUST contain { pageId, title, slug }

### Requirement: Provide `cms_list_pages` function to list pages with optional limit (CMS-T-003)
The CMS tool MUST provide a `cms_list_pages` function to list pages with an optional limit.

**Priority:** Must **Status:** Implemented

#### Scenario: List pages via the tool
- GIVEN existing pages
- WHEN the agent calls `cms_list_pages` with an optional limit
- THEN the result MUST contain a count and a list of pages bounded by the limit

### Requirement: Provide `cms_create_menu` function to create menus with title, position, items, groups, hideBeforeLogin (CMS-T-004)
The CMS tool MUST provide a `cms_create_menu` function to create menus with title, position, items, groups, hideBeforeLogin.

**Priority:** Must **Status:** Implemented

#### Scenario: Create a menu via the tool
- GIVEN an agent is running
- WHEN it calls `cms_create_menu` with title="Main Menu" and items=[{order:0, name:"Home", link:"/"}]
- THEN a `menu` object MUST be saved and the result MUST contain the menuId and itemCount

### Requirement: Provide `cms_list_menus` function to list all menus (CMS-T-005)
The CMS tool MUST provide a `cms_list_menus` function to list all menus.

**Priority:** Must **Status:** Implemented

#### Scenario: List menus via the tool
- GIVEN existing menus
- WHEN the agent calls `cms_list_menus`
- THEN the result MUST contain a count and a list of menus with id, title, position, and itemCount

### Requirement: Provide `cms_add_menu_item` function to add items to existing menus (CMS-T-006)
The CMS tool MUST provide a `cms_add_menu_item` function to add items to existing menus.

**Priority:** Must **Status:** Implemented

#### Scenario: Add an item to an existing menu
- GIVEN a menu identified by menuId
- WHEN the agent calls `cms_add_menu_item` with a name and link or pageId
- THEN the new item MUST be persisted on the menu and the result MUST contain { menuItemId, name, menuId }

### Requirement: Auto-generate URL-friendly slugs from page titles when not provided (CMS-T-007)
The CMS tool SHALL auto-generate a URL-friendly slug from the page title when no slug is provided. It SHOULD derive the slug deterministically from the title.

**Priority:** Should **Status:** Implemented

#### Scenario: Slug auto-generated from title
- GIVEN a `cms_create_page` call with title "About Us" and no slug
- WHEN the page is created
- THEN the slug "about-us" MUST be auto-generated from the title

### Requirement: Respect agent's organization boundaries (set organisation on created objects) (CMS-T-008)
The CMS tool MUST respect the agent's organization boundaries (set organisation on created objects).

**Priority:** Must **Status:** Implemented

#### Scenario: Created object carries the agent's organisation
- GIVEN an agent with organization "org-123" set via setAgent()
- WHEN the agent creates a page or menu
- THEN the created object MUST carry organisation="org-123" and owner=agentUserId

### Requirement: Use ObjectService for data operations with RBAC support (CMS-T-009)
The CMS tool MUST use ObjectService for data operations with RBAC support.

**Priority:** Must **Status:** Implemented

#### Scenario: Data operations go through ObjectService
- GIVEN any CMS tool create or list operation
- WHEN it reads or writes objects
- THEN it MUST use OpenRegister's ObjectService so RBAC is enforced

### Requirement: Support `__call` magic method for snake_case to camelCase method resolution (LLPhant compatibility) (CMS-T-010)
The CMS tool SHALL support the `__call` magic method for snake_case to camelCase method resolution for LLPhant compatibility. It SHOULD map snake_case LLM calls to camelCase methods.

**Priority:** Should **Status:** Implemented

#### Scenario: snake_case call resolved via __call
- GIVEN the LLM framework calls `cms_create_page` via `__call`
- WHEN the call is routed
- THEN the `cms_` prefix MUST be stripped and `create_page` resolved to the `createPage` method

### Requirement: Type-cast arguments from LLM (handle string 'null', integer/boolean coercion) (CMS-T-011)
The CMS tool SHALL type-cast arguments received from the LLM, handling string 'null' and integer/boolean coercion. It SHOULD normalise loosely-typed LLM arguments.

**Priority:** Should **Status:** Implemented

#### Scenario: LLM arguments are type-cast
- GIVEN an LLM-supplied argument such as the string 'null' or a numeric string
- WHEN the tool method is invoked via `__call`
- THEN the argument MUST be coerced to the correct PHP type before execution

### Requirement: Return JSON-encoded results for LLM consumption via `__call` (CMS-T-012)
The CMS tool SHALL return JSON-encoded results for LLM consumption when invoked via `__call`. It SHOULD encode array results as JSON.

**Priority:** Should **Status:** Implemented

#### Scenario: Array result JSON-encoded for the LLM
- GIVEN a tool method that returns an array
- WHEN it is invoked via `__call`
- THEN the array result MUST be JSON-encoded for LLM consumption

### Requirement: Validate required parameters and return structured error responses (CMS-T-013)
The CMS tool MUST validate required parameters and return structured error responses.

**Priority:** Must **Status:** Implemented

#### Scenario: Missing required parameter returns a structured error
- GIVEN a tool call missing a required parameter
- WHEN it is executed
- THEN the tool MUST return a structured response with { success: false, error: ... } rather than throwing uncaught

### Requirement: Register tool via ToolRegistrationListener on OpenRegister's ToolRegistrationEvent (CMS-T-014)
The CMS tool MUST register itself via ToolRegistrationListener on OpenRegister's ToolRegistrationEvent.

**Priority:** Must **Status:** Implemented

#### Scenario: Tool registers on bootstrap
- GIVEN OpenRegister dispatches a ToolRegistrationEvent
- WHEN ToolRegistrationListener.handle() runs
- THEN it MUST call event.registerTool() with ID 'opencatalogi.cms' and the CMSTool instance plus metadata

### Requirement: Menu creation requires at least one item with order, name, and link fields (CMS-T-015)
Menu creation MUST require at least one item with order, name, and link fields.

**Priority:** Must **Status:** Implemented

#### Scenario: Menu creation without items fails
- GIVEN an agent calls `cms_create_menu` with a title but no items
- WHEN the function executes
- THEN it MUST return { success: false, error: "Menu must have at least one item..." } and not create a menu

## Architecture

### Registration Flow

```
Application.php register()
  --> registerEventListener(ToolRegistrationEvent::class, ToolRegistrationListener::class)

OpenRegister dispatches ToolRegistrationEvent
  --> ToolRegistrationListener.handle()
    --> event.registerTool('opencatalogi.cms', CMSTool, metadata)
```

### Key Components

| Component | Location | Responsibility |
|-----------|----------|----------------|
| CMSTool | `lib/Tool/CMSTool.php` | Implements ToolInterface, provides function definitions and execution |
| ToolRegistrationListener | `lib/Listener/ToolRegistrationListener.php` | Registers CMSTool when ToolRegistrationEvent is dispatched |
| Application | `lib/AppInfo/Application.php` | Registers the ToolRegistrationListener |

### Tool Registration Metadata

| Field | Value |
|-------|-------|
| ID | `opencatalogi.cms` |
| Name | `CMS Tool` |
| Description | `Manage website content: create and manage pages, menus, and menu items for OpenCatalogi` |
| Icon | `icon-category-office` |
| App | `opencatalogi` |

## Function Definitions

### cms_create_page

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| title | string | Yes | Page title |
| summary | string | No | Brief summary |
| description | string | No | Full page content (HTML or markdown) |
| slug | string | No | URL-friendly slug (auto-generated from title if not provided) |

Returns: `{ pageId, title, slug }`

### cms_list_pages

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| limit | integer | No | Maximum pages to return (default: 50) |

Returns: `{ count, pages: [{ id, title, slug, summary }] }`

### cms_create_menu

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| title | string | Yes | Menu title |
| position | number | No | Display position (0 = first) |
| items | array | Yes | Menu items, each with order, name, link (required), description, icon, groups (optional) |
| groups | array | No | Nextcloud groups that can access the menu |
| hideBeforeLogin | boolean | No | Hide menu for anonymous users |

Returns: `{ menuId, title, position, itemCount }`

### cms_list_menus

No parameters.

Returns: `{ count, menus: [{ id, title, position, itemCount }] }`

### cms_add_menu_item

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| menuId | string | Yes | UUID of the menu to add item to |
| name | string | Yes | Label for the menu item |
| link | string | No | External URL (provide either link or pageId) |
| pageId | string | No | UUID of a page to link to |
| order | integer | No | Display order in the menu |

Returns: `{ menuItemId, name, menuId }`

## Scenarios

### Scenario: AI agent creates a page
- GIVEN an AI agent with organization "org-123" is running
- AND the agent has been set via setAgent()
- WHEN the agent calls executeFunction('cms_create_page', { title: 'About Us' })
- THEN a slug "about-us" is auto-generated from the title
- AND ObjectService.saveObject() is called with register='publication', schema='page'
- AND the page object includes owner=agentUserId, organisation="org-123"
- AND the result contains { success: true, data: { pageId, title, slug } }

### Scenario: AI agent creates a menu with items
- GIVEN an AI agent is running
- WHEN the agent calls cms_create_menu with title="Main Menu" and items=[{order:0, name:"Home", link:"/"}]
- THEN each item is validated for required fields (order, name, link)
- AND ObjectService.saveObject() is called with register='publication', schema='menu'
- AND the result contains the menuId and itemCount

### Scenario: LLPhant snake_case compatibility
- GIVEN the LLM framework calls the tool via snake_case method names
- WHEN `cms_create_page` is called via `__call`
- THEN the `cms_` prefix is stripped
- AND `create_page` is converted to `createPage` (camelCase)
- AND the method is invoked with type-cast arguments
- AND the array result is JSON-encoded for LLM consumption

### Scenario: Menu creation without items fails
- GIVEN an agent calls cms_create_menu with title="Empty Menu" but no items
- THEN the function returns { success: false, error: "Menu must have at least one item..." }

### Scenario: Tool registration on bootstrap
- GIVEN OpenRegister dispatches a ToolRegistrationEvent
- WHEN ToolRegistrationListener.handle() is called
- THEN event.registerTool() is called with ID 'opencatalogi.cms' and the CMSTool instance
- AND metadata includes name, description, icon, and app fields

## Dependencies

- **OpenRegister ToolInterface** - Contract that CMSTool implements
- **OpenRegister ObjectService** - `saveObject()`, `findObjects()` for CRUD operations on pages and menus
- **OpenRegister Agent** - Agent entity providing organisation and user context
- **OpenRegister ToolRegistrationEvent** - Event that triggers tool registration
- **Nextcloud IUserSession** - Current user session for ownership assignment
- **Nextcloud IEventDispatcher** - Event listener registration in Application.php
