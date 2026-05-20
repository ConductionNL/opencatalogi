# Tasks: cms-tool

## 0. Deduplication Check

### Task 0: Verify no overlap with existing OpenRegister services
- **spec_ref**: `specs/cms-tool/spec.md` (Reuse Analysis in design.md)
- **files**: `lib/Tool/CMSTool.php`, OpenRegister `lib/Service/`, OpenRegister `lib/Formats/`
- **acceptance_criteria**:
  - GIVEN the CMS Tool implementation WHEN checked against OpenRegister's existing service inventory THEN no custom logic duplicates ObjectService, ImportService, or ExportService capabilities
- [x] 0.1 Search OpenRegister `lib/Formats/` for an existing `SlugFormat` or equivalent slug generator — none found; `generateSlug()` is justified
- [x] 0.2 Search OpenRegister `lib/Service/` for an LLM argument type-caster — none found; reflection-based casting in `__call` is justified
- [x] 0.3 Confirm all persistence delegates to `ObjectService.saveObject()` / `findAll()` — verified in `CMSTool.php`

---

## 1. CMSTool — Core ToolInterface Implementation

### Task 1: Implement CMSTool class with ToolInterface contract
- **spec_ref**: `specs/cms-tool/spec.md#req-cms-001-cmstool-must-implement-toolinterface`
- **files**: `lib/Tool/CMSTool.php`
- **acceptance_criteria**:
  - GIVEN `CMSTool` is instantiated WHEN `getName()` and `getDescription()` are called THEN the correct strings are returned
- [x] 1.1 Create `lib/Tool/CMSTool.php` implementing `OCA\OpenRegister\Tool\ToolInterface`
- [x] 1.2 Inject `ObjectService`, `LoggerInterface`, and `IUserSession` via constructor
- [x] 1.3 Implement `getName()` returning `'CMS Tool'`
- [x] 1.4 Implement `getDescription()` returning the standard description string
- [x] 1.5 Implement `setAgent(?Agent $agent)` — store agent, resolve `currentUserId` from session (fallback to `$agent->getUser()`)

---

## 2. Function Definitions

### Task 2: Implement getFunctions() with five OpenAI-compatible definitions
- **spec_ref**: `specs/cms-tool/spec.md#req-cms-002-getfunctions-must-return-five-openai-compatible-definitions`
- **files**: `lib/Tool/CMSTool.php`
- **acceptance_criteria**:
  - GIVEN `getFunctions()` is called WHEN the result is inspected THEN all five function definitions are present with correct parameter schemas
- [x] 2.1 Define `cms_create_page` function with `title` (required), `summary`, `description`, `slug` parameters
- [x] 2.2 Define `cms_list_pages` function with optional `limit` parameter
- [x] 2.3 Define `cms_create_menu` function with `title` (required), `position`, `items` (required, array with `order`/`name`/`link`), `groups`, `hideBeforeLogin`
- [x] 2.4 Define `cms_list_menus` function with no parameters
- [x] 2.5 Define `cms_add_menu_item` function with `menuId` (required), `name` (required), `link`, `pageId`, `order`

---

## 3. Page Functions

### Task 3: Implement createPage()
- **spec_ref**: `specs/cms-tool/spec.md#req-cms-003-cms_create_page-must-create-a-page-in-the-publication-register`
- **files**: `lib/Tool/CMSTool.php`
- **acceptance_criteria**:
  - GIVEN `cms_create_page` is called with `{ title: 'Over ons' }` WHEN `createPage()` runs THEN `ObjectService.saveObject()` is called with `register='publication'`, `schema='page'` and slug `'over-ons'` is auto-generated
- [x] 3.1 Validate `title` is non-empty; return error response (400) if missing
- [x] 3.2 Generate slug from title via `generateSlug()` when `slug` param is absent
- [x] 3.3 Build `pageData` array with `title`, `slug`, `summary`, `description`, `owner`, `organisation`
- [x] 3.4 Call `ObjectService.saveObject(object: $pageData, extend: [], register: 'publication', schema: 'page')`
- [x] 3.5 Return `successResponse('Page created successfully', ['pageId', 'title', 'slug'])`

### Task 4: Implement listPages()
- **spec_ref**: `specs/cms-tool/spec.md#req-cms-004-cms_list_pages-must-return-pages-for-the-agents-organisation`
- **files**: `lib/Tool/CMSTool.php`
- **acceptance_criteria**:
  - GIVEN `cms_list_pages` is called WHEN `listPages()` runs THEN `ObjectService.findAll()` is called with organisation filter and results are mapped to `{ id, title, slug, summary }`
- [x] 4.1 Read `limit` from params (default: 50)
- [x] 4.2 Build filters array with `organisation` from agent context
- [x] 4.3 Call `ObjectService.findAll(config: ['filters' => $filters, 'limit' => $limit])`
- [x] 4.4 Map results to `{ id, title, slug, summary }` and return in success envelope

