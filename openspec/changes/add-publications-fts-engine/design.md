## Context

OpenCatalogi's `/api/{catalogSlug}` endpoint currently passes the `_search` query-parameter through to OpenRegister's [`MagicSearchHandler::buildSearchConditionSql`](https://codeberg.org/Conduction/openregister/src/branch/main/lib/Db/MagicMapper/MagicSearchHandler.php), which renders a single SQL `ILIKE '%term%'` clause over every `type: string` property + the metadata columns `_name` / `_description` / `_summary`. Result: substring matching only, no scoring, no field weights, no parsing of `OR` / `"..."` / `term*`.

The 12 FTS-NNN requirements in [`publications-fts-engine`](./specs/publications-fts-engine/spec.md) plus the 4 PUB-FTS-NNN delta requirements on [`publications`](./specs/publications/spec.md) define what changes. This document covers HOW.

**Current code paths involved:**

| Layer | File | What it does today |
|---|---|---|
| Controller entry | [`lib/Controller/PublicationsController.php:369`](../../../../lib/Controller/PublicationsController.php) | `index($catalogSlug)` resolves catalog, calls `queryService->buildCatalogSearchQuery()` |
| Query builder | [`lib/Service/PublicationQueryService.php`](../../../../lib/Service/PublicationQueryService.php) | `buildCatalogSearchQuery()` shapes the request params into an OR-compatible query envelope |
| OR call | `ObjectService::searchObjectsPaginated($query, _rbac: true, _multitenancy: false)` | The single point where the engine MUST intercept |
| Anonymous filter | `PublicationQueryService::isObjectPublic($object)` | Post-filter; uses `@self.published` / `@self.depublished` |

**Stakeholders:** `woo-website-template-apiv2` (consumer); external integrators (depend on stable `@self.relevance` shape once shipped); deployers (must add `searchConfig` to schemas to get scoring); future Solr/Elasticsearch operators (engine must yield gracefully).

## Goals / Non-Goals

**Goals:**

- One `FulltextSearchService` that owns the parser, the per-OR-branch query issuance, the result merge, and the scoring.
- Parser is a single-pass tokenizer — deterministic, side-effect-free, no external regex library beyond PHP's PCRE.
- Scoring produces a stable, ordered `@self.relevance` value (integer 0–100) per row in the merged result-set.
- Integration with `PublicationsController::index` requires zero signature changes on the controller, exactly one new branch in `buildCatalogSearchQuery`.
- All OR calls remain `_rbac: true` — RBAC is never widened by the engine.
- Seed data ships with a `searchConfig` block on the bundled `publication` schema so a fresh install can demonstrate scoring without operator setup.

**Non-Goals:**

- Indexing, inverted-index, or TF-IDF in the strict information-retrieval sense — we approximate with a frequency penalty but do not maintain corpus statistics across calls.
- Modifying OpenRegister (per ADR-022).
- Solr / Elasticsearch backend integration — when those are active, the engine MUST step aside and let OR's `IndexService` handle parsing.
- B3 features (fuzzy `~`, boosting `^N`, proximity `~5`, grouped queries) — out of scope here; see **B3 hooks** below for where they will plug in.
- Frontend changes in `woo-website-template-apiv2` — the consumer can opt into new ordering and new operators in a follow-up PR.
- Authority-bias / age-decay scoring — explicit non-goal; defer until we have query-pattern telemetry.

## Reuse Analysis

Per ADR-001 (Deduplication check), inventory of existing services this change leverages:

| Existing service / abstraction | How the engine uses it |
|---|---|
| `OpenRegister\Service\ObjectService::searchObjects` (and `…Paginated`) | The engine's **only** path to fetching candidate rows. One call per OR-branch in the parsed AST. RBAC + multitenancy invariants flow through unchanged. |
| `OpenCatalogi\Service\PublicationQueryService::buildCatalogSearchQuery` | The integration seam — gains one early branch that detects `_search` + engine-engaged conditions and routes to `FulltextSearchService`. |
| `OpenCatalogi\Service\PublicationQueryService::isObjectPublic` | Reused unchanged. Engine merges + scores BEFORE this filter runs; the filter still has the final word on anonymous visibility. PUB-FTS-004 in the spec encodes this ordering. |
| `OpenRegister\Service\ObjectService::isSolrAvailable()` | Detected at runtime; bypasses the parser entirely when true. FTS-011. |
| Per-schema `x-openregister.*` block (already used by `authorization.read`, ADR-031) | New sub-key `searchConfig` follows the same declarative-on-schema pattern — no new config storage layer. |
| `OpenRegister\Service\SettingsService::autoConfigure()` | Already loads bundled schemas + their `x-openregister` blocks on install. Picks up the new `searchConfig` block with no migration. |
| Nextcloud's PCRE / PHP standard library | The tokenizer uses `preg_split` + `preg_match` exclusively — no new composer dependencies for B2. |

**No overlap with**: no parser, no scorer, no FTS abstraction exists today in OC, OR, or any shared lib. Confirmed by grepping `lib/Service/` in both repos for `Fulltext`, `Lucene`, `tokenize`, `scor` — zero hits.

## Decisions

### D1 — Where the engine lives

`OpenCatalogi\Service\FulltextSearchService` — new file at `lib/Service/FulltextSearchService.php`. Single-responsibility class with a small public surface:

```php
class FulltextSearchService {
  public function isEngineEngaged(array $query): bool;
  public function search(array $query, Catalog $catalog, ObjectService $objectService): array;
}
```

Internal helpers (parser, scorer, merger) are `private` methods on the same class. We deliberately keep the surface narrow to limit the integration footprint in `PublicationQueryService` to a single call.

**Rationale:** A separate file (not a method on `PublicationQueryService`) keeps testability high — the parser can be unit-tested without instantiating the query service. The class is stateless (no caching, no shared corpus stats) so DI is trivial.

### D2 — Parser algorithm (single-pass tokenizer)

```
Input:  string  _search="convenant OR \"evenement vergunning\" verzoek*"
Output: AST    {orBranches: [
                  [term("convenant")],
                  [phrase("evenement vergunning"), wildcard("verzoek")]
                ]}
```

**Algorithm:**

1. Strip leading/trailing whitespace; if empty → return empty AST → engine yields, OR's bare `_search` runs unchanged.
2. Loop char-by-char with a small state machine:
   - **OUTSIDE_QUOTE**: accumulate until whitespace; on `"` switch to INSIDE_QUOTE.
   - **INSIDE_QUOTE**: accumulate until next `"`; emit one `phrase` token; back to OUTSIDE_QUOTE.
3. After tokenization, walk the flat token list:
   - Token equals exactly `OR` (uppercase, ASCII-only) → mark the boundary between OR-branches.
   - Token equals exactly `AND` → no-op (implicit AND is already the default).
   - Token ends with literal `*` → wildcard node, with the `*` stripped before going into the LIKE pattern.
   - Token starts and ends with `"` → already a phrase node from step 2.
   - Anything else → `term` node.
4. Resulting `orBranches: [Term[]]` — flat 2-level grammar (OR between branches, implicit AND within a branch).

**Why a hand-rolled tokenizer instead of a parser-generator:** the grammar is two-level flat. A full PEG/LL parser would be over-engineered. The implementation is ~80 lines of PHP and 100% covered by unit tests against the FTS-001 to FTS-005 scenarios. Future B3 grouping `(a OR b) AND c` would warrant a real parser — but that's B3.

**Operator capitalisation (FTS-005):** the equality check is `$token === 'OR'` (strict ASCII string compare). Any lowercase / mixed-case variant falls through to the `term` branch. Dutch words like `or`, `An`, `OR` (in quoted text) all behave correctly.

### D3 — Sub-query strategy

For each OR-branch:

1. Clone the original `$query` envelope.
2. Replace its `_search` value with a synthesised string that the existing OR ILIKE handler will accept. For a single-term branch this is just the term; for multi-term (implicit AND) we issue one OR call but build a custom SQL conjunction post-fetch — see D4 below.
3. Call `$objectService->searchObjectsPaginated($query, _rbac: true, _multitenancy: false)`. The catalog scoping, RBAC, and pagination params flow through untouched.

For each row returned, capture:
- The full object (so we don't refetch later for scoring),
- Which OR-branch it came from (provenance — needed for relevance disambiguation when a row matches multiple branches).

**Parallel issuance:** PHP doesn't have native async, but a small `react/promise` or `amphp/parallel` shim could parallelise the OR-branches. We **defer parallelisation to a B3 follow-up** — for the typical max 2-3 OR-branches the sequential cost is dominated by the database round-trip, and adding the dependency is a non-goal here.

### D4 — Result merge, de-duplication, AND-enforcement

After all OR-branches' rows are collected:

1. **De-duplicate** by `@self.uuid` — a row that matches multiple branches is counted once but its provenance set records both branches (used by the scorer below).
2. **Implicit AND enforcement** — when a single OR-branch had multiple terms (e.g., `verzoek vergunning`), the bare OR ILIKE will have already matched rows containing the concatenated literal `verzoek vergunning` (not the AND-intersection). The engine post-filters: for each row, verify EVERY term in that branch appears somewhere in the indexed fields. Rows failing this check are dropped from that branch's contribution (but may stay if matched via another OR-branch).
3. **Result-set is the union** of all branches' surviving rows, de-duped by uuid.

Performance note: the post-filter walks the indexed-fields-only subset of each row in PHP, comparing each term against each field value via `stripos`. For a typical row this is ~10 fields × ~3 terms × O(field_length) — negligible against the DB cost.

### D5 — Scoring formula

For each row in the merged set:

```
raw_score = Σ (terms-matched-in-field × field_weight)
            over every indexed string field

idf_penalty = 1 / log(1 + corpus_freq_of_token)   # per-token, applied once
              where corpus_freq = count(rows containing token) / total_rows

final_raw = Σ (matches × field_weight × idf_penalty)
relevance = round( final_raw × 100 / max(final_raw_in_set) )
```

- **Field weight** comes from `x-openregister.searchConfig.fields[fieldname].weight`, or defaults to `1.0` per FTS-007.
- **Matches-in-field** counts term occurrences (substring count) — `substr_count($lowercaseField, $lowercaseTerm)`.
- **IDF penalty** is computed per-token across the merged result-set (not corpus-wide — we don't maintain DB-wide statistics). A token in every row gets penalty `1/log(2) ≈ 1.44` capped; a rare token gets near 1.0.
- **Normalisation** divides by the result-set's top raw score so the highest row is `100`. Empty result set → no scoring needed.
- All scores collapse to integer at the end (per FTS-008's "integer in [0, 100]" requirement).

**Why not "true" TF-IDF:** true IDF requires corpus-wide doc-frequency, which means another DB pass per query. For our scale (10k publications per catalog), the per-result-set approximation is fast enough and ranks the typical "best match first" expectation correctly. When this approximation hurts (very large result-sets where a rare token in the top-1 row appears in many other matched rows), we can revisit in B3 with a real index.

### D6 — `x-openregister.searchConfig` schema-block shape

Lives on each schema's `x-openregister` block (ADR-031 — declarative business logic on schemas), in the same JSON file as the bundled schemas (`lib/Settings/publication_register.json` for OpenCatalogi's defaults; deployer-modified schemas as appropriate).

```json
{
  "type": "object",
  "x-openregister": {
    "searchConfig": {
      "fields": {
        "titel":         { "weight": 5 },
        "samenvatting":  { "weight": 3 },
        "beschrijving":  { "weight": 2 },
        "thema":         { "weight": 1 },
        "kenmerk":       { "weight": 1 }
      }
    },
    "authorization": { "read": [ ... ] }
  },
  "properties": { ... }
}
```

**Validation:** any `fields` entry whose key does not appear in the schema's `properties` is logged at WARN-level and ignored (no hard fail — schemas evolve; orphan entries shouldn't block a deploy). Any property in `searchConfig` whose corresponding schema property is not `type: string` is similarly ignored (numeric / object / array properties are not text-searchable).

**Future-extensibility:** the per-field object (currently just `{weight}`) leaves room for `analyzer`, `stopwords`, `tokenizer` hints in B3+ without breaking changes — additive only.

### D7 — Solr / Elasticsearch bypass detection (FTS-011)

At the very top of `FulltextSearchService::isEngineEngaged($query)`:

```php
if ($this->objectService->isSolrAvailable() === true) {
    return false;   // engine yields; OR handles _search natively via IndexService
}
```

The `ObjectService::isSolrAvailable()` method already exists in OR (`lib/Service/ObjectService.php:2497`). The engine's bypass is therefore zero-config — deployers who enable Solr in OR's settings get Lucene-style parsing automatically without touching OC config.

**One caveat:** when Solr is active but the deployer has manually disabled it for OC search (hypothetical future config flag), the engine should still engage. A future config-flag override on the OC side can layer ABOVE this auto-detect — but it's a B3 concern; for now, Solr-available → engine yields.

### D8 — Integration seam in `PublicationQueryService::buildCatalogSearchQuery`

Single new branch, ~10 lines:

```php
public function buildCatalogSearchQuery(...): array
{
    $query = $this->normaliseRequestParams($requestParams);

    if ($this->ftsService->isEngineEngaged($query) === true) {
        // Hand off to the FTS engine — it returns the same shape as
        // searchObjectsPaginated, so the caller (PublicationsController::index)
        // is unchanged.
        return $this->ftsService->search($query, $catalog, $objectService);
    }

    // Existing path (unchanged):
    return $this->buildLegacyCatalogQuery($query, $catalog, $objectService);
}
```

The engine returns the same envelope shape (`{results: [...], total: N, page, pages, limit, offset, @self: {...}, @catalog: {...}}`) so the controller's `isObjectPublic()` post-filter and the response serialisation run unmodified. PUB-FTS-004 — anonymous filter ordering — is enforced by the controller continuing to run `isObjectPublic()` on each row in the engine's result set BEFORE pagination is applied. The engine over-fetches by a tunable factor (default 1.5×) to cover anonymous rejects.

### D9 — Error handling and edge cases

| Case | Behaviour |
|---|---|
| Empty `_search` (whitespace-only) | Engine yields → bare `_search` path (which itself ignores empty input). |
| `_order[@self.relevance]=desc` without `_search` | Controller returns `400` per FTS-009 (validation in `buildCatalogSearchQuery` before engine call). |
| Schema with no `searchConfig` | All string properties get weight `1.0` (FTS-007). Scoring still works. |
| Malformed query (e.g., unclosed `"`) | Tokenizer treats the unclosed-quote string as a single phrase up to end-of-input — best-effort recovery. WARN logged. |
| Wildcard with empty prefix (`_search=*`) | Treated as a literal `*` per FTS-004's "single-term-boundary" clause. |
| Hundreds of OR-branches (DoS attempt) | Engine caps at 8 branches; extras logged + ignored. Configurable via `OC\\PrefixedConstants::FTS_MAX_OR_BRANCHES`. |
| All branches return zero rows | Engine returns empty `results`, total: 0; `@self.relevance` field is absent on missing rows (trivially). |
| Single-term query with the engine engaged | Engine still runs (one OR-branch, one term, one sub-query), but the scoring path collapses to "every row got a single match" — relevance is uniform 100 across all. Acceptable cost (~5ms overhead). |

## Risks / Trade-offs

**Risk 1 — Per-result-set IDF is not real TF-IDF**

The penalty approximates corpus-frequency by looking only at the merged result set. For a result set of 5 rows where a rare-globally token happens to appear in 4 of them, the engine ranks it as a common token. Verdict: acceptable for B2; revisit in B3 when we have query-pattern data.

**Risk 2 — Sequential OR-branch issuance**

PHP can't naturally issue 3 parallel DB queries without a library. For typical queries the 2-3x sequential cost is dominated by network/I/O, but a worst-case 5-branch OR-query could hit the 800ms p95 target. **Mitigation:** the 8-branch hard cap (D9) + the 1.5× over-fetch limit. Real parallelisation is a B3 follow-up if telemetry demands it.

**Risk 3 — `searchConfig` migration burden**

Existing schemas without `searchConfig` get uniform-weight scoring (per FTS-007). That works but means existing tenants don't see the "titel matters more" effect until they migrate. **Mitigation:** ship `searchConfig` on the bundled `publication` schema in this change, with `publication_register.json` updated. Tenants get the value immediately on the next OC upgrade.

**Risk 4 — Anonymous filter pagination gaps**

The engine merges + scores BEFORE the controller's `isObjectPublic()` filter. If the engine returns exactly `_limit` rows and `isObjectPublic` drops 3 of them, the response has 7 rows instead of 10. **Mitigation:** the engine over-fetches by 1.5× and the controller re-fills from the candidate pool. If the pool is exhausted, the response is short-but-correct (gap is acceptable; pagination cursors stay valid).

**Risk 5 — Solr config drift**

If a deployer enables Solr in OR without re-indexing, the engine yields but Solr returns stale/empty results. **Mitigation:** out of scope for this engine — that's a Solr-deployment hygiene concern. The engine merely yields when `isSolrAvailable()` returns true; what Solr does is OR's territory.

**Risk 6 — Backwards compatibility regressions**

Any existing apiv2 query that happens to contain uppercase `OR` / `AND` / `*` characters in the search string would now be parsed instead of treated as literal. **Mitigation:** FTS-012's backwards-compat requirement guarantees that the FTS engine produces identical results to the bare ILIKE for queries that contain none of those constructs. The change is opt-in via syntax — consumers using vanilla terms see no change.

## B3 hooks — where future features will plug in

The B2 architecture explicitly anticipates the B3 features without rewriting the engine:

| B3 feature | Where it plugs into B2 |
|---|---|
| **Fuzzy tilde** (`term~`, `term~1`) | Tokenizer recognises a trailing `~` (with optional integer); emits a `fuzzy` AST node. Engine translates `fuzzy` nodes into a parallel `?_fuzzy=true` sub-query on `_name` only (per OR's existing mechanism) and merges trigram-similarity results alongside the ILIKE candidates. Scorer adds `@self.relevance` (from OR's fuzzy mode) as an additional input alongside the field-weight × match-count. |
| **Boosting** (`term^N`) | Tokenizer recognises a trailing `^<digits>`; attaches a `boost` multiplier to the AST node. Scorer's `raw_score` formula multiplies the term's contribution by the boost. No DB-side change. |
| **Proximity matching** (`"a b"~5`) | Phrase node grows an optional `slop` integer. The engine's sub-query degrades to substring-containment of both words (no positional index without Solr); WARNs that proximity is only enforced exactly when Solr is active. Without Solr, the slop is silently treated as "both words present in same field" — graceful degradation. |
| **Grouped queries** (`(a OR b) AND c`) | Tokenizer grows a recursive `group` AST node; the OR-branch flat list becomes a tree. Engine's sub-query strategy becomes recursive — each leaf-level OR-set issues a sub-query, results are intersected at AND-nodes. Requires reworking D3+D4 algorithmically but the same `ObjectService->searchObjects` interface stays. |
| **Authority-bias / age-decay** | Scorer takes an optional `bias_fn(row)` callable that returns a modifier. Plugged in by config (deployer adds `"bias": "age-decay"` to `searchConfig`). The scoring formula's `raw_score` becomes `raw_score × bias_fn(row)`. No parser change. |
| **Real corpus-wide IDF** | Replace the per-result-set IDF approximation with a pre-computed IDF table maintained by a background job. Engine reads from the table at scoring time. Schema change (new `oc_fts_token_idf` table) but no parser change. |

The two-level grammar + the single integration seam in `PublicationQueryService` keep all of these additions confined to the engine. None of them require touching `PublicationsController`, OR, or the `_rbac: true` contract.

## Seed Data

Per ADR-001, seed data lives in `lib/Settings/{app}_register.json` as `components.objects[]` with the `@self` envelope. This change does NOT introduce a new schema — it adds a `x-openregister.searchConfig` block to the **existing** `publication` schema. The existing 3-5 seed publications in `publication_register.json` already cover the schema's content surface.

For testing the FTS engine specifically, this change adds:

1. **`searchConfig` block on the `publication` schema** in `lib/Settings/publication_register.json`:

```json
{
  "components": {
    "schemas": {
      "publication": {
        "x-openregister": {
          "searchConfig": {
            "fields": {
              "title":       { "weight": 5 },
              "summary":     { "weight": 3 },
              "description": { "weight": 2 }
            }
          }
        }
      }
    }
  }
}
```

2. **Two additional seed publications** showcasing the matching modes. Both fit ADR-001's "general organisation data" rule (a municipality and a consultancy):

| `@self.slug` | `title` | `summary` | `description` |
|---|---|---|---|
| `seed-municipality-convenant` | "Convenant gemeente Den Haag" | "Samenwerkingsovereenkomst tussen gemeente Den Haag en regionale partners" | "Convenant voor het verbeteren van de dienstverlening aan inwoners van Den Haag, ondertekend op 2026-03-15 door wethouder Jansen namens het college van burgemeester en wethouders. Looptijd vier jaar." |
| `seed-consultancy-rapport` | "Onderzoeksrapport Conduction B.V." | "Conduction B.V. onderzoek naar digitale toegankelijkheid voor het ministerie" | "Conduction B.V. heeft in opdracht van het Ministerie van BZK onderzoek gedaan naar digitale toegankelijkheid van overheidsapplicaties. Het rapport bevat aanbevelingen voor het verbeteren van WCAG 2.2 conformiteit." |

Notes on the seed objects:

- The municipality object exercises Den Haag — appears in `title` (×5 weight), `summary` (×3), and `description` (×2). A query `_search=Den Haag` should return this object with high relevance (multiple matches in high-weight fields).
- The consultancy object exercises "Conduction" — appears in `title`, `summary` (twice), and `description`. A query `_search=Conduction` should rank it high.
- A query `_search="Den Haag" Conduction` (phrase + term, implicit AND) should return EITHER (under FTS-002's implicit AND) only rows containing both, i.e., neither of these two seeds — demonstrating the AND semantics.
- A query `_search=Den Haag OR Conduction` should return both seeds, ranked by relevance.

Both objects MUST be added with `@self.slug` matching the keys above (idempotent re-import per ADR-001). Both use the bundled `publication` schema's existing properties (no new fields).

3. **`searchConfig` block matches existing field names** — the `publication` schema in `lib/Settings/publication_register.json` ships with `title`, `summary`, `description`, `organization`, `themes`. Of those, the three text fields above are configured; `organization` and `themes` get default weight `1.0` via FTS-007.

The `_registers.json` import is idempotent — re-running `SettingsService::autoConfigure()` skips existing objects matched by `@self.slug` (per ADR-001's idempotency rule). Deployers upgrading from a pre-FTS version receive both the `searchConfig` block AND the two new seed publications on first repair-step run.
