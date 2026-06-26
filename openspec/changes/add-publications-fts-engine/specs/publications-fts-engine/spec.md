---
status: proposed
---

# Publications FTS Engine

## Purpose

OpenCatalogi-side full-text-search engine that wraps OpenRegister's `MagicSearchHandler` to add Lucene-ish query parsing, per-schema field weighting, and TF-IDF-ish relevance scoring on top of OR's pure `ILIKE '%term%'` primitive. Without this engine, `_search=verzoek OR klacht` is treated as one literal substring; with it, the query is decomposed into separate OR-clause sub-queries whose merged results are re-ranked by per-field weight + match-count + corpus-frequency penalty. The engine composes on top of OR (no OR changes — ADR-022) and surfaces results via the existing `/api/{catalogSlug}` endpoints with a new `@self.relevance` computed field.

## Context

Reasoning behind the architectural choice:

- **ADR-022 (apps consume OR abstractions)** — OR is the storage primitive layer; publication-shaped semantics (multi-term queries, field weighting, scoring) belong in OpenCatalogi where catalog scoping + RBAC already live. Pushing this into OR would couple a generic object store to one consumer's needs.
- **ADR-031 (declarative business logic on schemas)** — per-field search weights live on the schema's `x-openregister.searchConfig` block, in the same shape as `authorization.read`. Deployers configure FTS the same way they configure RBAC.
- **ADR-032 (spec sizing)** — B2 scope only (parser + weights + scoring). B3 features (fuzzy `~`, boosting `^N`, proximity, grouped queries) are explicitly out of scope and will be separate changes if/when needed.
- **Composes on OR's existing `_search` ILIKE primitive** — the engine doesn't bypass OR; it issues one or more OR-compatible sub-queries via the existing `objectService->searchObjects` path and merges + re-ranks the union in PHP before pagination.
- **Solr/Elasticsearch backend coexistence** — when OR routes search through `IndexService` (Solr/ES), the engine SHOULD step out of the way and let the backend handle parsing natively (those backends already speak Lucene). The bypass is detected, not configured, so deployments can upgrade to Solr without redoing OpenCatalogi config.

## ADDED Requirements

### Requirement: Parse boolean OR operator in `_search` query strings (FTS-001)
The engine MUST recognise an uppercase `OR` token as a boolean operator that splits the query into two or more sub-terms; a row matches the query when ANY sub-term matches. The split MUST apply only when `OR` appears as a whole token between two whitespace-separated terms; a token that merely contains the letters `or` (e.g., the Dutch word `voor`) MUST NOT be treated as an operator.

#### Scenario: OR returns union of matches
- GIVEN three publications with titles "Convenant met gemeente", "Klacht ingediend", and "Vergunning aangevraagd"
- WHEN a GET request is made to `/api/publications?_search=convenant OR klacht`
- THEN the response MUST include "Convenant met gemeente" AND "Klacht ingediend"
- AND the response MUST NOT include "Vergunning aangevraagd"

#### Scenario: Lowercase `or` is treated as a literal term
- GIVEN one publication with title "Verzoek or weigering" (containing the literal text `or`)
- AND one publication with title "Andere titel"
- WHEN a GET request is made to `/api/publications?_search=verzoek or weigering`
- THEN the engine MUST search for the literal phrase "verzoek or weigering" (combined per FTS-002 implicit-AND rules), not as an OR-split
- AND the publication containing the literal phrase MUST be returned

