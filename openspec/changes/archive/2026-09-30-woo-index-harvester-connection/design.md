# Design: woo-index-harvester-connection

Read at opencatalogi development `1694b051`.

## Where it lands

| piece | file | what is there now |
|---|---|---|
| robots.txt | `lib/Controller/RobotsController.php:93` `index()`, route `appinfo/routes.php:33` (`/api/robots.txt`) | loops every slugged catalogue (:124-138), no `hasWooSitemap` check, `'\n'` in single quotes at :131, 17 category lines per catalogue at :135 |
| readiness check | `lib/Service/WooReadinessService.php:139` `runCheck()`, :279 `checkRobotsTxt()` fetches `{baseUrl}/robots.txt`, :503 `checkRegistration()` | runs only on `POST /api/woo/readiness/run` (`lib/Controller/WooReadinessController.php:108`) |
| registration status | `WooReadinessService::getRegistrationConfig()` :206, app config keys `woo_index_registration_status`, `_url`, `_at` (`lib/Service/SettingsService.php:521-523`, allowed keys :702-704) | set by hand in `src/views/settings/Settings.vue:234-280`, saved by `saveRegistration()` :1306 |
| national channel | `lib/Service/Publication/NationalIndexService.php:219` `registerWithWooIndex()`, :288 `handOver()` through `OCA\Integriq\Service\CallService` or `OCA\OpenConnector\Service\CallService` | no caller |
| background jobs | `appinfo/info.xml:136-149` registers `DirectorySync`, `RetentionEvaluation`, `Broadcast` | no readiness job |

Nextcloud's own `robots.txt` at the server root reads `User-agent: *` and `Disallow: /`. A harvester that honours it will not fetch anything under `/apps/opencatalogi/`.

## D1. The app gives the rule, the operator applies it, the check proves it

A Nextcloud app cannot answer at the domain root. The settings panel shows two ready rules that map `/robots.txt` to `/index.php/apps/opencatalogi/api/robots.txt`: an Apache `RewriteRule` for the Nextcloud `.htaccess` or vhost, and an nginx `location = /robots.txt` block. The rules are text rendered from the instance's base URL, not stored. The existing `checkRobotsTxt()` already fetches the root file and fails with `missing-sitemap-reference` when it has no sitemap line; the panel links that failure to the rules.

When the reading room runs on its own domain in front of portaliq, the rule belongs on that domain. The panel says so; the check reads the base URL the instance reports.

## D2. robots.txt content

- One `Sitemap:` line per sitemap index of each catalogue whose `hasWooSitemap` is true, each ending in a real line break (`"\n"`).
- `Allow:` lines for `/index.php/apps/opencatalogi/api/` and `/apps/opencatalogi/api/`, so a crawler that honours robots may fetch the sitemaps and the documents they point at.
- No `Disallow:` for the rest of Nextcloud is added or removed; the operator's root file keeps whatever else it says. The app's file is meant to replace the root file only through the D1 rule.

## D3. Registration goes through the gateway, and the status follows the answer

`POST /api/woo/registration` (admin, `#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]`) composes the request: organisation name and TOOI identifier (the same lookup `SitemapService::resolveOrganisationTooiIdentifier()` uses), the root `robots.txt` URL, and the sitemap index URLs of every Woo-enabled catalogue. It calls a reshaped `NationalIndexService::registerWithWooIndex(array $request)`. On an answer, the status moves to `requested`, the URL and time are stored, and the answer is kept in a new key `woo_index_registration_answer`. On `IndexUnreachableException`, nothing is stored as sent; the response carries the composed request so the panel can show it for sending by hand.

`registered` is set by an administrator when the index confirms, as now. The readiness check keeps comparing the registered URL with the base URL (WOO-HR-003).

The method changes shape from one publication to one instance. It has no caller, so nothing breaks.

## D4. A daily readiness check

A `TimedJob` `WooReadinessCheck` (24 hours, `ITimeFactory` based, ADR-069) calls `runCheck()` when `hasWooEnabledCatalogs()` is true. The same check runs once when a catalogue's `hasWooSitemap` changes to true. `lib/Listener/CatalogCacheEventListener.php:108` `handle()` already receives every catalogue create and update (registered in `lib/AppInfo/Application.php:129-137`); a new listener on the same events compares `ObjectUpdatedEvent::getOldObject()` with `getNewObject()` (the accessors :82-83 uses) and queues the check, so the object save never waits on outbound requests. The report is persisted as today (`persistReport()`), so the panel shows the last verdict and when it ran.

## Declarative or imperative

- Registration request and daily check: imperative. Both call an outside system or run scheduled work (ADR-031 exceptions: external integration, scheduled work). No new OpenRegister schema.
- No lifecycle, aggregation, notification or widget is added.

## Seed data

None. No schema changes.

## Risks

- An operator who adds the rule to the vhost but not to `.htaccess` (or the reverse) still sees a failing check. The panel names both places.
- The KOOP registration process may stay a form and an e-mail. Then the gateway source composes that e-mail; the contract here stays the same.

## As built (30 Sep 2026)

- **The registration has its own service and controller.** `WooRegistrationService` composes, sends and records; `WooRegistrationController` serves `GET /api/woo/registration` (the stored registration, the composed request and the two root rules), `POST /api/woo/registration` and `POST /api/woo/registration/confirm`. `WooReadinessService` stays under phpmd's class length; it only gains a public `getWooEnabledCatalogs()` and `runWhenEnabled()`.
- **The rules are rendered on the server,** from `IURLGenerator::getBaseUrl()`, so an instance under a sub-path gets a rule for its own route. The nginx rule is an internal `rewrite ... last` inside `location = /robots.txt`, not a redirect, so a harvester reads the file at the root URL.
- **An unreachable gateway answers 502** with the composed request and the unchanged registration.
- **The organisation comes from each Woo-enabled catalogue's `organization`,** read from OpenRegister for its name and `tooiIdentifier`, the same lookup the DiWoo sitemap uses for the publisher.
- **The one-off check is a `QueuedJob`** (`WooReadinessCheckNow`), queued by `WooReadinessTriggerListener` on `ObjectCreatedEvent` and on an `ObjectUpdatedEvent` whose old catalogue did not publish Woo and whose new one does. The daily `TimedJob` (`WooReadinessCheck`) is registered in `appinfo/info.xml`.
- **robots.txt starts with `User-agent: *`** and the two `Allow:` lines, then the `Sitemap:` lines. The sitemap index names keep their existing form (`sitemapindex-diwoo-infocat001.xml`).
