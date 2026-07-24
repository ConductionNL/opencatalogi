# Contract: add-document-content-search

## Consumers

- **Frontend consumers of the public search page** (currently: any WOO portal calling `GET /apps/opencatalogi/api/search`, e.g. the Tilburg-frontend `CardsResultsTemplate`). Existing consumers see zero behaviour drift because `_content` defaults to false.
- **External API clients** discovered via [openwoo-app-website's full-text search docs](https://openwoo.conduction.nl/docs/Integrations/fulltext-search/). Twin doc PRs are queued as a task in `tasks.md` to publish the `_content=true` opt-in once the OR-side prerequisite lands.
- **Internal follow-up specs** — this endpoint stays the canonical public search surface for any future opt-in extensions (e.g. `_snippet` for highlighted excerpts). Any future flag lands under the same envelope contract.

Not consumers of this change: OpenRegister itself (this is a one-way call OC → OR); other apps-extra projects (no direct cross-app dependency on the OC search endpoint).

## Endpoints

### `GET /apps/opencatalogi/api/search`

**Auth**: none required (`#[PublicPage]` + `#[NoCSRFRequired]`, per WOO-506 `SCH-PFTS-001`). Anonymous callers are fully supported. Visibility is enforced server-side via `isObjectPublic()` post-scoring — no auth token needed to consume the endpoint.

**Request** — new optional parameter added by this change:

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `_search` | string | no | — | Substring search across metadata + string properties (existing WOO-506 behaviour). |
| `_content` | boolean | **no (new)** | `false` | Opt-in: when `true`, additionally match on documents whose extracted body text contains the query. When `false` or omitted, endpoint response is byte-identical to the WOO-506 baseline. |
| `_limit`, `_page`, `_order[...]`, other `@self[...]` filters | various | no | — | Passed through to OR unchanged; behaviour identical to WOO-506. |

Example — content search opted-in:
```
GET /apps/opencatalogi/api/search?_search=YOUR_QUERY_HERE&_content=true&_limit=20
```

**Response (200)** — envelope shape unchanged from WOO-506:

```json
{
  "results": [
    {
      "id": "<uuid>",
      "title": "…",
      "@self": {
        "schema": "publication",
        "…": "…"
      }
    },
    {
      "id": "<uuid>",
      "title": "…",
      "@self": {
        "schema": "document",
        "…": "…"
      },
      "publication": {
        "id": "<uuid>",
        "slug": "<pub-slug>",
        "title": "<pub-title>"
      }
    }
  ],
  "total": 5
}
```

Content-matched document rows are byte-shape-identical to metadata-matched document rows; clients MUST NOT depend on any field to tell the two apart. Deduplication on `@self.id` is guaranteed — a document matching on both surfaces appears exactly once.

**Errors** — no new error codes introduced by this change; inherits the WOO-506 error surface:

| Code | Condition |
|---|---|
| 200 | Success — results envelope (may be empty). |
| 400 | Malformed query parameters (existing behaviour). |
| 503 | OpenRegister unavailable (existing WOO-506 fail-closed with logger warning; `_content` does not change this). |

## Error Codes

No error codes added by this change. The `_content` parameter is silently ignored if OR's `_content_search` flag is not yet available on the target env — the endpoint gracefully falls back to metadata-only matching and still returns HTTP 200 with the WOO-506 envelope. This makes deploy order (OR-first, OC-second) tolerant to lag.

| Code | Meaning | Condition |
|---|---|---|
| 200 | OK | Search executed; results returned. |
| 400 | Bad Request | Malformed parameter (e.g. non-boolean `_content` value). |
| 503 | Service Unavailable | OR-side `ObjectService` not installed / unreachable (existing WOO-506 behaviour). |

## Versioning

No formal API version header is used on this endpoint (matches WOO-506 conventions — Nextcloud app endpoints are not versioned in the URL). This change is **strictly additive**: a new optional query parameter with a false default. No client relying on the WOO-506 contract needs to change to keep working.

## Breaking Change Policy

This change makes no breaking changes to the WOO-506 contract:

- Envelope shape unchanged.
- No fields removed from any row.
- No fields renamed on any row.
- No status codes changed.
- Default behaviour byte-identical to the pre-change baseline.

Any future breaking change to this endpoint (e.g. removing the `_content` flag, changing the envelope) MUST land as a separate OpenSpec change with:
1. A parallel deprecation notice in the openwoo-app-website docs at least one release ahead of the breaking commit.
2. An entry in the OpenCatalogi CHANGELOG under a `## Breaking changes` heading.
3. Explicit consumer coordination through the WOO-518-style follow-up flow.

## SLA

This change does not introduce new SLA commitments. The endpoint inherits WOO-506's implicit expectations for public read-only surfaces on a Nextcloud install:

- **Latency** — best-effort. Content-search fan-out adds one additional query (OR `ChunkMapper::searchByKeyword()`) per request when `_content=true` is set; typical impact is a small fixed overhead on top of the WOO-506 baseline latency for the same query.
- **Availability** — matches the surrounding Nextcloud instance. No new dependencies are introduced by this change; the chunk store and index it reads from are already shipped by the merged OR `hybrid-document-search` change.
- **Rate limiting** — none added at the endpoint level. Infra-level throttling (if any) applies uniformly to both `_content=false` and `_content=true` requests.
