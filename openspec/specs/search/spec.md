---
or_dep: zoeken-filteren
audit_ref: .claude/audit-2026-05-03/02-spec-rewrite.md
---

**Status**: in-progress
**Scope**: opencatalogi
**OpenSpec changes**:

- [add-public-fulltext-search](../../changes/add-public-fulltext-search/) — in-progress

# Search

> **NEEDS-REWRITE notice:** This spec was rewritten as part of
> `opencatalogi-adopt-or-abstractions` (Phase 7). The bespoke query
> parsing, faceting, ranking, and filter-generation logic described in
> the previous version of this spec is replaced by a citation of OR's
> `zoeken-filteren` capability. opencatalogi's search surface is now a
> thin orchestrator on top of `zoeken-filteren`. See the REMOVED section.
>
> Upstream dependency: OR `zoeken-filteren` capability.

## Purpose

@e2e exclude OR-abstraction-consumer spec — query parsing/faceting/ranking delegated to OpenRegister's `zoeken-filteren`, verified by PHPUnit/vitest/Newman; the search UI is separately real-UI covered.

opencatalogi's search capability aggregates publications from local
catalogs and federated remote OpenCatalogi instances into a single
search interface. After Phase 7, the query parsing, faceting, and
ranking are owned by OR's `zoeken-filteren` capability; opencatalogi
is responsible only for:

1. Fanning out parallel `zoeken-filteren` calls across all configured
   catalog contexts (local + federated).
2. Merging the ranked result sets in a documented, stable order.
3. Merging facet buckets across sources by `_id`.

opencatalogi MUST NOT re-implement query string parsing, bracket-notation
parsing, facet generation, score computation, or pagination arithmetic.
Those are owned upstream by OR `zoeken-filteren`.
## Requirements
### Requirement: single-catalog search delegates to OR `zoeken-filteren` (SCH-OR-001)

When a user issues a search query within a single catalog, opencatalogi MUST delegate the full query — including `_search`, `_order`, `_limit`,
`_page`, `_offset`, `_filters`, and any `_facetable` / `_aggregate` flags
— to OR's `zoeken-filteren` API unmodified. opencatalogi MUST NOT alter
the query, re-parse bracket notation, or inject custom filter parameters.

> @e2e exclude Backend query-passthrough contract (full query delegated to OR `zoeken-filteren` unmodified; no local filter transformation or score adjustment) — a server-side delegation with no UI surface; verified by PHPUnit/Newman asserting the constructed call and unchanged response. The search UI itself is already real-UI covered under search::run-a-publication-search.

#### Scenario: single-catalog search passes through

- **WHEN** a user issues a search query within a single catalog,
- **THEN** opencatalogi constructs a single `zoeken-filteren` call for
  that catalog context,
- **AND** returns the OR response to the caller unchanged,
- **AND** does NOT apply local filter transformation or score adjustment.

### Requirement: federated search is a thin orchestrator (SCH-OR-002)

When a user issues a federated search across N catalogs or remote directories, opencatalogi MUST act as a thin orchestrator that:

1. Makes N parallel `zoeken-filteren` calls (one per catalog context
   / remote endpoint) using async HTTP.
2. Merges the result arrays in a stable, documented order (descending
   `_score`; ties broken by source order declared in the active listings).
3. Merges facet buckets by `_id`; counts are summed across sources.
4. Returns a single paginated response whose `total` is the sum of all
   source totals.

opencatalogi MUST NOT alter individual ranking scores, re-rank within
a source's result set, or apply cross-source deduplication beyond
merging on `_id` equality.

> @e2e exclude Backend federation-orchestration contract (N parallel `zoeken-filteren` calls; stable descending-_score merge; facet buckets summed by _id; total = sum of source totals; no re-ranking) — server-side merge math with no UI surface; verified by PHPUnit/Newman over the orchestrator with seeded multi-source responses. The federated search UI is already real-UI covered under search::run-a-publication-search and ::toggle-a-facet-from-the-ui.

#### Scenario: cross-catalog search merges OR results

- **WHEN** a user issues a federated search across N catalogs,
- **THEN** opencatalogi makes N parallel `zoeken-filteren` calls,
- **AND** merges the results in stable descending `_score` order,
- **AND** does NOT alter individual ranking scores.

