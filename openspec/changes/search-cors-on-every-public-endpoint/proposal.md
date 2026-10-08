---
kind: code
depends_on: []
---

# Proposal: search-cors-on-every-public-endpoint

## Why

A municipality wants a search box on its own website that queries its publications from the reader's browser. The publication list answers such a call, but the main search endpoint and the federated search do not: they send no CORS header and answer no preflight, so the browser blocks the call. The list of allowed websites can only be changed with `occ`.

opencatalogi matrix, row `srch-cors`, "Let any named website call the search from a reader's browser." Own rating partial.

- Own evidence: "cors_allowed_origins CSV allowlist (DcatController.php:91, PublicationsController.php:281, PublicationRulesController.php:391 on POST /api/publications/search, OPTIONS routes.php:99-149); SearchController.php and FederationController.php add no CORS header and have no OPTIONS route".
- Note: "The main search endpoints, /api/search and /api/federation/publications, send no CORS headers, so a browser on another site cannot call them. The allowlist has no settings UI (not in SettingsService allowedKeys)."

What the competitors show, quoted from the matrix:

| system | rating | evidence |
|---|---|---|
| CKAN | yes | "CORS headers are set for every response when ckan.cors.origin_allow_all is on or the Origin is in ckan.cors.origin_whitelist (ckan/views/__init__.py:18-25, config ckan/config/config_declaration.yaml:1070-1083), so a named website can call /api/3/action/package_search from the browser." |
| xxllnc Publiceren | yes | "a request to https://vught.woopublicaties.nl/publicationbackend/Categories/list with Origin https://example.org returned access-control-allow-origin: * on the Vught instance ... so any website can call the public API from a browser" |

No demand row. The row is `build` under the rule "two or more competitors rated yes".

## What changes

- `/api/search` and every `/api/federation/publications` endpoint answer CORS preflights and send the same allowlist-based CORS headers as the publication list.
- An administrator edits the list of allowed websites in the admin settings instead of with `occ`.
- The rule stays in one place: the `AnswersCrossOriginRequests` trait. The three controllers that still carry their own copy of it move onto the trait.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| opencatalogi | `srch-cors` | Let any named website call the search from a reader's browser. | partial | no CORS on `/api/search` and the federation endpoints; no settings screen for the allowlist |

## Existing work it builds on

- Main spec `cross-origin-api-access`, COR-001 (every public controller answers preflights). This change adds requirements for the search and federation controllers and for the settings screen; it does not rewrite COR-001.
- Main spec `publications`, PUB-010 (CORS on public publication endpoints).
- `lib/Controller/AnswersCrossOriginRequests.php`, the shared allowlist rule.

## Out of scope

- Credentialed cross-origin calls. `Access-Control-Allow-Credentials` stays false.
- A ready-made search widget for other websites (row `srch-widget`, deferred).
