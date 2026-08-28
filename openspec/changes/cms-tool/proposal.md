# Proposal: cms-tool

## Summary

Add a CMS Tool to OpenCatalogi that exposes OpenAI-compatible function definitions for OpenRegister agents, enabling language models to create pages, create menus, and add menu items on behalf of authenticated users within organization boundaries.

## Motivation

OpenRegister agents operate as autonomous assistants inside Nextcloud. They need the ability to populate catalog websites without human intervention — creating pages from content outlines, building navigation menus, and linking menu items to published pages. OpenCatalogi's `page` and `menu` schemas already exist in the publication register, but there was no programmatic tool interface for AI agents to interact with them. Without a `ToolInterface` implementation, agents have no way to call CMS functions, leaving catalog website setup fully manual.

## Scope

### In Scope

- `CMSTool` class implementing OpenRegister `ToolInterface` with five function definitions:
  - `cms_create_page` — create a page with optional slug auto-generation
  - `cms_list_pages` — list pages for the agent's organisation
  - `cms_create_menu` — create a menu with at least one validated item
  - `cms_list_menus` — list menus for the agent's organisation
  - `cms_add_menu_item` — append an item to an existing menu
- Automatic URL-friendly slug generation from page titles
- LLPhant framework compatibility via `__call` magic method (snake_case → camelCase dispatch, JSON-encoded results)
- Argument type-casting from LLM output (string `'null'`, integer/boolean coercion)
- RBAC and organisation boundary enforcement via `ObjectService`
- Structured success/error response envelopes for LLM consumption
- `ToolRegistrationListener` that registers the tool on `ToolRegistrationEvent`
- Event listener registration in `Application.php`

### Out of Scope

- Admin UI for reviewing agent-created content (handled by existing `CnIndexPage` views)
- Webhook notifications when pages or menus are created by agents
- Draft/publish workflow or versioning for agent-generated content
- Menu item update or delete functions (read/create surface only in this change)

## Affected Projects

- [x] `opencatalogi` — New `lib/Tool/CMSTool.php`, `lib/Listener/ToolRegistrationListener.php`, `lib/AppInfo/Application.php` update

## Approach

1. Implement `CMSTool` against `ToolInterface` with five function definitions matching the `page` and `menu` schemas in `lib/Settings/publication_register.json`.
2. Use `ObjectService.saveObject()` (register=`publication`, schema=`page`/`menu`/`menuItem`) for all writes and `ObjectService.findAll()` for reads — no custom queries or raw SQL.
3. Apply organisation context from the injected `Agent` entity to every created object; fall back to the session user for ownership.
4. Add `ToolRegistrationListener` that handles `ToolRegistrationEvent` and calls `event.registerTool('opencatalogi.cms', CMSTool, metadata)`.
5. Register the listener in `Application.php` via `IEventDispatcher::addServiceListener`.

## Cross-Project Dependencies

- **OpenRegister** — `ToolInterface`, `Agent`, `ObjectService`, `ToolRegistrationEvent` (event dispatcher contract)
- **Nextcloud core** — `IUserSession`, `IEventDispatcher`

## Rollback Strategy

Purely additive. The listener only fires when OpenRegister dispatches `ToolRegistrationEvent`; if OpenRegister is absent the listener never executes. Rollback: remove the `addServiceListener` call in `Application.php` and delete `CMSTool.php` and `ToolRegistrationListener.php`. No database migrations are required.

## Open Questions

_None — all design decisions resolved during implementation._