#### Scenario: facet merging

- **GIVEN** catalog A returns `{theme: [{_id: "milieu", count: 5}]}`
  and catalog B returns `{theme: [{_id: "milieu", count: 3}, {_id: "energie", count: 2}]}`,
- **WHEN** the federated results are merged,
- **THEN** the response contains `{theme: [{_id: "milieu", count: 8}, {_id: "energie", count: 2}]}`.

### Requirement: internal search endpoint delegates to `zoeken-filteren` (SCH-OR-003)

The `SearchController::index` (`GET /apps/opencatalogi/api/search`) MUST delegate to OR's `zoeken-filteren`. Two facets of the prior wording are superseded by this change and are hereby MODIFIED:

1. **Auth posture:** the endpoint is no longer authenticated-only — anonymous callers reach it (per `SCH-PFTS-001`). The controller carries `#[PublicPage]` + `#[NoCSRFRequired]`. `SCH-PFTS-004`'s post-scoring `isObjectPublic()` filter provides the anonymous visibility guarantee that the old authenticated-only posture used to provide implicitly.
2. **Delegation scope:** the controller MUST delegate to `zoeken-filteren` across **both** the `publication` **and** `document` schemas, not the `publications` context alone (per `SCH-PFTS-002` / `SCH-PFTS-006`). It MUST NOT call a bespoke `buildSearchQuery()` or `searchObjectsPaginated()` method in opencatalogi itself.

Everything else about SCH-OR-003 (the "no bespoke query layer" prohibition, the passthrough contract) is preserved.

#### Scenario: internal endpoint delegates (updated for public + multi-schema)

- **GIVEN** any request (authenticated or anonymous) to `GET /apps/opencatalogi/api/search`,
- **WHEN** `SearchController::index` runs,
- **THEN** it calls `zoeken-filteren` across both the `publication` and `document` schemas with the caller's query parameters,
- **AND** returns the merged + visibility-filtered response.

### Requirement: search frontend store calls the federation endpoint (SCH-OR-004)

The frontend search store MUST query publications via the federation
endpoint `GET /api/federation/publications`, building query parameters
from the current search term, pagination, active filters, ordering, and
the federation flags `_facetable=true`, `_aggregate=true`. The federation
endpoint in turn calls `zoeken-filteren` per catalog context. The frontend
store MUST NOT call OR's `zoeken-filteren` endpoint directly.

> @e2e exclude Frontend network-target contract (search store queries `/api/federation/publications` with the term/pagination/filters/order + `_facetable`/`_aggregate` flags, never calling `zoeken-filteren` directly) — the assertion is the request target/params, not a distinct browsable surface; verified by vitest mocking the federation endpoint and asserting the URL + params. The search UI is already real-UI covered under search::run-a-publication-search.

#### Scenario: frontend search runs through the federation endpoint

- **GIVEN** a search term and optional filters,
- **WHEN** the search store runs a search,
- **THEN** it sends the request to `/api/federation/publications`,
- **AND** does NOT call `zoeken-filteren` directly from the frontend.

### Requirement: facet discovery and active-facet query building (SCH-OR-005)

The search frontend MUST provide facet discovery (`discoverFacetableFields()`)
and active-facet encoding (`buildFacetQuery()`). These translate the user's
enabled facets into the `_facetable` / `_aggregate` parameters on the
`zoeken-filteren` call — they do NOT compute facet buckets locally.

#### Scenario: discover facetable fields

- **GIVEN** the search view loads,
- **WHEN** `discoverFacetableFields()` runs,
- **THEN** the facetable-fields map is populated from the OR response's
  `facetable` metadata.

### Requirement: search UI components (SCH-OR-006)

opencatalogi MUST provide a `SearchSideBar` (facet filter controls),
a `SearchResults` component (result list), and a `FacetComponent`
(individual facet toggle). These components render the OR `zoeken-filteren`
response; they do NOT contain local filter computation.

#### Scenario: search UI renders OR results without local computation

