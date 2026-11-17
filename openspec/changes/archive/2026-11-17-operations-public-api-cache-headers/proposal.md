---
kind: code
depends_on: []
---

# Proposal: operations-public-api-cache-headers

## Why

A municipality that puts a CDN in front of its reading room gains nothing for the publication API: every answer leaves Nextcloud marked not to be cached, so each reader's request reaches the server. Under load, such as a council decision everyone reads on the same morning, the anonymous rate limits start refusing readers instead.

opencatalogi matrix, row `ops-cdn-cache`, "Let a CDN cache the public catalogue API so readers get fast answers under load." Own rating partial.

- Demand: featureRequest, https://github.com/GetDKAN/dkan/issues/4783.
- Own evidence: "the DCAT harvest feeds send ETag and Last-Modified and answer a matching If-None-Match with 304 (lib/Controller/DcatController.php:224-233,343-360), which lets a CDN revalidate cheaply. Missing half: grep cacheFor, Cache-Control, max-age and ETag in lib finds no other caching header, so the public publications API /api/{catalogSlug} and /api/search go out with Nextcloud's default no-cache headers and a CDN cannot hold them."

What the competitors show, quoted from the matrix:

| system | rating | evidence |
|---|---|---|
| CKAN | yes | "every Flask response passes set_cache_control_headers_for_response (ckan/config/middleware/flask_app.py:467), which marks anonymous responses public with max-age set by ckan.cache_expires and must-revalidate (ckan/views/__init__.py:43-61), and marks logged-in ones private" |
| DKAN | partial | "responses carry Drupal cache metadata ... and datastore downloads set a public one-hour max-age ... but metastore item responses reach the CDN as no-cache per open issue https://github.com/GetDKAN/dkan/issues/4783." |

The row is `build` under the rule "a featureRequest demand row plus one competitor yes".

## What changes

- Anonymous answers of the public publication and search API say they may be cached, for a time the administrator sets, and carry an ETag.
- A reader or CDN that sends the ETag back gets 304 Not Modified when nothing changed.
- Answers to a signed-in user are marked private, because they can hold records the public cannot read.
- Answers vary on Origin, so a CDN never hands one site's CORS answer to another.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| opencatalogi | `ops-cdn-cache` | Let a CDN cache the public catalogue API so readers get fast answers under load. | partial | no cache header on `/api/{catalogSlug}` and `/api/search` |

## Existing work it builds on

- The DCAT feeds' conditional GET (`DcatController::cachingHeaders()`, main spec `dcat-ap-harvest`). This change applies the same idea to the publication and search API.
- Main spec `publications` PUB-010 (CORS on public endpoints) and main spec `search`.
- The catalogue cache in `CatalogiService` (`ICacheFactory::createDistributed('opencatalogi_catalogs')`, open change `catalogs`) is a server-side cache; it stays as it is.

## Out of scope

- A purge API for a CDN. A withdrawn publication can stay in a CDN for at most the configured time; the docs say so, and an administrator who needs instant removal sets a short time or purges at the CDN.
- Caching of file downloads, which OpenRegister serves.
