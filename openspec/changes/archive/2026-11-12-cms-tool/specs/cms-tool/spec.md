---
status: implemented
---

# CMS Tool Specification

## Purpose

Defines how OpenCatalogi exposes a CMS Tool to the OpenRegister agent runtime, enabling AI agents to create and manage pages and menus in the OpenCatalogi publication register through OpenAI-compatible function definitions. The tool implements `ToolInterface` and is registered automatically via event listener on application bootstrap.

## Context

OpenRegister agents execute multi-step workflows inside Nextcloud. They discover available tools via a `ToolRegistrationEvent` and call those tools by name using OpenAI-compatible function schemas. This spec covers the five CMS functions exposed by `CMSTool`, the registration mechanism, the LLPhant compatibility layer, and the argument validation rules that keep LLM interactions reliable.

**Relation to existing specs:**
- `content-management`: The `page` and `menu` schemas used by this tool are defined there. This spec covers only the agent-facing tool layer, not the schemas themselves.
- OpenRegister `ToolInterface` contract: External — defined in OpenRegister's `lib/Tool/ToolInterface.php`.

## Requirements

### REQ-CMS-001: CMSTool MUST implement ToolInterface

The `CMSTool` class MUST implement the OpenRegister `ToolInterface`, providing `getName()`, `getDescription()`, `setAgent()`, `getFunctions()`, and `executeFunction()`.

#### Scenario: Tool identity is correct
- GIVEN the CMSTool is instantiated
- WHEN `getName()` and `getDescription()` are called
- THEN `getName()` MUST return `'CMS Tool'`
- AND `getDescription()` MUST return `'Manage website content: create and manage pages, menus, and menu items for OpenCatalogi'`

#### Scenario: Agent context is stored
- GIVEN an `Agent` entity with organisation `'org-abc'` and user `'agent-user'`
- WHEN `setAgent($agent)` is called
- THEN the agent's organisation is available for subsequent function calls
- AND the current user ID is resolved from the Nextcloud session (with fallback to `$agent->getUser()`)

---

### REQ-CMS-002: getFunctions MUST return five OpenAI-compatible definitions

`getFunctions()` MUST return an array of five function definition arrays, each with `name`, `description`, and `parameters` following the OpenAI function-calling schema.

#### Scenario: Function list is complete
- GIVEN `CMSTool.getFunctions()` is called
- WHEN the result is inspected
- THEN it MUST contain exactly the functions: `cms_create_page`, `cms_list_pages`, `cms_create_menu`, `cms_list_menus`, `cms_add_menu_item`
- AND each function MUST have a `parameters.type = 'object'` wrapper with `properties` and `required` keys

---

### REQ-CMS-003: cms_create_page MUST create a page in the publication register

`executeFunction('cms_create_page', params)` MUST persist a new page object via `ObjectService.saveObject()` with `register='publication'` and `schema='page'`.

#### Scenario: Page created with explicit slug
- GIVEN an agent calls `cms_create_page` with `{ title: 'Onze diensten', slug: 'onze-diensten', summary: 'Overzicht' }`
- WHEN `executeFunction` dispatches to `createPage()`
- THEN `ObjectService.saveObject()` MUST be called with `register='publication'`, `schema='page'`
- AND the saved object MUST contain `title='Onze diensten'`, `slug='onze-diensten'`
- AND the result MUST be `{ success: true, data: { pageId: '<uuid>', title: 'Onze diensten', slug: 'onze-diensten' } }`

#### Scenario: Slug is auto-generated from title when not provided
- GIVEN an agent calls `cms_create_page` with `{ title: 'About Us' }`
- WHEN `createPage()` runs
- THEN the slug MUST be computed as `'about-us'` (lowercase, non-alphanumeric replaced with `-`, trimmed)
- AND the saved object MUST contain `slug='about-us'`

#### Scenario: Title is missing — returns error
- GIVEN an agent calls `cms_create_page` with `{}` (no `title`)
- WHEN `createPage()` runs
- THEN the result MUST be `{ success: false, error: 'Title is required', code: 400 }`
- AND `ObjectService.saveObject()` MUST NOT be called

#### Scenario: Organisation is set from agent context
- GIVEN an agent with `organisation='org-123'` is set via `setAgent()`
- WHEN `cms_create_page` creates a page
- THEN the saved object MUST include `organisation='org-123'`
- AND the saved object MUST include `owner` equal to the resolved user ID

---

### REQ-CMS-004: cms_list_pages MUST return pages for the agent's organisation

`executeFunction('cms_list_pages', params)` MUST query pages via `ObjectService.findAll()` filtered to the agent's organisation, up to the specified limit.

