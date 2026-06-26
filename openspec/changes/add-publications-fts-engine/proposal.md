## Why

OpenCatalogi's `/api/{catalogSlug}?_search=…` endpoint currently passes `_search` through to OpenRegister's `MagicSearchHandler` unchanged, which implements **pure `ILIKE '%term%'` substring matching** over every string property + the metadata columns `_name`/`_description`/`_summary` (see `openregister/lib/Db/MagicMapper/MagicSearchHandler.php:473-522`). There is no tokenisation, no field weighting, no scoring, and no parsing of Lucene-style operators. Existing frontends and the OpenWoo documentation (now corrected on `Conduction/openwoo-app-website#26`) treat phrase quotes, boolean operators, wildcards, and "best match first" relevance as **wished-for but unimplemented** features.

Consumers of the publications API — `woo-website-template-apiv2` and external integrators — need at minimum: boolean OR/AND to combine terms, phrase quoting to require word adjacency, prefix wildcards (`term*`) for type-ahead UX, and a relevance-ordered alternative to the default `_order[@self.published]=desc` chronological sort. Solving this in OpenRegister would couple a generic object-storage primitive to publication-shaped semantics; per ADR-022 the right place is **OpenCatalogi**, which already wraps OR with publication-flavoured logic in `PublicationsController` and `PublicationQueryService`.

## What Changes

This change introduces an OpenCatalogi-side full-text-search engine that wraps OR's existing `_search` primitive. The engine sits in the OC catalog-search pipeline; OR is **not** modified (ADR-022 — apps consume OR abstractions).

- **NEW** OC-side `FulltextSearchService` that parses `_search` query strings into a structured AST and renders one or more OR-compatible sub-queries which it issues against `objectService->searchObjects`, then merges + re-ranks the result-set in PHP before pagination.
- **NEW** query-syntax support on `_search`:
  - **Boolean OR** — `_search=verzoek OR klacht` returns rows matching either term.
  - **Implicit AND** — `_search=verzoek vergunning` returns rows matching both (each as a separate substring, not as one literal substring).
  - **Phrase queries** — `_search="evenement vergunning"` returns rows where that exact word sequence appears in at least one indexed field.
  - **Prefix wildcards** — `_search=evenem*` matches `evenement`, `evenementen`, `evenementenvergunning`.
  - **Operator capitalisation** — `OR`/`AND` MUST be uppercase to register as operators (lowercase = literal token), so we don't break searches for the word "or" in Dutch text.
- **NEW** per-schema declarative configuration block `x-openregister.searchConfig.fields` (or equivalent — exact key chosen in design.md) that lists which schema properties participate in FTS and at what weight. Mirrors the shape of `authorization.read` rules; lives on the schema's `x-openregister` block per ADR-031 (declarative business logic on schemas). Default weight = 1.0 for any string property not explicitly listed.
- **NEW** computed `@self.relevance` field on every search result — a normalised score (0–100) combining (a) number of matched terms per result, (b) per-field weight from `searchConfig`, and (c) a TF-IDF-ish penalty for very common tokens. Only present when `_search` is supplied.
- **NEW** sort key `_order[@self.relevance]=desc` — returns best-match-first ordering. Default behaviour without an explicit `_order` falls back to the current `@self.published desc` chronological sort (no regression for existing consumers).
- **NEW** OC config flag (or auto-detection) to disable the engine and fall back to pure OR ILIKE pass-through; useful for Solr/Elasticsearch deployments where the backend already handles parsing.
- **REQUIREMENT update** on the existing `publications` capability spec: the `/api/{catalogSlug}` and `/api/{catalogSlug}/{id}` endpoints delegate `_search` parsing + scoring to the new FTS engine when present; otherwise behave exactly as today.