### Requirement: Treat whitespace-separated terms as implicit AND (FTS-002)
The engine MUST treat multiple whitespace-separated terms (with no explicit operator between them) as an implicit AND: a row matches only when EVERY term appears in at least one indexed field. Each term is an independent substring (not joined into one literal substring as OR's bare `_search` would do).

#### Scenario: Implicit AND requires both terms to match
- GIVEN one publication with title "Verzoek vergunning evenement" (contains both `verzoek` and `vergunning`)
- AND one publication with title "Alleen verzoek" (contains only `verzoek`)
- WHEN a GET request is made to `/api/publications?_search=verzoek vergunning`
- THEN the response MUST include "Verzoek vergunning evenement"
- AND the response MUST NOT include "Alleen verzoek"

### Requirement: Support phrase queries via double quotes (FTS-003)
The engine MUST recognise text enclosed in straight double quotes (`"..."`) as a phrase query: a row matches only when the quoted sequence appears verbatim — word-adjacent and in order — in at least one indexed string field. The surrounding quotes MUST be stripped before the substring match; they MUST NOT be part of the matched value.

#### Scenario: Phrase query matches only when words are adjacent
- GIVEN one publication with title "Evenement vergunning aangevraagd"
- AND one publication with title "Vergunning voor evenement"
- WHEN a GET request is made to `/api/publications?_search="evenement vergunning"`
- THEN the response MUST include "Evenement vergunning aangevraagd"
- AND the response MUST NOT include "Vergunning voor evenement" (words are present but not adjacent in this order)

### Requirement: Support prefix wildcard via trailing `*` (FTS-004)
The engine MUST recognise a trailing `*` on a term as a prefix wildcard: the term `evenem*` matches any token starting with `evenem` (e.g., `evenement`, `evenementen`, `evenementenvergunning`). The wildcard MUST work on a single term boundary; mid-token wildcards (`ev*ment`) and leading wildcards (`*nement`) are out of scope.

#### Scenario: Prefix wildcard matches all derivatives
- GIVEN three publications with titles "Evenement gehouden", "Evenementen overzicht", "Vergunning gegeven"
- WHEN a GET request is made to `/api/publications?_search=evenem*`
- THEN the response MUST include both "Evenement gehouden" AND "Evenementen overzicht"
- AND the response MUST NOT include "Vergunning gegeven"

### Requirement: Operator case-sensitivity is strict (FTS-005)
The engine MUST treat `OR` and `AND` operators as operators ONLY when written in uppercase. A lowercase `or`, `and`, or mixed-case `Or` / `aNd` MUST be treated as literal search terms. This prevents Dutch words that share spelling with English operators (e.g., `or`, `an`) from being silently misinterpreted.

#### Scenario: Mixed-case operator is treated as a term
- GIVEN one publication with title "Or een ander voorbeeld" (contains the literal `Or`)
- WHEN a GET request is made to `/api/publications?_search=Or voorbeeld`
- THEN `Or` MUST be treated as a search term (not an operator)
- AND the result set MUST be the implicit-AND of `Or` + `voorbeeld`, matching the publication containing both

### Requirement: Per-schema `searchConfig` block declares field weights (FTS-006)
Each schema MAY declare a `x-openregister.searchConfig.fields` block listing which string properties participate in FTS and their relative weight. The block MUST use the same JSON shape as `x-openregister.authorization.read` (declarative business-logic ADR-031). When present, only the listed fields contribute to the relevance score; properties not in the block are searched (so substring matches still produce hits) but contribute weight 0 to scoring.

#### Scenario: Configured weights drive relevance order
- GIVEN a schema `convenanten` with `searchConfig.fields`: `{titel: 5, samenvatting: 3, beschrijving: 2}`
- AND publication A with `titel`: "Climate convenant" (term matches the title)
- AND publication B with `beschrijving` containing "convenant" (term matches a lower-weighted field)
- WHEN a GET request is made to `/api/publications?_search=convenant&_order[@self.relevance]=desc`
- THEN publication A MUST be ordered before publication B in the response

### Requirement: Default field weight is 1.0 when `searchConfig` is absent (FTS-007)
When a schema has no `x-openregister.searchConfig.fields` block, every `type: string` property MUST be treated with weight 1.0 for scoring purposes. This guarantees the engine produces a usable `@self.relevance` value for legacy schemas that have not yet been migrated to declarative search config.

#### Scenario: Schema without searchConfig still produces relevance
- GIVEN a schema `notes` with no `x-openregister.searchConfig` block defined
- AND a publication whose `samenvatting` contains the term `urgent`
- WHEN a GET request is made to `/api/{catalogSlug}?_search=urgent`
- THEN the response result MUST include `@self.relevance` for that publication
- AND the value MUST be a finite number > 0 (no NaN, no null)

### Requirement: Surface `@self.relevance` computed field on results when `_search` is present (FTS-008)
The engine MUST add a `relevance` key under each result row's `@self` object when `_search` is present in the query. The value MUST be a normalised score on a 0–100 integer scale, where 100 represents the strongest possible match within the current result set. The field MUST NOT be present when `_search` is absent (no `_search`, no scoring computation, no field clutter).

#### Scenario: @self.relevance present for searched query
- GIVEN at least one publication matches the search
- WHEN a GET request is made to `/api/publications?_search=convenant`
- THEN every result row MUST contain `@self.relevance` as an integer in [0, 100]
- AND the top-ranked result MUST have `@self.relevance` strictly ≥ all other results' relevance

#### Scenario: @self.relevance absent when no _search
- WHEN a GET request is made to `/api/publications?_limit=10` (no `_search`)
- THEN no result row's `@self` MUST contain a `relevance` key

### Requirement: Sort by relevance via `_order[@self.relevance]=desc` (FTS-009)
The engine MUST honour `_order[@self.relevance]=desc` (and `=asc`) as a valid sort key, ordering the result set by computed `@self.relevance` value. When this sort key is provided without `_search`, the engine MUST return an HTTP `400` with a descriptive error (no relevance computed → cannot order by it).

#### Scenario: _order[@self.relevance]=desc reorders results
- GIVEN two publications where publication X has a stronger match than publication Y per FTS-006 weighting
- WHEN a GET request is made to `/api/publications?_search=<term>&_order[@self.relevance]=desc`
- THEN publication X MUST appear before publication Y in the response

#### Scenario: _order[@self.relevance]=desc without _search returns 400
- WHEN a GET request is made to `/api/publications?_order[@self.relevance]=desc` (no `_search` parameter)
- THEN the response status MUST be `400 Bad Request`
- AND the response body MUST contain a descriptive error referencing the missing `_search` requirement

### Requirement: Compose on top of OR's `searchObjects` without modifying OpenRegister (FTS-010)
The engine MUST issue all object-fetch calls via OpenRegister's existing `ObjectService::searchObjects` / `searchObjectsPaginated` interface. The engine MUST NOT introduce new public methods on OR, MUST NOT bypass OR's RBAC (the existing `_rbac: true` invariant per ADR-022 stays untouched), and MUST NOT require schema-changes in OR's data model. Multiple sub-queries (one per OR-branch in the parsed AST) MAY be issued and their result-sets merged + de-duplicated by `@self.id` in PHP before scoring.

#### Scenario: Engine routes all DB reads via ObjectService
- GIVEN a publication catalog with the engine enabled
- WHEN any `_search`-bearing query reaches `PublicationsController::index`
- THEN the engine MUST call `objectService->searchObjects` (or `searchObjectsPaginated`) one or more times to fetch candidate rows
- AND the engine MUST NOT issue raw SQL against OpenRegister tables directly
- AND the `_rbac: true` parameter MUST be set on every such call

### Requirement: Step out of the way when a Solr/Elasticsearch backend is active in OpenRegister (FTS-011)
When OpenRegister's `isSolrAvailable()` (or equivalent index-backend detector) returns true, the engine MUST detect this condition and pass `_search` through to OR unparsed, letting the Solr/Elasticsearch backend handle Lucene parsing natively. The engine MUST NOT issue its own sub-queries in this mode (no double-parsing). The detection MUST be runtime, not config-driven, so a deployment can enable Solr without redoing OpenCatalogi config.

#### Scenario: Solr backend bypasses the OC parser
- GIVEN OpenRegister reports Solr available
- WHEN a GET request is made to `/api/publications?_search=convenant OR klacht`
- THEN the engine MUST forward the unparsed `_search` string to `objectService->searchObjects` exactly once
- AND the engine MUST NOT issue separate sub-queries for the `convenant` and `klacht` terms

### Requirement: Backwards-compatible behaviour when no operators / wildcards / quotes are used (FTS-012)
For `_search` values that contain no recognised operators (`OR`, `AND` in uppercase), no double-quoted phrases, and no trailing `*` wildcards, the engine MUST produce a result set IDENTICAL (in row identity, ordering before `@self.relevance` sort is applied, and pagination behaviour) to what OR's bare `_search` would return today. This guarantees existing consumers (woo-website-template-apiv2 and external integrators) see no behavioural change unless they opt into the new syntax.

#### Scenario: Plain single-term query is unchanged
- GIVEN a result set Z that OR's bare `_search=convenant` returns today (engine disabled)
- WHEN a GET request is made to `/api/publications?_search=convenant` (engine enabled)
- AND `_order[@self.relevance]=desc` is NOT specified
- THEN the row identities + ordering of the response MUST equal Z

#### Scenario: Default sort unchanged
- GIVEN no `_order` parameter is explicitly provided
- WHEN a GET request is made to `/api/publications?_search=convenant`
- THEN the default sort (typically `@self.published desc`) MUST apply
- AND `@self.relevance` MUST still appear on each row (per FTS-008) but MUST NOT drive ordering

## Non-Functional Requirements

- **Performance:** A single `_search` query (with up to 3 OR-branches and pagination `_limit ≤ 50`) MUST return within 800ms p95 on a catalog of up to 10 000 publications, measured against the local NC dev stack. The engine MUST avoid N+1 patterns; sub-queries are issued in parallel where the PHP runtime permits (`Promise::all` or equivalent in the OC service layer).
- **Backwards compatibility:** Per FTS-012, existing `_search` callers see no behavioural change unless they use new syntax. The `@self.relevance` field is additive (new key in an existing object); JSON consumers ignoring unknown keys are unaffected.
- **Accessibility:** No UI in this spec — purely API-layer. WCAG 2.2 AA SCs are not directly applicable. The eventual UI consumer (woo-website-template-apiv2) is responsible for accessible rendering of search-result reordering; this engine MUST NOT emit any UI fragments.
- **Internationalization:** The parser MUST be language-agnostic — operators are detected purely by uppercase `OR` / `AND` tokens and by ASCII quote/`*` literals. Search terms themselves are passed through to OR's ILIKE primitive which is collation-aware (per ADR-007). i18n of result *content* is handled by the existing `register-i18n` capability and is out of scope here.
- **Security:** The engine MUST NOT widen the visibility scope beyond what OR's `_rbac: true` enforces (per FTS-010). The parser MUST safely tokenise arbitrary user input — quotes, backslashes, percent signs, and trailing wildcards MUST NOT enable SQL-injection or RBAC-bypass. Every value passed to `objectService->searchObjects` MUST be sent via the parameterised query interface, never concatenated into a raw SQL string.

## Acceptance Criteria

- [ ] Parser unit tests cover every requirement scenario above (12 requirements × ≥ 1 scenario each = 14+ test cases).
- [ ] Scorer unit tests verify weighting + normalisation (a sweep of synthetic inputs producing known relevance ranks).
- [ ] Integration test seeds a register with at least 10 publications across 2 schemas (one with `searchConfig`, one without) and exercises each requirement end-to-end against the running NC dev stack.
- [ ] Backwards-compat regression test: an existing apiv2 query (`_search=verzoek&_order[@self.published]=desc&_limit=20`) returns row-identical output before vs after the engine ships (off vs on).
- [ ] The Solr-bypass branch is exercised by a test double of `ObjectService` that flips `isSolrAvailable()` between calls.

## Notes

- **Optional B3 follow-ups** (separate OpenSpec changes if/when prioritised): fuzzy tilde syntax (`term~`, `term~1`) — already partially supported by OR via `?_fuzzy=true` on `_name`, the parser can grow tilde translation; boosting (`term^N`); proximity matching (`"a b"~5`); grouped queries with parentheses (`(a OR b) AND c`); authority-bias and age-decay scoring.
- **Why `@self.relevance` and not `_score`** — keeps the metadata under the `@self` envelope (consistent with `@self.published`, `@self.created`, `@self.relevance` from the existing `?_fuzzy=true` mechanism in OR). Avoids re-using `_score` which would conflict with the legacy 1.0-aggregator response shape.
- **B2 explicitly avoids parser-grouping** — `(a OR b) AND c` would be in scope of B3. The MVP grammar is two flat levels: top-level AND of terms / quoted-phrases / wildcards, with explicit OR allowed between top-level slots. This is enough for >90% of realistic queries and substantially simpler to test.
- **`x-openregister.searchConfig` schema-block shape** — finalised in `design.md` (next artifact). Indicative shape: `{ "fields": { "titel": {"weight": 5}, "samenvatting": {"weight": 3} } }`. Future-extensible (per-field analyzer hints, stopword lists per field, etc.) without breaking changes.
- **Tests for "Solr backend bypasses the OC parser" (FTS-011)** can mock `isSolrAvailable()`; getting an actual Solr container into CI is out of scope for this change.
- **Cross-references** — see `publications/spec.md` (in this delta) for the integration requirements; `openregister/openspec/specs/search/spec.md` for the upstream search primitive.
