# retrofit-2026-05-26-menu-page-management

## Why

OpenCatalogi is also a lightweight CMS: it manages navigation menus, menu items (with icon selection, group/permission scoping, ordering, footer positioning), pages, and page content blocks. These editing flows are real capabilities with substantial logic (icon-option building, SVG handling, multiline encode/decode, group normalization, ordering). This change reverse-specs them.

## What Changes

- Document the menu view/edit modal and the delete-menu-item modal (menu state, item add/edit/delete, ordering, group fetch, save).
- Document the menu-item form (icon selection and option building, SVG formatting, group/value-mode normalization, save).
- Document the page view/edit modal and the page-content form (content add/edit/delete, ordering, group normalization, save).
- No code changes — annotation-only retrofit.

## Impact

- **Affected specs**: new capability `retrofit-2026-05-26-menu-page-management`
- **Affected code**: `src/modals/menu/*.vue`, `src/modals/menuItem/*.vue`, `src/modals/page/*.vue`, `src/modals/pageContents/*.vue`, `src/dialogs/page/DeletePageContentDialog.vue`, `src/dialogs/menu/CopyMenuDialog.vue` (docblock `@spec` annotations only)
- **Risk**: none — comment-only; production app, no admin merge.