#### Scenario: Pages returned with default limit
- GIVEN an agent calls `cms_list_pages` with `{}`
- WHEN `listPages()` runs
- THEN `ObjectService.findAll()` MUST be called with `filters.organisation` set to the agent's organisation
- AND the result MUST be `{ success: true, data: { count: N, pages: [{ id, title, slug, summary }] } }`
- AND at most 50 pages MUST be returned

#### Scenario: Custom limit is respected
- GIVEN an agent calls `cms_list_pages` with `{ limit: 10 }`
- WHEN `listPages()` runs
- THEN `ObjectService.findAll()` MUST be called with `limit: 10`

---

### REQ-CMS-005: cms_create_menu MUST create a menu with validated items

`executeFunction('cms_create_menu', params)` MUST validate that `items` is a non-empty array with each item having `order`, `name`, and `link`, then persist via `ObjectService.saveObject()` with `schema='menu'`.

#### Scenario: Menu created successfully
- GIVEN an agent calls `cms_create_menu` with `{ title: 'Hoofdmenu', position: 0, items: [{ order: 0, name: 'Home', link: '/' }] }`
- WHEN `createMenu()` runs
- THEN `ObjectService.saveObject()` MUST be called with `register='publication'`, `schema='menu'`
- AND the result MUST be `{ success: true, data: { menuId: '<uuid>', title: 'Hoofdmenu', position: 0, itemCount: 1 } }`

#### Scenario: Menu without items is rejected
- GIVEN an agent calls `cms_create_menu` with `{ title: 'Leeg Menu', items: [] }`
- WHEN `createMenu()` runs
- THEN the result MUST be `{ success: false, error: 'Menu must have at least one item...', code: 400 }`
- AND `ObjectService.saveObject()` MUST NOT be called

#### Scenario: Menu item missing required field is rejected
- GIVEN an agent calls `cms_create_menu` with an item that has `order` and `name` but no `link`
- WHEN `createMenu()` validates items
- THEN the result MUST be `{ success: false, error: "Menu item 0 is missing 'link' field", code: 400 }`

#### Scenario: hideBeforeLogin is cast to boolean
- GIVEN `hideBeforeLogin` is passed as the string `'true'` by the LLM
- WHEN `createMenu()` processes the parameter
- THEN `menuData['hideBeforeLogin']` MUST be `true` (boolean, not string)

---

### REQ-CMS-006: cms_list_menus MUST return all menus for the agent's organisation

`executeFunction('cms_list_menus', [])` MUST query menus via `ObjectService.findAll()` and return summary fields for each.

#### Scenario: Menus returned
- GIVEN an agent calls `cms_list_menus`
- WHEN `listMenus()` runs
- THEN the result MUST be `{ success: true, data: { count: N, menus: [{ id, title, position, itemCount }] } }`
- AND `itemCount` MUST reflect `count(menu.items)`

---

### REQ-CMS-007: cms_add_menu_item MUST append an item to an existing menu

`executeFunction('cms_add_menu_item', params)` MUST validate `menuId` and `name`, require at least one of `link`/`pageId`, then persist via `ObjectService.saveObject()` with `schema='menuItem'`.

#### Scenario: Menu item added with external link
- GIVEN an agent calls `cms_add_menu_item` with `{ menuId: '<uuid>', name: 'Contact', link: '/contact' }`
- WHEN `addMenuItem()` runs
- THEN `ObjectService.saveObject()` MUST be called with `schema='menuItem'`
- AND the result MUST be `{ success: true, data: { menuItemId: '<uuid>', name: 'Contact', menuId: '<uuid>' } }`

#### Scenario: Neither link nor pageId provided — returns error
- GIVEN `cms_add_menu_item` is called without `link` or `pageId`
- WHEN `addMenuItem()` validates parameters
- THEN the result MUST be `{ success: false, error: 'Either link or pageId must be provided', code: 400 }`

#### Scenario: menuId missing — returns error
- GIVEN `cms_add_menu_item` is called without `menuId`
- WHEN `addMenuItem()` validates parameters
- THEN the result MUST be `{ success: false, error: 'Menu ID is required', code: 400 }`

---

### REQ-CMS-008: Slug generation MUST produce URL-safe strings

`generateSlug(title)` MUST convert any title to a lowercase, hyphen-separated, alphanumeric slug.

#### Scenario: Standard title
- GIVEN title `'Onze Diensten & Partners'`
- WHEN `generateSlug()` is called
- THEN the result MUST be `'onze-diensten-partners'`

#### Scenario: Title with special characters
- GIVEN title `'  FAQ -- Veelgestelde Vragen  '`
- WHEN `generateSlug()` is called
- THEN the result MUST be `'faq-veelgestelde-vragen'` (leading/trailing hyphens trimmed)