- **GIVEN** a `zoeken-filteren` response is returned to the search view,
- **WHEN** `SearchSideBar`, `SearchResults`, and `FacetComponent` render,
- **THEN** they MUST display the OR-provided results and facets,
- **AND** they MUST NOT compute filters, facet buckets, or scores locally.

### Requirement: Public full-text search endpoint absorbs the admin-only search (SCH-PFTS-001)

The existing internal endpoint `GET /apps/opencatalogi/api/search` (currently served by `SearchController::index` and admin-only — returns 401 for anonymous callers) MUST become the canonical public full-text search endpoint. The route MUST be reachable without authentication (annotated `#[PublicPage]` + `#[NoCSRFRequired]`), and MUST NOT require a session user. A new endpoint path MUST NOT be introduced for this purpose.

The companion `GET /publications` endpoint MUST remain unchanged: its behaviour, response shape, and admission rules are out of scope for this change.

#### Scenario: anonymous caller reaches the public search endpoint

- **GIVEN** no authenticated session,
- **WHEN** a `GET /apps/opencatalogi/api/search?q=jaarverslag` request is issued,
- **THEN** the endpoint MUST respond with HTTP 200 and a search-result envelope,
- **AND** MUST NOT respond with HTTP 401.

#### Scenario: the publications endpoint is untouched

- **GIVEN** this change has been implemented,
- **WHEN** the existing `GET /publications` behaviour is exercised against the previous contract,
- **THEN** every previously-passing assertion against `/publications` MUST still pass,
- **AND** no request shape, response shape, or admission rule of `/publications` MUST have changed.

### Requirement: Search results are a flat envelope with `@self.schema` as the row discriminator (SCH-PFTS-002)

`GET /apps/opencatalogi/api/search` MUST return a single flat result array whose rows MAY mix object types. Each row MUST carry an `@self.schema` field whose value identifies the row's schema slug (`publication`, `document`, …); callers use this field as the discriminator to render row-specific UI. Rows MUST NOT be grouped into per-type sub-arrays in the response envelope.

This shape is consistent with the existing `/publications` envelope's use of `@self` metadata; clients MUST be able to switch rendering on `@self.schema` without inspecting any other field.

#### Scenario: mixed-type rows are returned in a single flat array

- **GIVEN** a search query matching both publications and documents,
- **WHEN** the response is inspected,
- **THEN** the result rows MUST live in a single flat array,
- **AND** each row MUST carry `@self.schema` set to the row's schema slug,
- **AND** the response MUST NOT contain separate `publications` / `documents` sub-arrays.

### Requirement: Document rows include an embedded publication summary (SCH-PFTS-003)

Every document row in the result envelope MUST include an embedded `publication` object with at least the fields `{ id, slug, title }` of the publication the document is linked to. Field name is English (`title`, not Dutch `titel`) to match the publication schema's canonical property names in the bundled publication register and to satisfy ADR-001's "do not hardcode Dutch field names as primary" rule. This enables the frontend to render a document card with a "from publication X" backlink without a second API roundtrip. A document with no linked publication MUST NOT appear in the public result set.

#### Scenario: document row carries publication summary

- **GIVEN** a document linked to a publication titled "Jaarverslag 2024" (slug `jaarverslag-2024`, id `pub-001`),
- **WHEN** that document matches the search query,
- **THEN** its result row MUST include `publication: { id: "pub-001", slug: "jaarverslag-2024", title: "Jaarverslag 2024" }`.

#### Scenario: document with no publication link is suppressed

- **GIVEN** a document object that has no linked publication,
- **WHEN** the public search assembly runs,
- **THEN** the document MUST NOT appear in the result rows returned to anonymous callers.

### Requirement: Anonymous visibility filter runs AFTER scoring and merging (SCH-PFTS-004)

For anonymous callers, the public search endpoint MUST apply the same `isObjectPublic()` filter that `/publications` already uses, with identical ordering semantics: the filter runs AFTER the underlying search (scoring and merge) has produced the candidate result set, NOT as a pre-filter on the query. This guarantees the public result set is a strict subset of what scoring produced, and that ranking decisions are made on the full corpus before visibility is enforced.

For documents, visibility MUST also be transitively gated: a document is visible to anonymous callers only if its linked publication itself satisfies `isObjectPublic()`.

