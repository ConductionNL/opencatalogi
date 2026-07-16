# Design: add-document-content-search

## Architecture Overview

WOO-517 layers content-search onto the WOO-506 public search endpoint (`GET /apps/opencatalogi/api/search`) without breaking the envelope. Two mechanics compose:

1. **OR side (prerequisite, out of this repo)** — expose a `_content_search` (working name) flag on `ObjectService::searchObjectsPaginated()`. When set, OR routes to `ChunkMapper::searchByKeyword()` alongside its normal metadata-based `zoeken-filteren` path, and returns chunk hits mapped to their owning object (`source_type = 'file'`, `source_id = <file_id>` → `document` row via existing owner-of-file join).
2. **OC side (this change)** — `PublicationQueryService::assemblePublicSearchResults()` accepts `_content=true` from `$queryParams` (default false), forwards the OR flag, dedupes the union of metadata-matches + content-matches on `@self.id`, applies the existing `isObjectPublic()` + transitive-publication-visibility filters, and returns the same flat envelope with `@self.schema` discriminator that WOO-506 defined.

Nothing else moves. No new endpoint, no new schema, no new column, no new frontend, no new visibility rule.

## API Design

Single endpoint extension — new optional query parameter on the existing WOO-506 route.

### `GET /apps/opencatalogi/api/search`

**New parameter:** `_content` (boolean, optional, default `false`).

**Request (opt-in content search):**
```
GET /apps/opencatalogi/api/search?_search=lorem-ipsum-woo517-marker&_content=true
```

**Response (envelope unchanged from WOO-506):**
```json
{
  "results": [
    {
      "id": "<uuid>",
      "title": "Interne evaluatie Klimaatakkoord Q1 2026.pdf",
      "@self": { "schema": "document", "...": "..." },
      "publication": {
        "id": "<uuid>",
        "slug": "woo-verzoek-2026-001-klimaatakkoord-evaluatie",
        "title": "Woo-verzoek 2026-001 — Klimaatakkoord evaluatie"
      }
    }
  ],
  "total": 1
}
```

A content-matched document row is **byte-shape-identical** to a metadata-matched document row. Clients MUST NOT depend on any field to tell the two apart.

**Default behaviour (no `_content` param):** byte-identical to the WOO-506 baseline. Zero drift for existing consumers.

## Database Changes