**Out of scope / future iterations (B3 — separate change(s) if/when needed):**
- Fuzzy matching via tilde syntax (`term~`, `term~1`) — OR already exposes `?_fuzzy=true` on `_name` via `pg_trgm`; the parser can grow tilde support later by translating it into that param + a secondary OR query.
- Term boosting (`term^3`) — the per-schema weights cover most use cases; in-query boost overrides can be layered on later without re-architecting.
- Proximity matching (`"woord1 woord2"~5`) — requires positional indexing, currently unavailable without Solr.
- Grouped queries with parentheses (`(verzoek OR klacht) AND vergunning`) — non-trivial parser work; the proposed grammar leaves room (operators in two flat levels) but doesn't implement grouping in this change.
- Authority-bias / age-decay scoring tweaks — out of MVP scope; revisit after observing real query patterns.

## Capabilities

### New Capabilities

- `publications-fts-engine`: OpenCatalogi-side full-text-search engine that parses `_search` query strings, weights matches per schema property, scores results into `@self.relevance`, and supports sort by relevance. Composes on top of OR's `MagicSearchHandler` without modifying it (ADR-022). Defines the query grammar (boolean OR/AND, phrase quotes, prefix wildcards), the per-schema `searchConfig` block shape, and the scoring algorithm.

### Modified Capabilities

- `publications`: Add requirements documenting that `GET /api/{catalogSlug}` and `GET /api/{catalogSlug}/{id}` invoke the new FTS engine for the `_search` parameter when the engine is enabled, and that the response surfaces `@self.relevance` (when `_search` present) and accepts `_order[@self.relevance]=desc`. No change to authentication, RBAC, attachments, or `/uses` `/used` semantics.

## Impact

**Affected code (OpenCatalogi only — no OR changes):**

- `lib/Service/FulltextSearchService.php` — new service, holds the parser + scoring + result-merge logic.
- `lib/Service/PublicationQueryService.php` — `buildCatalogSearchQuery()` becomes "if `_search` present and FTS engine enabled, delegate to `FulltextSearchService`; else current behaviour". Single integration seam.
- `lib/Controller/PublicationsController.php` — no signature changes; the engine slots in via the existing query-builder.
- Tests — new unit tests for the parser (every grammar branch), the scorer (weight handling, normalisation), and result-merge (multi-OR queries combining). Integration tests for the end-to-end `_search` → `@self.relevance` path against a seeded register.
- `lib/Settings/publication_register.json` — bundled WOO/publication schemas gain a `searchConfig` block (suggested defaults: `titel` × 5, `samenvatting` × 3, `beschrijving` × 2, attachments-text × 1).

**Affected APIs:**

- `GET /api/{catalogSlug}` — `_search` parsing semantics change (boolean/phrase/wildcard now work); `@self.relevance` appears in responses when `_search` is set; `_order[@self.relevance]=desc` becomes valid. Default sort unchanged.
- `GET /api/{catalogSlug}/{id}` — unchanged externally; internally same engine path used for any `_search`-aware queries (rare for detail).
- `/api/search` (existing internal admin endpoint) — **untouched** by this change. That endpoint's `SearchController` already delegates to `PublicationService` and can opt into the engine separately once it's stable; explicitly out of scope here.

**Dependencies:**
- None — purely additive on the OC side. No new composer packages required for B2 scope (parser is hand-rolled; scoring is straight PHP arithmetic).
- Optional dependency on `pg_trgm` only if/when B3 fuzzy tilde lands.

**Backwards compatibility:**
- Queries without `_search`: identical behaviour.
- Queries with `_search` that don't use new syntax (most existing callers): same result set; new `@self.relevance` field appears but is ignored by consumers that don't read it.
- Existing default sort behaviour (`@self.published desc`) preserved unless `_order[@self.relevance]=desc` explicitly passed.

**Stakeholders:**
- `Conduction/woo-website-template-apiv2` — primary consumer; can adopt `@self.relevance` sorting + new operators in a follow-up frontend PR (not blocked by this change).
- `Conduction/openwoo-app-website` — `docs/Integrations/fulltext-search.md` ([PR 26](https://codeberg.org/Conduction/openwoo-app-website/pulls/26)) will be re-updated to document the engine's behaviour once it ships.
- ADR-022, ADR-031, ADR-032 — design choices below align with these.
