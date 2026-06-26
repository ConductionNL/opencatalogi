## ADDED Requirements

### Requirement: List endpoint delegates `_search` to the FTS engine when present (PUB-FTS-001)
The `GET /api/{catalogSlug}` endpoint (existing PUB-001) MUST delegate the `_search` query-parameter parsing and result scoring to the `publications-fts-engine` capability (see [`publications-fts-engine/spec.md`](../publications-fts-engine/spec.md)) when `_search` is supplied AND the engine is enabled (per `publications-fts-engine#FTS-011` runtime detection). When `_search` is absent, the endpoint behaviour MUST be unchanged from PUB-001.

#### Scenario: List query with _search routes through FTS engine
- GIVEN a catalog `publications` with at least one publication matching `_search=convenant`
- WHEN a GET request is made to `/api/publications?_search=convenant`
- THEN the request MUST be processed via the FTS engine pipeline (parser → sub-queries via `objectService->searchObjects` → merge + score)
- AND each response row MUST include `@self.relevance` per `publications-fts-engine#FTS-008`

#### Scenario: List query without _search is unchanged
- WHEN a GET request is made to `/api/publications?_limit=10` (no `_search`)
- THEN the response MUST behave identically to existing PUB-001 (catalog-scoped list, no `@self.relevance` key, default `@self.published desc` order)

### Requirement: Detail endpoint surfaces `@self.relevance` when invoked with `_search` (PUB-FTS-002)
The `GET /api/{catalogSlug}/{id}` endpoint (existing PUB-002) MUST attach an `@self.relevance` integer (0–100) to the returned object when the request includes a `_search` parameter AND the publication's content matches the parsed query under the engine's matching rules. When `_search` is absent or the publication does not match, the detail response MUST NOT contain `@self.relevance` (no field on the `@self` envelope).

#### Scenario: Detail with matching _search surfaces relevance
- GIVEN a publication whose `titel` contains the term `convenant`
- WHEN a GET request is made to `/api/publications/{id}?_search=convenant`
- THEN the response MUST contain `@self.relevance` as an integer in [0, 100]

#### Scenario: Detail without _search omits relevance
- WHEN a GET request is made to `/api/publications/{id}` (no `_search`)
- THEN the response object's `@self` MUST NOT contain a `relevance` key

### Requirement: List endpoint accepts `_order[@self.relevance]=desc` for relevance-ordered results (PUB-FTS-003)
The `GET /api/{catalogSlug}` endpoint MUST honour `_order[@self.relevance]=desc` (and `=asc`) as a valid sort key when combined with `_search`, ordering the result set by the computed relevance score per `publications-fts-engine#FTS-009`. The endpoint MUST reject (HTTP 400) the same sort key when `_search` is absent — there is no relevance to order by.

#### Scenario: Relevance-ordered search returns highest-scored first
- GIVEN publications X and Y where X has a stronger match than Y for `_search=convenant` per the engine's weighting
- WHEN a GET request is made to `/api/publications?_search=convenant&_order[@self.relevance]=desc`
- THEN publication X MUST appear before publication Y in the response

#### Scenario: Relevance sort without _search rejected
- WHEN a GET request is made to `/api/publications?_order[@self.relevance]=desc` (no `_search`)
- THEN the response status MUST be `400 Bad Request`
- AND the response body MUST contain a descriptive error indicating `_search` is required for relevance ordering

### Requirement: Anonymous-publication filter (`isObjectPublic`) MUST run AFTER FTS scoring (PUB-FTS-004)
The existing anonymous-caller filter in `PublicationsController` (which drops objects that are concept / depublished / lacking `@self.published`) MUST be applied AFTER the FTS engine merges and scores the candidate result set. A candidate that the engine matched against `_search` but the anonymous filter rejects MUST NOT be returned, and its omission MUST NOT cause gaps in pagination — the engine's pre-filter candidate-set MUST be requested with sufficient over-fetch (or re-queried) to fill the requested `_limit` after the anonymous filter trims rejects.

#### Scenario: Anonymous filter still hides drafts after FTS match
- GIVEN a publication whose `titel` matches `_search=convenant`
- AND that publication has `@self.published = null` (draft)
- WHEN an anonymous GET request is made to `/api/publications?_search=convenant`
- THEN the response MUST NOT include the draft publication
- AND any `@self.relevance` value computed for the draft MUST NOT be exposed to the anonymous caller