**None on the OpenCatalogi side.** The chunk store (`openregister_chunks`) + PostgreSQL `tsvector` GIN index already exist in OpenRegister via the merged [`hybrid-document-search`](https://codeberg.org/Conduction/openregister/src/branch/development/openspec/changes/hybrid-document-search) change (migration `Version1Date20260706101000`). No new tables, columns, or migrations required by WOO-517.

## Nextcloud Integration

- Controllers:
  - `OCA\OpenCatalogi\Controller\SearchController::index` — unchanged (reads `_content` from `$this->request->getParams()` which already flows through).
- Services:
  - `OCA\OpenCatalogi\Service\PublicationQueryService::assemblePublicSearchResults` — extended to consume `_content` and forward the OR-side flag.
- Mappers/Entities: none touched on OC side.
- Events/Hooks: none.
- External dependency: `OCA\OpenRegister\Service\ObjectService::searchObjectsPaginated` MUST accept the OR-side `_content_search` flag (prerequisite, not in this change).

## Security Considerations

Content-search matches the same visibility surface as metadata-search — no new attack surface exposed.

- **Anonymous reachability** — endpoint is already `#[PublicPage]` + `#[NoCSRFRequired]` per WOO-506's `SCH-PFTS-001`. `_content=true` MUST NOT change that posture.
- **Post-scoring visibility gate** — `isObjectPublic()` runs AFTER the union assembly (per WOO-506's `SCH-PFTS-004`); a body-text match on a depublished document is dropped exactly like a metadata-match on the same document.
- **Transitive publication visibility** — a body-text match on a document whose linked publication is depublished MUST be dropped, same rule as metadata-match. Covered by scenarios in `SCH-PFTS-CONTENT-003`.
- **No raw chunk exposure** — chunk id, chunk snippet, chunk score MUST NOT appear in the public response envelope. Only the mapped document object is returned. Covered by `SCH-PFTS-CONTENT-002`.
- **Opt-in cost** — `_content=true` triggers an additional query fan-out to `ChunkMapper::searchByKeyword()`. Anonymous callers can therefore compel a heavier query by adding the flag. Rate-limit / cost concerns are the same as the WOO-506 base endpoint (already public) and belong to the infra layer if raised; no per-endpoint throttling added here.

## File Structure

```
opencatalogi/
  lib/
    Service/
      PublicationQueryService.php    ← MODIFIED (assemblePublicSearchResults + optional dedup helper)
  tests/
    Unit/
      Service/
        PublicationQueryServiceTest.php    ← MODIFIED (add _content on/off + dedup scenarios)
    e2e/
      content-search-endpoint.spec.ts      ← NEW (or in existing search-endpoint spec — TBD by impl)

  openspec/
    changes/
      add-document-content-search/          ← this change (proposal + tasks + spec deltas + this design)
```

External (not in this change):

```
openregister/
  lib/
    Service/
      ObjectService.php    ← prerequisite (expose _content_search flag)
    Db/
      MagicMapper.php      ← may need routing to ChunkMapper::searchByKeyword() from searchObjectsPaginated
```

## Declarative-vs-imperative decision

Not applicable — this change only extends an existing controller-service call chain. It doesn't introduce any lifecycle, aggregation, calculation, notification, relation, or widget behaviour. The OR-side wiring exposes a query flag; no new schema-register content on either side.

## Trade-offs

- **Opt-in default** — chose `_content=false` default over always-on. Rationale: guarantees byte-identical WOO-506 behaviour for existing consumers; content-fan-out only fires when a caller explicitly opts in. Alternative "always on" would silently change ranking for every existing call and could double query cost per request. Rejected.
- **Union + dedup on OC side, not on OR side** — the deduplication happens after `assemblePublicSearchResults` receives the OR union. Alternative: have OR itself dedupe and return already-merged rows. Rejected because it entangles OR internals with OC-specific rules (transitive publication visibility, embedded publication summary) — cleaner to keep OR returning a normalised chunk-hit list and let the OC assembler handle domain-specific composition.
- **Content matches return the document, never the chunk** — spec explicitly forbids chunk payloads in the public envelope (`SCH-PFTS-CONTENT-002`). Alternative: expose highlighted snippets in a `_snippet` field so clients can render "…matched here…" excerpts. Rejected for this change (out of scope, snippet-rendering has its own frontend + a11y questions). Can be added later without envelope-break.
- **MariaDB ranking degrades to LIKE** — the chunk index is Postgres-only (`tsvector` GIN). On MariaDB, `ChunkMapper::searchByKeyword()` falls back to `LIKE` without `ts_rank`. Trade-off documented in proposal.md; equal-quality MariaDB ranking is deferred to a follow-up on OR's side (equivalent MariaDB `FULLTEXT` index or trigram alternative).

## Risks / Trade-offs

- **OR-side flag naming not yet fixed** → Coordinate with Ruben on the exact name (`_content_search` proposed) before opcatalogi impl commit; a bikeshed change later means a follow-up PR here.
- **Extraction lag** → Files are extracted lazily by `FileTextExtractionJob` / `CronFileTextExtractionJob`; a document uploaded seconds before a search may not have chunks yet. Mitigation: the WOO-506 metadata-match already surfaces the doc via title/filename; content-match arrives once the job runs. Acceptable — documented in the docs update task.
- **Chunk-store size growth** → Every new document adds chunks. OR's `hybrid-document-search` already ships pruning/rotation logic; nothing to add on OC side.
- **Rate-limit gap** → Public opt-in query can compound cost. No per-endpoint throttle added by this change. Mitigation: infra-level rate-limit (matches WOO-506 assumption) or a follow-up if abuse surfaces.

## Seed Data

Not applicable — no new schemas or object types introduced by this change. WOO-506's `publication` + `document` schemas + their seeded objects are reused. For end-to-end testing of content-search, WOO-531 (Seed-data script met echte PDF/DOCX-inhoud) provides the file bodies needed to exercise the extraction pipeline; that ticket is the seed-data owner for this feature.

## Migration Plan

Spec-only OC-side change; no runtime migration.

- **Deploy order** — OR-side prerequisite MUST ship first (expose `_content_search` flag). OC-side impl can then be enabled behind a feature flag if needed, but since default `_content=false` yields byte-identical WOO-506 behaviour, no flag is strictly required.
- **Rollback** — remove or reject the `_content` query parameter in `PublicationQueryService`; endpoint reverts to WOO-506 baseline.
- **Coexistence** — OR-side flag can be added ahead of OC-side; unused flag has no cost on the OR side.

## Open Questions

- **Exact OR-side flag name** — `_content_search`? `_content`? Ruben's call. Provisional throughout this design: `_content_search` for the OR-side param, `_content` for the OC-side param (they need not match — the OC assembler translates).
- **Snippet exposure** — future ask, not in this change. If added later it lands as a `_snippet` field on document rows behind another opt-in flag; envelope-shape stays back-compat.
