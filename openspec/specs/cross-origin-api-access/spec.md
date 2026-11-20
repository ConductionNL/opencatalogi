---
status: done
retrofit: true
---

# Cross-Origin API Access

## Purpose

@e2e exclude HTTP-contract spec — CORS header behaviour is verified by Newman API tests (OPTIONS preflight + Access-Control-Allow-Origin echo checks); not browser-UI observable in Playwright.

OpenCatalogi exposes public read APIs (catalogs, directory, publications, pages, menus,
glossary, themes) that are consumed by browser-based clients hosted on other origins —
embedded catalog widgets, federated directory portals, and standalone front-ends. Those
clients issue CORS preflight (`OPTIONS`) requests before their actual cross-origin calls.
Each public controller therefore implements a `preflightedCors()` action that answers the
preflight with the appropriate `Access-Control-*` headers, allowing the browser to proceed
with the real request.

## Requirements

### Requirement: Answer CORS preflight requests on public API controllers (COR-001)
Every public OpenCatalogi API controller SHALL answer CORS preflight requests. Each public controller — `CatalogiController`, `DirectoryController`,
`GlossaryController`, `MenusController`, `PagesController`, `PublicationsController` and
`ThemesController` — MUST expose a `preflightedCors()` action, declared
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

### Requirement: The search and federation endpoints answer cross-origin calls (REQ-SCO-001)

`GET /api/search` with its item, attachments, download, uses and used endpoints, and `GET /api/federation/publications` with the same five, SHALL answer an `OPTIONS` preflight and SHALL send `Access-Control-Allow-Origin` on every answer, resolved from the allowlist by the shared rule. They SHALL send `Vary: Origin` and `Access-Control-Allow-Credentials: false`.

#### Scenario: A municipality's website searches from the browser

- **GIVEN** the allowlist holds `https://www.voorbeeldgemeente.nl`
- **WHEN** a reader's browser on that site calls `GET /index.php/apps/opencatalogi/api/search?_search=begroting`
- **THEN** the answer carries `Access-Control-Allow-Origin: https://www.voorbeeldgemeente.nl`
- **AND** the browser shows the results on the municipality's page

#### Scenario: A site that is not on the list

- **GIVEN** the allowlist holds only `https://www.voorbeeldgemeente.nl`
- **WHEN** a browser on `https://elders.example` sends a preflight for `/api/federation/publications`
- **THEN** the answer does not name `https://elders.example`, so the browser blocks the call

### Requirement: One rule decides the allowed origin (REQ-SCO-002)

Every public controller that sends CORS headers SHALL resolve the allowed origin through the shared `AnswersCrossOriginRequests` rule. No controller SHALL read the allowlist setting itself.

#### Scenario: The catalogue list follows the shared rule

- **GIVEN** the allowlist holds two origins and a request comes from the second
- **WHEN** a browser calls `GET /api/catalogi`
- **THEN** the answer names the second origin, exactly as `GET /api/search` does for the same request

### Requirement: The administrator edits the list of allowed websites (REQ-SCO-003)

The admin settings SHALL show the websites that may call the public API, one per line, where `*` means any website. Saving SHALL refuse a line that is not a bare origin (scheme, host and optional port) and SHALL name that line.

#### Scenario: An administrator adds the municipality's website

- **GIVEN** an administrator on the publishing section of the OpenCatalogi admin settings
- **WHEN** they enter `https://www.voorbeeldgemeente.nl` and save
- **THEN** a browser on that site can call the search

#### Scenario: A line with a path

- **GIVEN** the same screen
- **WHEN** the administrator enters `https://www.voorbeeldgemeente.nl/zoeken` and saves
- **THEN** the save is refused and the message names that line
