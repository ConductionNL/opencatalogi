# Public API: cache headers for a CDN

The public read answers of OpenCatalogi can be kept by a CDN or a browser. A visitor without an account then gets a cached answer, and Nextcloud answers fewer requests.

## Which answers

- `GET /api/{catalogSlug}`: the publications of a catalogue
- `GET /api/{catalogSlug}/{id}`: one publication
- `GET /api/{catalogSlug}/{id}/attachments`: its attachments
- `GET /api/search`: the public search

## What the headers say

| caller | answer | headers |
|---|---|---|
| no account | 200 | `Cache-Control: public, max-age=N, must-revalidate`, a weak `ETag` over the answer, `Vary: Origin, Accept-Language` |
| no account, `If-None-Match` matches | 304, no body | the same `ETag` and `Cache-Control` |
| signed in | any | `Cache-Control: private, no-store`, no `ETag` |
| anyone | an error (4xx, 5xx) | no public cache header |

A signed-in answer can hold records the public cannot read, so it is never cached. The `ETag` is computed over the answer after the access check, so it does not reveal that a hidden record changed.

## Set the cache time

Open **Administration settings → OpenCatalogi → Publishing options** and set **Public cache time (seconds)**. The default is 60. Set 0 to switch the public cache headers off; the answers then go out as before.

The same value is the app config key `public_api_cache_seconds`:

```bash
occ config:app:set opencatalogi public_api_cache_seconds --value=300
```

## Setting up a CDN

- Let the CDN respect `Cache-Control` and `ETag` from the origin. It should revalidate with `If-None-Match` once `max-age` has passed.
- Keep `Origin` and `Accept-Language` in the cache key. The answers carry `Vary: Origin, Accept-Language` because the CORS header and the language differ per site and per reader. A CDN that ignores `Vary: Origin` can hand one site's CORS answer to another site.
- Do not cache requests that carry a Nextcloud session cookie or an `Authorization` header. The origin already marks those answers `private, no-store`.

## The withdrawal delay

A withdrawn or changed publication can stay visible in a cached answer for up to the cache time. With the default of 60 seconds that is one minute. Choose a shorter time if a withdrawal must disappear sooner, or purge the CDN by URL after a withdrawal.
