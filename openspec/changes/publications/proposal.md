# Proposal: publications

## Summary
Formalize the Publications public API as a tracked OpenSpec change: the `PublicationsController` wildcard endpoints that serve catalog-scoped publication lists, single-object retrieval, attachment access, file download, and relation traversal for external frontends such as tilburg-woo-ui.

## Motivation
Publications are the primary read surface of OpenCatalogi for external consumers. The endpoints are live and marked Implemented in the canonical spec (`openspec/specs/publications/spec.md`), but no change artifact exists to trace implementation decisions, record the two private helper methods (`findObjectLocation` and `extractFilterValues`) and their edge cases, or drive follow-up quality work (CORS verification, route-ordering regression tests, RBAC on the list endpoint). This change captures the full spec, design, and task backlog so future modifications have a traceable baseline.

## Scope

### In scope
- Public list endpoint `GET /api/{catalogSlug}` with pagination, facets, and `@catalog` metadata
- Single-object endpoint `GET /api/{catalogSlug}/{id}` with fast-path + fallback via `findObjectLocation`
- Attachments endpoint `GET /api/{catalogSlug}/{id}/attachments`
- Download endpoint `GET /api/{catalogSlug}/{id}/download`
- Relation endpoints `GET /api/{catalogSlug}/{id}/uses` and `GET /api/{catalogSlug}/{id}/used`
- CORS preflight `OPTIONS` routes for all six endpoint variants
- `findObjectLocation` UNION ALL fallback across magic tables
- `extractFilterValues` filter-syntax normaliser (single, array, OR/AND, comma-separated)
- Route-ordering constraint: wildcard `{catalogSlug}` routes placed last in `routes.php`
- Multi-schema catalog universal-ordering restriction
- RBAC-enabled list endpoint (`_rbac: true` on `searchObjectsPaginated`)
- Seed data: 3–5 realistic Dutch publication objects in `publication_register.json`

### Out of scope
- Admin CRUD for publications (covered by the standard OpenRegister + `@conduction/nextcloud-vue` CRUD pattern)
- Catalog creation and configuration (covered by the catalogs spec)
- WOO-specific publication workflow (covered by `woo-transparency` spec)
- Authentication or authorisation changes — all endpoints are intentionally public (`#[PublicPage]`)

## kind
`code` — centre of mass is PHP controller and service logic. No schema changes proposed.
