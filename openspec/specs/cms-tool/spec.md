# CMS Tool (AI Agent Integration)

## Purpose

The CMS Tool provides AI agents running within OpenRegister with the ability to manage CMS content in OpenCatalogi. It implements the OpenRegister `ToolInterface` and exposes OpenAI-compatible function definitions that allow language models to create and list pages, create and list menus, and add items to menus. The tool is registered automatically via a `ToolRegistrationEvent` listener during application bootstrap.

## Requirements

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| CMS-T-001 | Implement OpenRegister `ToolInterface` with getName(), getDescription(), setAgent(), getFunctions(), executeFunction() | Must | Implemented |
| CMS-T-002 | Provide `cms_create_page` function to create pages with title, summary, description, slug | Must | Implemented |
| CMS-T-003 | Provide `cms_list_pages` function to list pages with optional limit | Must | Implemented |
| CMS-T-004 | Provide `cms_create_menu` function to create menus with title, position, items, groups, hideBeforeLogin | Must | Implemented |
| CMS-T-005 | Provide `cms_list_menus` function to list all menus | Must | Implemented |
| CMS-T-006 | Provide `cms_add_menu_item` function to add items to existing menus | Must | Implemented |
| CMS-T-007 | Auto-generate URL-friendly slugs from page titles when not provided | Should | Implemented |
| CMS-T-008 | Respect agent's organization boundaries (set organisation on created objects) | Must | Implemented |
| CMS-T-009 | Use ObjectService for data operations with RBAC support | Must | Implemented |
| CMS-T-010 | Support `__call` magic method for snake_case to camelCase method resolution (LLPhant compatibility) | Should | Implemented |
| CMS-T-011 | Type-cast arguments from LLM (handle string 'null', integer/boolean coercion) | Should | Implemented |
| CMS-T-012 | Return JSON-encoded results for LLM consumption via `__call` | Should | Implemented |
| CMS-T-013 | Validate required parameters and return structured error responses | Must | Implemented |
| CMS-T-014 | Register tool via ToolRegistrationListener on OpenRegister's ToolRegistrationEvent | Must | Implemented |
| CMS-T-015 | Menu creation requires at least one item with order, name, and link fields | Must | Implemented |

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
