# Discovery: add-document-content-search

**Time-boxed research on the OpenRegister text-extraction + chunk-search pipeline, 2026-07-16.** Confirmed OR ships everything needed except one wire between `ObjectService::searchObjectsPaginated()` and `ChunkMapper::searchByKeyword()`. This document captures the artefacts found so the proposal + design + spec deltas + tasks can commit to that plan.

## Question

Can OpenCatalogi's public full-text search endpoint (WOO-506 `GET /apps/opencatalogi/api/search`) be extended with body-text matching by consuming an existing OpenRegister pipeline, or does WOO-517 require a new indexing stack?

Ruben's answer on 2026-07-16 (verbatim):

> "Solr is deprecated hè, dus we doen sowieso geen Solr. Maar ja, leunen op OR. OR heeft al een pipeline voor text extraction, dus daar hoeven we niks meer mee te doen als het goed is."

This discovery verifies the "als het goed is" — i.e. how ready the OR pipeline actually is, what shape its output takes, and how OpenCatalogi would consume it.

## Approach

Read-only exploration of `~/woo506-test/openregister/` — no code changes. Focus areas:

1. `TextExtractionService` + per-format handlers — what MIME types are covered, extraction trigger model.
2. Where extracted text lives in the DB — column, table, index.
3. Whether any search entry-point already consumes the chunk store.
4. Anonymous/RBAC visibility semantics on both extraction jobs and any existing chunk-search entry-point.
5. Sizing for the OpenCatalogi-side change.
6. Existing OpenSpec changes touching this area (avoid duplication).

## Findings

### Extraction stack (present, complete)

- **`openregister/lib/Service/TextExtractionService.php`** (~1900 LOC) is the orchestrator. It sniffs MIME type and delegates to per-format handlers:
  - `PdfExtractor` (Smalot pdfparser)
  - `WordExtractor` (PhpOffice PhpWord — DOCX/DOC/ODT)
  - `SpreadsheetExtractor` (PhpOffice PhpSpreadsheet — XLSX/XLS)
  - `EmlParser` (RFC-822 messages)
  - Direct read for `text/*` families (plain, markdown, HTML, XML, JSON, CSV, YAML).
- **Entry-point signatures:** `extractFile(int $fileId, bool $forceReExtract = false)` and `extractObject(int $objectId, bool $forceReExtract = false)`.
- **Extraction is lazy, background-driven:** `FileTextExtractionJob`, `ObjectTextExtractionJob`, `CronFileTextExtractionJob`. Not blocking on file writes; not per-query.

### Chunk store + index (present, complete)

- **Table `openregister_chunks`** — columns include `text_content` (TEXT), `source_type` (`'file'` or `'object'`), `source_id`, plus language metadata.
- **PostgreSQL functional GIN index** on `to_tsvector('simple', text_content)` shipped by migration `Version1Date20260706101000` under the merged [`hybrid-document-search`](https://codeberg.org/Conduction/openregister/src/branch/development/openspec/changes/hybrid-document-search) change.
- **Query API present**: `ChunkMapper::searchByKeyword()` uses `ts_rank` scoring and returns chunk hits with `source_type` + `source_id` — exactly the shape needed to join back to the owning document.

### Missing wire (the only OR-side gap)

- `ObjectService::searchObjectsPaginated()` → `QueryHandler::searchObjectsPaginatedDatabase()` → `MagicMapper::searchObjectsPaginated()` is a pure metadata-search path. It does not touch `openregister_chunks`.
- `ChunkMapper::searchByKeyword()` is currently only reachable through the admin-only `FileSearchController` (not a surface WOO-506 consumes).
- There is no `_content_search` (or similarly-named) flag threading a chunk-search fan-out through `searchObjectsPaginated()`.

### RBAC / anonymous visibility semantics (already fine)

- OR does not apply per-object visibility inside `searchObjectsPaginated` when called with `_rbac: false` — WOO-506 already relies on that and applies its own `isObjectPublic()` post-scoring filter.
- The same pattern works for content matches: fetch chunks + owning documents with `_rbac: false`, then apply `isObjectPublic()` + transitive-publication check on OC side. No new pipeline needed on OR side to enforce visibility.

### Existing OpenSpec changes touching this area

- **`openregister/openspec/changes/hybrid-document-search`** — MERGED. Ships extraction + chunk store + index + query method. No overlap with what WOO-517 needs on OR side (which is only the flag on `searchObjectsPaginated`).
- **`opencatalogi/openspec/changes/`** — no existing `add-document-content-search` or equivalent. This change is the first WOO-517-related spec on the OC side.

## Recommendation

**Green light for the OC-side WOO-517 spec.**

- Do not re-implement extraction, chunking, or indexing — reuse.
- Do not add a new endpoint — extend WOO-506's `GET /apps/opencatalogi/api/search` with an opt-in `_content` param.
- Do not change the response envelope shape — content matches return the mapped document object, indistinguishable in shape from metadata matches.
- File an OR-side prerequisite spec + Jira ticket (with Ruben) to add `_content_search` (or agreed name) to `ObjectService::searchObjectsPaginated()`. WOO-517 has a HARD `depends_on` on that landing first.

**Sizing estimate:**

| Layer | Effort |
|---|---|
| OR-side prerequisite | Medium — hook `ChunkMapper::searchByKeyword()` into `searchObjectsPaginated()` behind a flag, map chunks → owning documents, return normalised list. Own spec on OR side. |
| OC-side (this change) | Small — 200–400 LOC across `PublicationQueryService::assemblePublicSearchResults()` + tests. Envelope + visibility filters already exist. |

## Deferred / follow-up

- **Exact OR-side flag name** — `_content_search` is the working name. Ruben's call at OR-spec time; the OC assembler can translate whatever OR agrees on to the OC-side `_content=true` query param.
- **MariaDB parity** — chunk index is Postgres-only (`tsvector` GIN). MariaDB falls back to `LIKE` without `ts_rank`. Design + wiring stay identical; only ranking quality degrades. Documented as deferred in proposal.md; own follow-up if MariaDB deploys need equal ranking.
- **Snippet exposure** — a `_snippet` field on document rows for "…matched here…" excerpts is a natural next ask, but out of scope here; can be added later without envelope-break.
