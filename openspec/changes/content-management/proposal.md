# Content Management Specification

## Problem

OpenCatalogi serves as the orchestration environment for government catalog websites consumed by external frontends such as tilburg-woo-ui. These frontends require static CMS content — landing pages, navigation menus, thematic publication cards, and glossary definitions — to render properly. Without a standardized CMS layer, each frontend must maintain its own content or rely on ad-hoc configuration, making content updates error-prone and inconsistent across deployments.

The current implementation exposes four public CORS-enabled REST endpoints (pages, menus, themes, glossary) backed by OpenRegister objects. However, there is no formal specification documenting the data model, API contracts, access-control behaviour, or configuration mechanism, making it difficult to validate, extend, or onboard new frontends reliably.

## Proposed Solution

Formalise the Content Management feature as a first-class OpenSpec change. Key requirements:

- **Pages** — block-based static content pages retrievable by slug, with group-based access control and hideAfterLogin/hideBeforeLogin visibility flags
- **Menus** — hierarchical navigation menus with per-item group visibility; fallback to schema 7 / register 1 when not configured
- **Themes** — publication categorisation cards with image, icon, link, sort, and isExternal fields; served with facets
- **Glossary** — term definitions with keywords, bypassing Solr (`_source: database`) and skipping the publishing workflow
- All four content types stored as OpenRegister objects, configuration via `IAppConfig`, CORS preflight on every endpoint

## Scope

This change covers all requirements defined in the content-management specification:

- Public REST API for pages, menus, themes, and glossary (list + single-item endpoints)
- Block-based page content structure (contents array with type, data, groups, hideAfterLogin, hideBeforeLogin)
- Hierarchical menu items with nested sub-items and group visibility
- Theme display fields and faceted list response
- Glossary database-source queries and publishing workflow bypass
- IAppConfig-based schema/register configuration for each content type
- CORS headers on all public endpoints
- Admin UI views: PageIndex, MenuIndex, ThemeIndex, GlossaryIndex and associated modals/forms

## Success Criteria

- `GET /api/pages/{slug}` returns the correct page with its contents blocks and CORS headers
- `GET /api/menus` falls back to schema 7 / register 1 when IAppConfig keys are absent
- `GET /api/themes` returns pagination metadata and unwrapped facets
- `GET /api/glossary` uses `_source: database` and `published=false` in its query
- All OPTIONS preflight routes return 200 with correct CORS headers
- Admin UI lists all four content types and provides create/edit/view modals
