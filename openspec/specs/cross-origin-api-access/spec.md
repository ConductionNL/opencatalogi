---
status: reviewed
retrofit: true
---

# Cross-Origin API Access

## Purpose

OpenCatalogi exposes public read APIs (catalogs, directory, publications, pages, menus,
glossary, themes) that are consumed by browser-based clients hosted on other origins —
embedded catalog widgets, federated directory portals, and standalone front-ends. Those
clients issue CORS preflight (`OPTIONS`) requests before their actual cross-origin calls.
Each public controller therefore implements a `preflightedCors()` action that answers the
preflight with the appropriate `Access-Control-*` headers, allowing the browser to proceed
with the real request.

## Requirements

### Requirement: Answer CORS preflight requests on public API controllers (COR-001)
Every public OpenCatalogi API controller — `CatalogiController`, `DirectoryController`,
`GlossaryController`, `MenusController`, `PagesController`, `PublicationsController` and
`ThemesController` — SHALL expose a `preflightedCors()` action, declared
`@NoAdminRequired` / `@NoCSRFRequired` / `@PublicPage`, that responds to `OPTIONS`
requests with CORS headers so that cross-origin browser clients may proceed with the
actual request. The response echoes the request `Origin` header (falling back to `*` when
absent) and sets `Access-Control-Allow-Methods`, `Access-Control-Max-Age`,
`Access-Control-Allow-Headers` and `Access-Control-Allow-Credentials: false`.

**Priority:** Must **Status:** Implemented

#### Scenario: Preflight from a known origin
- GIVEN a cross-origin browser client about to call a public OpenCatalogi endpoint
- WHEN it sends an `OPTIONS` preflight with an `Origin` header
- THEN `preflightedCors()` MUST return a `Response` whose `Access-Control-Allow-Origin` echoes that origin
- AND the allowed methods, max-age, allowed headers and `Access-Control-Allow-Credentials: false` are set

#### Scenario: Preflight without an Origin header
- GIVEN an `OPTIONS` request that carries no `Origin` header
- WHEN `preflightedCors()` runs
- THEN it MUST default `Access-Control-Allow-Origin` to `*`

> **Notes:** The CORS values (`corsMethods`, `corsMaxAge`, `corsAllowedHeaders`) are
> controller-level configuration. `Access-Control-Allow-Credentials` is hard-coded to
> `false` across all controllers — credentialed cross-origin requests are not supported.
