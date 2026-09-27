---
kind: code
depends_on: []
---

# Proposal: woo-index-harvester-connection

## Why

The national Woo-index harvests OpenCatalogi's DiWoo sitemaps, so no publication is ever delivered to it by hand. Two things are still done by hand. An administrator types the registration status into a form, and the harvester reads `/robots.txt` at the domain root, which the app does not serve. Nextcloud's own root `robots.txt` says `Disallow: /`, so an unconfigured instance tells every crawler to stay away.

The opencatalogi matrix carries both as partial rows.

- Row `woo-index-delivery`, "Get publications into the national Woo-index without delivering each one by hand." Own evidence: "Harvest model: SitemapService.php:179/297 + RobotsController.php:93 expose all publications; Settings.vue:234-280 records Woo-index registration status by hand; NationalIndexService.php:219 registerWithWooIndex has no caller (grep registerWithWooIndex lib)". Note: "Works only as far as woo-sitemap works (external 'woo' register) and root robots.txt is rewritten."
- Row `woo-robots`, "Serve a robots.txt that points harvesters at those sitemaps." Own evidence: "RobotsController.php:93-140 lists 17 sitemap URLs per catalog but does not filter hasWooSitemap, and :131 joins catalogs with single-quoted '\n' (literal backslash-n); WooReadinessService.php:280 checks $baseUrl/robots.txt at the root". Note: "A harvester reads /robots.txt at the domain root, which the app does not serve without web-server config."

Two competitors are rated yes on each row.

| row | system | evidence, quoted from the matrix |
|---|---|---|
| `woo-index-delivery` | xxllnc Publiceren | "docs read 2026-09-26: https://xxllnc.nl/applicaties/publiceren/ FAQ "Woo-index": "een eigen publicatieportaal ... in uw huisstijl en een directe koppeling met de Woo-Index"; the Vught instance serves the DiWoo sitemap the harvester reads through robots.txt (https://vught.woopublicaties.nl/robots.txt)" |
| `woo-index-delivery` | iprox.open | "docs read 2026-09-26: https://iprox.nl/oplossingen/456/iprox-open-nieuw FAQ: "iprox.open regelt de aansluiting op de Woo-index automatisch. Na implementatie worden uw publicaties automatisch doorgezet naar open.overheid.nl, zonder handmatige handelingen"" |
| `woo-robots` | xxllnc Publiceren | "docs read 2026-09-26: https://vught.woopublicaties.nl/robots.txt returns "Sitemap: /sitemapindex-diwoo.xml" on the Vught instance" |
| `woo-robots` | iprox.open | "docs read 2026-09-26: https://open.waterschaplimburg.nl/robots.txt returns "Sitemap:" lines for the per-category DiWoo sitemap indexes" |

No demand row is recorded on either row. Both are `build` under the rule "two or more competitors rated yes".

## What changes

- The app's `robots.txt` lists only Woo-enabled catalogues, puts each `Sitemap:` line on its own line, and allows the sitemap and document paths it names.
- The Woo section of the admin settings gives the exact web-server rule that serves that file at the domain root, for Apache and for nginx, and the existing readiness check confirms the rule works.
- An administrator requests the Woo-index registration from the same section. OpenCatalogi composes the request from what it already holds and hands it to integriq's gateway. The status follows the gateway's answer instead of a hand-typed value.
- The readiness check runs once a day and after a catalogue is switched to publish a Woo sitemap, so the panel always shows a current verdict.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| opencatalogi | `woo-index-delivery` | Get publications into the national Woo-index without delivering each one by hand. | partial | registration kept by hand; `registerWithWooIndex` has no caller; the root `robots.txt` needs a rule nobody is given |
| opencatalogi | `woo-robots` | Serve a robots.txt that points harvesters at those sitemaps. | partial | not served at the domain root; no `hasWooSitemap` filter; literal backslash-n between catalogues |

## Existing work it builds on

- Main spec `woo-compliance`: WOO-004 and WOO-008 (robots.txt), WOO-HR-001 to WOO-HR-004 (the readiness check and the registration status, shipped by the archived `2026-07-23-woo-index-harvester-readiness`).
- Open change `woo-compliance` specifies the `hasWooSitemap` filter on robots.txt (REQ-WOO-004, REQ-WOO-008). This change does not repeat that requirement; task 1.1 lands it if that change has not.
- Open change `publication-inspection-and-the-national-indexes` shipped `NationalIndexService` (`lib/Service/Publication/NationalIndexService.php`) with every task checked. `registerWithWooIndex()` (:219) has no caller. This change adds only that caller and the screen around it.

## Out of scope

- Serving any file at the domain root from inside the app. Nextcloud owns the root; the operator adds the rule.
- The transport to the Woo-index. integriq owns the gateway, as `publication-inspection-and-the-national-indexes` already records.
- Sitemap content per document. `woo-sitemap-document-signals` covers `lastmod`, `hasPart` and `isPartOf`.

## Sibling halves

- ConductionNL/integriq owes the `national-woo-index` source with a `registrations` endpoint that `NationalIndexService::handOver()` calls through `CallService`. Until it exists, the panel shows the composed request so an administrator can send it and set the status by hand, as today.
