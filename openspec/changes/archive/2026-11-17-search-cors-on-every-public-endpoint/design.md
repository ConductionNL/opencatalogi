# Design: search-cors-on-every-public-endpoint

Read at opencatalogi development `9aa54150`.

## Where it lands

| piece | file | what is there now |
|---|---|---|
| allowlist rule | `lib/Controller/AnswersCrossOriginRequests.php` `resolveAllowedOrigin()`: reads app config `cors_allowed_origins` (CSV, default `*`), echoes the caller's `Origin` only when it is on the list | used by `PublicationRulesController`, `CommunityController`, `ServiceCatalogueController`, `PublicationDisclosureController` |
| own copies | `lib/Controller/CatalogiController.php:146`, `OoapiController.php:98`, `ApiDocumentationController.php:83` read `cors_allowed_origins` themselves | three copies of the rule |
| publication list | `lib/Controller/PublicationsController.php:282-284` adds the CORS headers; `:401` `preflightedCors()`; OPTIONS routes `appinfo/routes.php:99-104` | done |
| search | `lib/Controller/SearchController.php:141` `index()` (`#[PublicPage]`), `:197` `show()`, routes `appinfo/routes.php:231-236` (`/api/search`, `/{id}`, `/attachments`, `/download`, `/uses`, `/used`) | no CORS header, no OPTIONS route |
| federation | `lib/Controller/FederationController.php:81` `publications()` (`@PublicPage`), `:135`, `:176`, `:217`, `:255`, `:276`; routes `appinfo/routes.php:238-243` | no CORS header, no OPTIONS route |
| settings | `lib/Service/SettingsService.php` `allowedKeys` | `cors_allowed_origins` is not writable through the API |

## D1. The search and federation controllers use the trait

`SearchController` and `FederationController` `use AnswersCrossOriginRequests`, add `Access-Control-Allow-Origin`, `-Methods` (`GET, OPTIONS`), `-Headers` and `Vary: Origin` to every answer of the actions above, and gain `preflightedCors()`. Twelve OPTIONS routes are added beside the existing block at `appinfo/routes.php:95-149`, one per GET route, before the `{catalogSlug}` wildcard routes (route order matters, PUB-012).

## D2. One rule, not four

`CatalogiController`, `OoapiController` and `ApiDocumentationController` drop their private copy and use the trait. Their behaviour does not change: the same key, the same default, the same echo rule.

## D3. The administrator edits the list

`cors_allowed_origins` joins `SettingsService` `allowedKeys`. The admin settings get a "Websites that may call the public API" field under the publishing section: one origin per line, saved as the CSV the trait reads, with `*` meaning any website. Each line must parse as `scheme://host[:port]` with no path; an invalid line is refused with the line named.

## Declarative or imperative

HTTP headers and a settings field. No schema, lifecycle or widget.

## Seed data

None.

## Risks

- A site that relied on the three controllers' copies behaving differently. They read the same key with the same default today, so the move is a refactor; the unit tests of D2 pin that.
- With the default `*`, every website may call search from a browser. That is today's behaviour of the publication list, and the settings field now makes it visible.
