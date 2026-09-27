# Design: operations-public-api-cache-headers

Read at opencatalogi development `1694b051`.

## Where it lands

| piece | file | what is there now |
|---|---|---|
| publication list and item | `lib/Controller/PublicationsController.php:433` `index()` and `:603` `show()`, `#[PublicPage]`, `#[AnonRateLimit(limit: 120, period: 60)]`, routes `appinfo/routes.php:251-252` (`/api/{catalogSlug}`, `/api/{catalogSlug}/{id}`) | CORS headers added at :282-284; no cache header |
| public search | `lib/Controller/SearchController.php:141` `index()`, `#[PublicPage]`, `#[AnonRateLimit(limit: 60, period: 60)]`, route `:231` `/api/search`; `:197` `show()` | no cache header |
| precedent | `lib/Controller/DcatController.php:224-233` answers 304 on a matching `If-None-Match`; `:350` `cachingHeaders()` sets `Last-Modified` and `ETag` | DCAT only |
| settings | `lib/Service/SettingsService.php` defaults and `allowedKeys` | no cache setting |

## D1. One helper, applied at the end of each public read

A trait `AnswersCacheably` beside `AnswersCrossOriginRequests` (`lib/Controller/AnswersCrossOriginRequests.php`) with `cacheable(JSONResponse $response): Response`:

- signed-in caller (`IUserSession::isLoggedIn()`): `Cache-Control: private, no-store`, nothing else;
- anonymous caller and a 200 answer: `Cache-Control: public, max-age=N, must-revalidate`, `ETag` = a weak tag over the serialised body, `Vary: Origin, Accept-Language`;
- a matching `If-None-Match`: 304 with the same `ETag` and `Cache-Control`, no body;
- error answers (4xx, 5xx): unchanged.

Applied in `PublicationsController::index()`, `show()`, `attachments()` and `SearchController::index()`, `show()`. `N` comes from app config.

## D2. The administrator sets the time

App config key `public_api_cache_seconds`, default 60, 0 turns caching off (the answer then goes out as today). Added to `SettingsService` defaults and `allowedKeys`, and to the publishing section of the admin settings (`src/views/settings/Settings.vue`) as a number field with a sentence explaining the trade-off: a longer time means faster answers and a longer wait before a change is visible.

## D3. The ETag is computed after RBAC

The body is the anonymous answer after OpenRegister's RBAC and the published check, so the tag can never leak that a hidden record changed. Computing it costs one hash of the body, which the answer was going to serialise anyway.

## Declarative or imperative

HTTP headers on controller answers. No schema, lifecycle or widget.

## Seed data

None.

## Risks

- A CDN that ignores `Vary: Origin` could serve a CORS answer to the wrong site. The docs name the header, and D1 sets it on every cacheable answer.
- The anonymous rate limit still counts requests that reach Nextcloud; with a CDN in front, fewer do.