#### Scenario: anonymous filter strips depublished publications post-scoring

- **GIVEN** anonymous request matching publications A (live) and B (depublicatiedatum in the past),
- **WHEN** the public search runs,
- **THEN** the candidate set initially contains both A and B,
- **AND** the visibility filter is applied AFTER scoring/merge,
- **AND** the response contains A only.

#### Scenario: document visibility is transitively gated

- **GIVEN** a document D linked to a publication P whose `depublicatiedatum` is in the past,
- **WHEN** an anonymous caller searches for content matching D,
- **THEN** D MUST NOT appear in the response.

### Requirement: A dedicated `document` schema is bundled in the publication register (SCH-PFTS-005)

OpenCatalogi MUST add a new `document` schema as a **bundled** schema inside `lib/Settings/publication_register.json` (registered alongside `publication`, `catalog`, `organization`, …). This schema MUST NOT be supplied by a deployer-side fragment; bundling guarantees the schema is present on every install so the public search endpoint can rely on its presence.

The schema MUST be discoverable through OR's standard schema-listing APIs (i.e. it MUST carry its own `@self.schema` identity in returned objects), MUST be `searchable: true`, and MUST be authorized so that anonymous read access is allowed for documents whose linked publication satisfies `isObjectPublic()` (matching publication's authorization shape).

#### Scenario: document schema ships with the app

