---
status: reviewed
retrofit: true
---

# SPA Deep-Link Routing

## Purpose

The OpenCatalogi front-end is a single-page application that uses HTML5 history-mode
routing (clean URLs without a `#` fragment). When a user opens or refreshes a deep link
such as `/dashboard`, `/catalogi`, `/publications/123`, `/search` or `/directory`, the
browser issues a full page request to the server for that path. The server must answer
each of those paths by serving the SPA shell so the front-end router can take over and
resolve the route client-side. `UiController` provides one action per top-level route that
renders the SPA `index` template; without these actions the deep links would 404.

## Requirements

### Requirement: Serve the SPA shell for every top-level deep-link route (SPA-001)
`UiController` SHALL expose one `@NoAdminRequired` / `@NoCSRFRequired` action per
top-level front-end route — `dashboard`, `catalogi`, `publicationsIndex`,
`publicationsPage`, `search`, `organizations`, `themes`, `glossary`, `pages`, `menus` and
`directory` — each returning a `TemplateResponse` that renders the app's `index` template.
The response sets a Content Security Policy permitting outbound API connections
(`connect-src *`) so the loaded SPA can call the OpenCatalogi and OpenRegister APIs.

**Priority:** Must **Status:** Implemented

#### Scenario: Open a deep link directly
- GIVEN a user navigates the browser directly to a top-level route such as `/publications/123`
- WHEN the matching `UiController` action runs
- THEN it MUST return a `TemplateResponse` for the `index` template with a permissive `connect-src` CSP
- AND the front-end router resolves the remaining path client-side

#### Scenario: Template rendering failure
- GIVEN the `index` template cannot be rendered
- WHEN the shared `makeSpaResponse()` helper catches the exception
- THEN it MUST return the `error` template with HTTP status `500` and the exception message

> **Notes:** All eleven actions delegate to a single private `makeSpaResponse()` helper, so
> they share identical CSP and error-handling behaviour. The actions carry no per-route
> server-side logic — route resolution happens entirely in the front-end router.
