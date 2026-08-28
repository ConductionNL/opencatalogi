# retrofit-2026-05-26-catalog-management

## Why

OpenCatalogi's core domain is the catalog: creating/editing a catalog (with organization, register, and schema bindings), viewing a catalog and its publications, and the generic detail/entity pages that render a catalog or other entity with its metadata and widgets. These are real capabilities lacking written specs; this change reverse-specs them.

## What Changes

- Document the catalog create/edit modal (organization/register/schema option resolution, validation).
- Document the catalog view modal (resolve register/schema by id, edit, delete, navigate to organization).
- Document the catalog detail page and the shared entity detail page (load, metadata items, config items, widgets, edit/delete, navigation).
- No code changes — annotation-only retrofit.

## Impact

- **Affected specs**: new capability `retrofit-2026-05-26-catalog-management`
- **Affected code**: `src/modals/catalog/*.vue`, `src/views/catalogi/CatalogDetailPage.vue`, `src/views/shared/EntityDetailPage.vue` (docblock `@spec` annotations only)
- **Risk**: none — comment-only; production app, no admin merge.
