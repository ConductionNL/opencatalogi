# Public API: calls from another website

A municipality can put a search box on its own website that queries its publications straight from the reader's browser. The browser only allows such a call when OpenCatalogi names that website in its CORS headers. Which websites it names is a list the administrator keeps.

## Set the allowed websites

Open **Administration settings → OpenCatalogi → Publishing options** and fill in **Websites that may call the public API**, one website per line:

```text
https://www.voorbeeldgemeente.nl
https://zoeken.voorbeeldgemeente.nl:8443
```

- A line is a bare origin: `https://` or `http://`, the host and an optional port. A path, query or trailing slash is refused, and the message names the line (`https://www.voorbeeldgemeente.nl/zoeken` is refused; `https://www.voorbeeldgemeente.nl` is right).
- `*` allows every website. It is the default, and a list that holds `*` allows every website too.
- Upper case is stored in lower case, because a browser sends its origin in lower case.

The same list is the app config key `cors_allowed_origins`, comma separated:

```bash
occ config:app:set opencatalogi cors_allowed_origins --value="https://www.voorbeeldgemeente.nl,https://zoeken.voorbeeldgemeente.nl:8443"
```

## What the answers say

A website on the list is named back in `Access-Control-Allow-Origin`. A website that is not on the list is never named: the answer names the first website on the list, so the browser blocks the call. Credentials are never allowed (`Access-Control-Allow-Credentials: false`), so a cross-origin call is always an anonymous one.

These endpoints answer a preflight (`OPTIONS`) and carry the headers on every answer, errors included:

- `GET /api/search` and `/api/search/{id}`, `/attachments`, `/download`, `/uses`, `/used`
- `GET /api/federation/publications` and `/api/federation/publications/{id}`, `/attachments`, `/download`, `/uses`, `/used`
- the publication list `GET /api/{catalogSlug}` and the other public endpoints, which already did

The search and federation answers carry `Vary: Origin`, so a CDN keeps one site's answer apart from another's (see [cache headers](./public-api-cache.md)).

## Search from another website

```js
const base = 'https://cloud.voorbeeldgemeente.nl/index.php/apps/opencatalogi/api'
const answer = await fetch(`${base}/search?_search=${encodeURIComponent('begroting')}`)
const { results, total } = await answer.json()
```

The call carries no cookies and sees what an anonymous visitor sees.