---

## 4. Menu Functions

### Task 5: Implement createMenu() with item validation
- **spec_ref**: `specs/cms-tool/spec.md#req-cms-005-cms_create_menu-must-create-a-menu-with-validated-items`
- **files**: `lib/Tool/CMSTool.php`
- **acceptance_criteria**:
  - GIVEN `cms_create_menu` is called with an empty `items` array WHEN `createMenu()` runs THEN an error is returned without calling `ObjectService`
- [x] 5.1 Validate `title` is non-empty; return 400 error if missing
- [x] 5.2 Validate `items` is a non-empty array; return 400 error otherwise
- [x] 5.3 Validate each item for `order` (including `0`), `name`, and `link`; return 400 error naming the failing index
- [x] 5.4 Build `menuData` with `title`, `position`, `items`, `owner`, `organisation`; conditionally add `groups` and `hideBeforeLogin`
- [x] 5.5 Call `ObjectService.saveObject(object: $menuData, extend: [], register: 'publication', schema: 'menu')`
- [x] 5.6 Return `successResponse('Menu created successfully', ['menuId', 'title', 'position', 'itemCount'])`

### Task 6: Implement listMenus()
- **spec_ref**: `specs/cms-tool/spec.md#req-cms-006-cms_list_menus-must-return-all-menus-for-the-agents-organisation`
- **files**: `lib/Tool/CMSTool.php`
- **acceptance_criteria**:
  - GIVEN `cms_list_menus` is called WHEN `listMenus()` runs THEN each menu is returned with `id`, `title`, `position`, and `itemCount` derived from `count(menu.items)`
- [x] 6.1 Call `ObjectService.findAll(config: ['filters' => ['organisation' => ...]])`
- [x] 6.2 Map each menu to `{ id, title, position, itemCount }` using `getObject()`
- [x] 6.3 Return in success envelope with `count`

### Task 7: Implement addMenuItem()
- **spec_ref**: `specs/cms-tool/spec.md#req-cms-007-cms_add_menu_item-must-append-an-item-to-an-existing-menu`
- **files**: `lib/Tool/CMSTool.php`
- **acceptance_criteria**:
  - GIVEN `cms_add_menu_item` is called without `link` or `pageId` WHEN `addMenuItem()` validates THEN a 400 error is returned
- [x] 7.1 Validate `menuId` is non-empty; return 400 error if missing
- [x] 7.2 Validate `name` is non-empty; return 400 error if missing
- [x] 7.3 Validate at least one of `link` or `pageId` is provided; return 400 error otherwise
- [x] 7.4 Build `menuItemData` with `name`, `menu`, `link`, `page`, `order`, `owner`, `organisation`
- [x] 7.5 Call `ObjectService.saveObject(object: $menuItemData, extend: [], register: 'publication', schema: 'menuItem')`
- [x] 7.6 Return `successResponse('Menu item added successfully', ['menuItemId', 'name', 'menuId'])`

---

## 5. Slug Generation

### Task 8: Implement generateSlug()
- **spec_ref**: `specs/cms-tool/spec.md#req-cms-008-slug-generation-must-produce-url-safe-strings`
- **files**: `lib/Tool/CMSTool.php`
- **acceptance_criteria**:
  - GIVEN title `'Onze Diensten & Partners'` WHEN `generateSlug()` is called THEN result is `'onze-diensten-partners'`
- [x] 8.1 Convert title to lowercase with `strtolower()`
- [x] 8.2 Replace non-`[a-z0-9]` sequences with `-` using `preg_replace`
- [x] 8.3 Trim leading/trailing hyphens with `trim($slug, '-')`

---

## 6. LLPhant Compatibility

### Task 9: Implement __call() for snake_case → camelCase dispatch
- **spec_ref**: `specs/cms-tool/spec.md#req-cms-009-__call-must-support-snake_case--camelcase-dispatch-for-llphant-compatibility`
- **files**: `lib/Tool/CMSTool.php`
- **acceptance_criteria**:
  - GIVEN LLPhant calls `cms_create_page` via `__call` WHEN the name is processed THEN `createPage()` is invoked and the array result is JSON-encoded
- [x] 9.1 Strip `cms_` prefix using `preg_replace('/^cms_/', '', $name)`
- [x] 9.2 Convert to camelCase with `lcfirst(str_replace('_', '', ucwords($methodName, '_')))`
- [x] 9.3 Check method existence; throw `BadMethodCallException` if not found
- [x] 9.4 Use `ReflectionMethod` to type-cast positional or named arguments
- [x] 9.5 Implement `resolveParameterValue()` to handle string `'null'` → default/null
- [x] 9.6 Implement `castParameterValue()` for int, float, bool, string, array via `match`
- [x] 9.7 JSON-encode array results before returning to LLPhant

---