---

### REQ-CMS-009: __call MUST support snake_case → camelCase dispatch for LLPhant compatibility

`CMSTool.__call(name, arguments)` MUST strip the `cms_` prefix, convert the remainder to camelCase, type-cast arguments via reflection, and JSON-encode array results.

#### Scenario: snake_case function name resolves to camelCase method
- GIVEN LLPhant calls `cms_create_page` via `__call`
- WHEN `__call` processes the name
- THEN the `cms_` prefix MUST be stripped
- AND `create_page` MUST be converted to `createPage`
- AND `$this->createPage(...)` MUST be invoked

#### Scenario: Array result is JSON-encoded
- GIVEN `createPage()` returns `['success' => true, 'data' => [...]]`
- WHEN `__call` receives the result
- THEN the returned value MUST be a JSON string, not an array

#### Scenario: String 'null' argument is converted to null or default
- GIVEN LLPhant passes the string `'null'` for an optional parameter
- WHEN `__call` resolves the parameter via `resolveParameterValue()`
- THEN the resolved value MUST be the parameter's default value (if declared) or `null`

#### Scenario: Unknown method throws BadMethodCallException
- GIVEN LLPhant calls `cms_unknown_function` via `__call`
- WHEN no matching camelCase method exists
- THEN a `BadMethodCallException` MUST be thrown with a descriptive message

---

### REQ-CMS-010: Tool MUST be registered via ToolRegistrationListener on ToolRegistrationEvent

`ToolRegistrationListener` MUST listen for `ToolRegistrationEvent` and call `event.registerTool()` with the correct ID, tool instance, and metadata.

#### Scenario: Tool registration on bootstrap
- GIVEN OpenRegister dispatches `ToolRegistrationEvent`
- WHEN `ToolRegistrationListener.handle()` is called
- THEN `event.registerTool()` MUST be called with `id='opencatalogi.cms'` and the `CMSTool` instance
- AND metadata MUST include `name`, `description`, `icon='icon-category-office'`, `app='opencatalogi'`

#### Scenario: Non-matching event is ignored
- GIVEN a generic `Event` (not `ToolRegistrationEvent`) is dispatched
- WHEN `ToolRegistrationListener.handle()` is called
- THEN `event.registerTool()` MUST NOT be called

---

### REQ-CMS-011: executeFunction MUST log invocations and catch exceptions

`executeFunction()` MUST log each invocation at INFO level and catch any thrown `\Exception`, returning a structured error response without re-throwing.

#### Scenario: Successful function logs at INFO
- GIVEN `cms_list_menus` is executed
- WHEN `executeFunction()` dispatches the call
- THEN the logger MUST record an INFO entry with `function`, `userId`, and `agentId`

#### Scenario: Exception during execution is caught
- GIVEN `ObjectService.saveObject()` throws a `\RuntimeException`
- WHEN `executeFunction('cms_create_page', [...])` is called
- THEN the result MUST be `{ success: false, error: '<exception message>', code: 500 }`
- AND the exception MUST NOT propagate to the caller

#### Scenario: Unknown function name returns 404 error
- GIVEN `executeFunction('cms_delete_everything', [])` is called
- WHEN the `match` expression runs
- THEN the result MUST be `{ success: false, error: 'Unknown function: cms_delete_everything', code: 404 }`

---

## MODIFIED Requirements

_None — this is a new capability._

## REMOVED Requirements

_None._

## Current Implementation Status

**Fully implemented.** All requirements above are covered by:

- `lib/Tool/CMSTool.php` — `ToolInterface` implementation with all five functions
- `lib/Listener/ToolRegistrationListener.php` — event listener registering the tool
- `lib/AppInfo/Application.php` — listener bound to `ToolRegistrationEvent`

**Known limitations (not blocking):**
- `listPages()` and `listMenus()` call `ObjectService.findAll()` without an explicit `schema` filter; they rely on the default context set by the calling agent. If the agent context changes this may return mixed schema objects.
- `cms_add_menu_item` saves to `schema='menuItem'` but the `menuItem` schema is not currently defined in `publication_register.json` — it should be added to fully align with the data model.

## Dependencies

- OpenRegister `ToolInterface` — contract the CMSTool implements
- OpenRegister `ObjectService` — `saveObject()`, `findAll()` for CRUD
- OpenRegister `Agent` — provides `organisation` and `user` context
- OpenRegister `ToolRegistrationEvent` — event triggering tool registration
- Nextcloud `IUserSession` — session user for ownership
- Nextcloud `IEventDispatcher` — listener registration in `Application.php`