- **GIVEN** a fresh OpenCatalogi install,
- **WHEN** the publication register is loaded,
- **THEN** a `document` schema MUST be present under `components.schemas.document` in `lib/Settings/publication_register.json`,
- **AND** the schema MUST carry `searchable: true`,
- **AND** the magic mapper MUST auto-allocate a dedicated table for the schema on first install (`oc_openregister_table_publication_document`, per the magic-mapper's `oc_openregister_table_{register}_{schema}` convention — no manual `configuration.schemas` wiring needed, consistent with how the other bundled schemas — `publication`, `catalog`, `page`, `menu`, `theme`, `glossary`, `listing`, `organization`, `usageCounter` — are wired today).

#### Scenario: document objects carry their own `@self.schema`

- **GIVEN** a stored document object,
- **WHEN** the public search endpoint returns that object,
- **THEN** its row MUST carry `@self.schema = "document"`.

### Requirement: OpenCatalogi consumes OR for search and (Path A) content extraction (SCH-PFTS-006)

OpenCatalogi MUST NOT re-implement search query parsing, faceting, ranking, or document content extraction. The public search endpoint orchestrates: it calls OR's `zoeken-filteren` (per ADR-022 and consistent with `SCH-OR-001` / `SCH-OR-002`) across the publication and document schemas, applies the anonymous visibility filter post-merge (per `SCH-PFTS-004`), shapes the flat envelope (per `SCH-PFTS-002` / `SCH-PFTS-003`), and returns it.

When document content indexing is enabled (Path A — pending Ruben's confirmation per the proposal's "Pending decisions"), OpenCatalogi MUST consume OR's `TextExtractionService` + `FileHandler` + Solr-pipeline; OpenCatalogi MUST NOT add its own extraction or indexing pipeline. When Path A is not yet enabled (Path B), document rows MUST surface metadata-only matches (filename, MIME, linked-publication fields) and content-search MUST be the subject of a separate follow-up change.

#### Scenario: search query parsing is delegated to OR

- **GIVEN** a search request to `/apps/opencatalogi/api/search`,
- **WHEN** the controller runs,
- **THEN** it MUST forward the query to OR's `zoeken-filteren`,
- **AND** MUST NOT re-parse bracket notation, recompute scores, or build a bespoke search filter set.

#### Scenario: document content extraction (Path A) is delegated to OR

- **GIVEN** Path A is enabled,
- **WHEN** a document is indexed for content search,
- **THEN** the extraction pipeline MUST be OR's `TextExtractionService` + `FileHandler` + Solr-pipeline,
- **AND** OpenCatalogi MUST NOT add a parallel extraction pipeline.

### Requirement: Search matches across all schema properties, not only pre-configured ones (SCH-PFTS-007)

Matches on the public search endpoint MUST cover **every searchable property** of the target schemas (`publication`, `document`), plus the standard `@self` metadata fields OR's `zoeken-filteren` already surfaces (`_name`, `_description`, `_summary`, timestamps). Callers MUST NOT need to enumerate which properties are searched, and OpenCatalogi MUST NOT filter the OR-side search surface down to a subset of properties. This makes the behaviour explicit rather than leaving it implicit-in-`zoeken-filteren`-delegation, so a reviewer inspecting only this spec can confirm the WOO-506 requirement that the endpoint zoekt "over alle properties en metadata — niet alleen de schema-properties die wij hebben ingesteld".

New properties added to a schema in future changes (e.g. a `kenmerk` field added to `document` in a later B3) MUST be automatically covered by search without any modification to `SearchController::index` or the assembly helper — they inherit `searchable: true` from the schema-level flag.

#### Scenario: a new schema property is searchable without controller changes

- **GIVEN** the `document` schema gains a new string property `kenmerk` in a future change,
- **AND** the schema keeps `searchable: true`,
- **WHEN** a search query matches the value of `kenmerk` on a document object,
- **THEN** that document MUST appear in the result set,
- **AND** neither `SearchController::index` nor the assembly helper MUST have been modified to expose the new property to search.

#### Scenario: search covers metadata fields OR already surfaces

- **GIVEN** a document whose `@self` metadata contains a match for the search query but whose declared schema properties do not,
- **WHEN** the public search runs,
- **THEN** the document MUST appear in the result set (matched via OR's `zoeken-filteren` on the metadata fields it already covers).

## REMOVED Requirements

The following requirements described bespoke implementations that OR's
`zoeken-filteren` capability now owns. They are retained for traceability;
implementation MUST NOT re-introduce them.

| ID | Title | Reason removed |
|----|-------|----------------|
| SCH-010 | Create MySQL/MongoDB-compatible search filters and sort parameters | REMOVED — re-implements OR's `zoeken-filteren` query layer; consume OR per ADR-022. OpenRegister's `ObjectService::buildSearchQuery()` is the authoritative implementation. |
| SCH-013 | Generate dual MySQL and MongoDB filter/sort parameters | REMOVED — same rationale as SCH-010. |
| SCH-014 | Parse complex nested query strings with bracket notation | REMOVED — bracket notation parsing (`_order[title]=asc`, `themes[or]=1,2,3`) is owned by OR `zoeken-filteren`; opencatalogi MUST NOT re-implement or duplicate it. |
| SCH-015 | Unset underscore-prefixed special parameters before passing to database layer | REMOVED — owned by OR `zoeken-filteren`; opencatalogi passes the raw query to OR unmodified. |

SCH-001 through SCH-009, SCH-011, SCH-012, SCH-016 through SCH-020 are superseded
by SCH-OR-001 through SCH-OR-006. The observable behaviours they describe are
preserved; the implementation path now routes through `zoeken-filteren`.

## Breaking Changes

| Breaking change | Old behaviour | New behaviour |
|---|---|---|
| Local `buildSearchQuery()` removed | opencatalogi applied bracket-notation parsing before calling OR | Raw query parameters forwarded to OR `zoeken-filteren`; OR parses them. Operators or clients relying on opencatalogi-side parsing must verify compatibility with the OR implementation. |
| `ElasticSearch` integration removed | SCH-006 noted "not implemented"; any future bespoke ElasticSearch integration is forbidden | Search goes through OR `zoeken-filteren`; OR owns the storage backend choice. |

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/apps/opencatalogi/api/search` | Public full-text search — delegates to OR `zoeken-filteren` across the `publication` + `document` schemas; anonymous-reachable with post-scoring `isObjectPublic()` filter (per `add-public-fulltext-search`). Prior posture: authenticated + publications-only. |

## References

- OR `zoeken-filteren` capability (upstream dependency)
- `.claude/audit-2026-05-03/02-spec-rewrite.md` (Stream 2 MISSING-OR-DEP rationale)
- `openspec/changes/opencatalogi-adopt-or-abstractions/` (Phase 7 implementation change)
- `openspec/specs/federation/spec.md` (federated search orchestration)
- ADR-022 — Apps consume OR abstractions