## 7. Tool Registration

### Task 10: Implement ToolRegistrationListener
- **spec_ref**: `specs/cms-tool/spec.md#req-cms-010-tool-must-be-registered-via-toolregistrationlistener-on-toolregistrationevent`
- **files**: `lib/Listener/ToolRegistrationListener.php`
- **acceptance_criteria**:
  - GIVEN `ToolRegistrationEvent` is dispatched WHEN `ToolRegistrationListener.handle()` is called THEN `event.registerTool()` is called with id `'opencatalogi.cms'` and correct metadata
- [x] 10.1 Create `lib/Listener/ToolRegistrationListener.php` implementing `IEventListener<Event>`
- [x] 10.2 Inject `CMSTool` via constructor
- [x] 10.3 Guard on `$event instanceof ToolRegistrationEvent`; return early otherwise
- [x] 10.4 Call `$event->registerTool(id: 'opencatalogi.cms', tool: $this->cmsTool, metadata: [name, description, icon, app])`

### Task 11: Register listener in Application.php
- **spec_ref**: `specs/cms-tool/spec.md#req-cms-010-tool-must-be-registered-via-toolregistrationlistener-on-toolregistrationevent`
- **files**: `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN the OpenCatalogi app boots WHEN `Application.register()` runs THEN `ToolRegistrationListener` is bound to `ToolRegistrationEvent` via `IEventDispatcher`
- [x] 11.1 Add `$dispatcher->addServiceListener(ToolRegistrationEvent::class, ToolRegistrationListener::class)` in `Application.register()`

---

## 8. Error Handling and Logging

### Task 12: Implement executeFunction() with logging and exception handling
- **spec_ref**: `specs/cms-tool/spec.md#req-cms-011-executefunction-must-log-invocations-and-catch-exceptions`
- **files**: `lib/Tool/CMSTool.php`
- **acceptance_criteria**:
  - GIVEN `cms_create_page` throws `\RuntimeException` WHEN `executeFunction()` catches it THEN `{ success: false, error: '...', code: 500 }` is returned without re-throwing
- [x] 12.1 Log INFO at entry with `function`, `userId`, `agentId` context
- [x] 12.2 Wrap `match` dispatch in `try/catch (\Exception $e)` — return `errorResponse($e->getMessage())`
- [x] 12.3 Include `default => $this->errorResponse('Unknown function: '.$functionName, 404)` in match
- [x] 12.4 Implement `errorResponse(string $message, int $code=500)` — log ERROR + return `['success'=>false,'error'=>$message,'code'=>$code]`
- [x] 12.5 Implement `successResponse(string $message, array $data=[])` — return `['success'=>true,'message'=>$message,'data'=>$data]`

---

## 9. Seed Data

### Task 13: Add seed pages and menus to publication_register.json
- **spec_ref**: `design.md#seed-data`
- **files**: `lib/Settings/publication_register.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN `importFromApp()` runs THEN 5 seed pages and 3 seed menus are created in the publication register
- [ ] 13.1 Add 5 seed page objects under `components.objects[]` using `@self` envelope (`slug: seed-over-ons`, `seed-contact`, `seed-privacyverklaring`, `seed-handleiding`, `seed-toegankelijkheidsverklaring`)
- [ ] 13.2 Add 3 seed menu objects (`slug: seed-hoofdmenu`, `seed-footermenu`, `seed-beheerdersmenu`) with Dutch item labels
- [ ] 13.3 Verify `importFromApp()` idempotency — re-import skips existing slugs

---

## 10. Schema Gap Fix

### Task 14: Add menuItem schema to publication_register.json
- **spec_ref**: `specs/cms-tool/spec.md` (Known limitation: menuItem schema absent)
- **files**: `lib/Settings/publication_register.json`
- **acceptance_criteria**:
  - GIVEN `cms_add_menu_item` saves to `schema='menuItem'` WHEN the schema is checked THEN a `menuItem` schema exists in the publication register with `name`, `menu`, `link`, `page`, `order`, `groups` properties
- [ ] 14.1 Define `menuItem` schema in `publication_register.json` with properties matching `addMenuItem()` data shape
- [ ] 14.2 Add `menuItem` to the register's `schemas` list
- [ ] 14.3 Bump `publication_register.json` version string

---

## Verification

- [x] CMSTool implements all five functions (create/list pages, create/list menus, add menu item)
- [x] Tool registration listener registers with id `opencatalogi.cms`
- [x] `__call` maps snake_case LLPhant calls to camelCase methods and JSON-encodes results
- [x] All error responses are structured `{ success, error, code }` — no bare PHP exceptions
- [x] Organisation and owner are set on all created objects
- [ ] Seed data added to publication_register.json
- [ ] menuItem schema defined and registered
- [ ] End-to-end test: configure an agent in OpenRegister, verify it can call `cms_create_page` and retrieve the result
