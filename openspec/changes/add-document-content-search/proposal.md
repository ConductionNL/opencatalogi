---
kind: mixed
depends_on:
  - openregister:expose-content-search-in-object-service
---

# Proposal: add-document-content-search

## Summary

Extend the public full-text search endpoint (`GET /apps/opencatalogi/api/search`, shipped in [add-public-fulltext-search](../add-public-fulltext-search/)) so it also matches on the **extracted body text** of document files — PDF/DOCX/XLSX/EML/text — not only on metadata (filename, title, summary, MIME). The envelope shape, `@self.schema` discriminator, embedded `publication` summary on document rows, and post-scoring `isObjectPublic()` visibility filter defined by WOO-506 all remain unchanged; this change only widens what a document row can match on.

Companion `GET /publications` (endpoint 1) is explicitly out of scope — its behaviour remains "publication-scoped, no document body indexed".

## Motivation

Ruben's 2026-07-16 architecture decision on [WOO-517](https://conduction.atlassian.net/browse/WOO-517):

> "Solr is deprecated hè, dus we doen sowieso geen Solr. Maar ja, leunen op OR. OR heeft al een pipeline voor text extraction, dus daar hoeven we niks meer mee te doen als het goed is."

OR already ships everything WOO-517 needs:

- **Extractor stack** — `TextExtractionService` + per-format handlers (`PdfExtractor`, `WordExtractor`, `SpreadsheetExtractor`, `EmlParser`, plaintext `text/*`) at `lib/Service/TextExtractionService.php`, invoked lazily via background jobs (`FileTextExtractionJob`, `ObjectTextExtractionJob`, `CronFileTextExtractionJob`) whenever a file object is written.
- **Indexed store** — extracted chunks land in `openregister_chunks.text_content` (TEXT column) with a PostgreSQL functional GIN on `to_tsvector('simple', text_content)` (migration `Version1Date20260706101000`, shipped under the OR `hybrid-document-search` change).
- **Keyword-search method** — `ChunkMapper::searchByKeyword()` with `ts_rank` scoring returns hits with `source_type` + `source_id`, ready to be joined back to their owning object.

What OR is missing is the last wire: `ObjectService::searchObjectsPaginated()` never touches `openregister_chunks`. `ChunkMapper::searchByKeyword()` is currently reachable only through `FileSearchController`, which is admin-only and not the surface WOO-506 consumes.

## Scope

**In scope (this change, opencatalogi-side):**

- Add an opt-in `_content` search flag to `GET /apps/opencatalogi/api/search` (default OFF) that widens matching to include document body text.
- Update `PublicationQueryService::assemblePublicSearchResults()` so when `_content=true` is set, it enriches the multi-schema OR search with content matches (documents whose body text matches). The union of metadata-matches and content-matches is deduplicated on `@self.id`, ordered by relevance, and passed through the same `isObjectPublic()` + transitive-publication-visibility filters SCH-PFTS-004 already defines.
- Spec deltas under `openspec/changes/add-document-content-search/specs/search/`:
    - New `SCH-PFTS-CONTENT-001` — the `_content` opt-in flag.
    - New `SCH-PFTS-CONTENT-002` — content-match rows still MUST be documents (never chunks) with the same envelope + embedded `publication` summary.
    - New `SCH-PFTS-CONTENT-003` — content matches inherit the same anonymous visibility semantics as metadata matches (transitive publication visibility, no draft/depublished leakage).
    - MODIFIED `SCH-PFTS-002` — clarify the envelope shape is unchanged: a content-match document row is indistinguishable in shape from a metadata-match document row.
- E2E test that seeds a publication + a document with a PDF/DOCX attachment carrying a distinctive body phrase, waits for OR's extraction job to run, and asserts the phrase surfaces the document via `?_search=<phrase>&_content=true`.
- No frontend change (endpoint contract is compatible with the existing consumer).

**Out of scope (belongs on openregister-side, filed as a prerequisite subtask):**

- Wiring `ChunkMapper::searchByKeyword()` into `ObjectService::searchObjectsPaginated()` as a new `_content_search` (or similar) query flag. OR's public API contract change; needs its own OpenSpec change in `openregister/openspec/changes/` and its own Jira ticket. **This change (WOO-517) is a HARD dependency on that OR change landing first** — see `depends_on` frontmatter.

**Deliberately deferred (own follow-up):**

- MariaDB parity for content search. `openregister_chunks`' GIN index is PostgreSQL-only (`to_tsvector`). On MariaDB, `ChunkMapper::searchByKeyword()` falls back to LIKE-scan without `ts_rank`. Design + wiring stay the same; only ranking degrades. Out of scope here — if MariaDB deploys need equal-quality content ranking, that's a separate change on OR's side (equivalent of a MariaDB `FULLTEXT` index or trigram alternative).
- Per-schema card variants on the frontend. Not touched.

## Pending decisions

None. Ruben's 2026-07-16 answer settles the architecture question; scope of this change follows directly.

## Cross-refs

- **Parent Jira:** [WOO-517](https://conduction.atlassian.net/browse/WOO-517) (split off from WOO-506 on 2026-07-03).
- **WOO-506 (this change's foundation):** `openspec/changes/add-public-fulltext-search/` — spec + endpoint + envelope + document schema all shipped there.
- **OR prerequisite:** to be filed on `Conduction/openregister` — expose `_content_search` (working name) on `ObjectService::searchObjectsPaginated()` routing to `ChunkMapper::searchByKeyword()`.
- **OR pipeline files** (existing, no change here):
    - `openregister/lib/Service/TextExtractionService.php`
    - `openregister/lib/Db/ChunkMapper.php` (has `searchByKeyword()`)
    - `openregister/lib/Migration/Version1Date20260706101000.php` (`text_content` + tsvector GIN)
- **OR spec that shipped the pipeline:** `openregister/openspec/changes/hybrid-document-search/` (already merged).
