# Design: add-public-fulltext-search

## Architecture Overview

OpenCatalogi exposes one new public surface: `GET /apps/opencatalogi/api/search`. It absorbs today's admin-only `SearchController::index` endpoint and grows two new responsibilities:

1. **Mixed-type assembly.** The endpoint returns publications **and** documents in a single flat array, discriminated by `@self.schema`. Today's controller delegates to `PublicationService::index()` which returns publications only; the new assembly calls OR's `zoeken-filteren` across both schemas (`publication`, `document`) and merges the result rows in a single envelope.
2. **RBAC for anonymous callers.** The endpoint becomes anonymous-reachable. The same `isObjectPublic()` filter `PublicationQueryService` already applies for `/publications` is reused, with identical ordering semantics: filter AFTER scoring/merge so visibility decisions never bias ranking.

The data layer change is purely additive: a new `document` schema joins `publication`, `catalog`, `organization`, …, in `lib/Settings/publication_register.json`. Existing schemas, their authorization blocks, and their lifecycle/notification metadata are untouched.

`GET /publications` is **not** modified. The two endpoints coexist:

| Endpoint                                              | Scope                              | Auth posture        | Returns                                   |
|-------------------------------------------------------|------------------------------------|---------------------|-------------------------------------------|
| `GET /publications`                                   | publication objects only           | public (unchanged)  | publications                              |
| `GET /apps/opencatalogi/api/search` (this change)     | publications + documents           | public (new)        | mixed rows, discriminated by `@self.schema` |

## Dual-path design — pending Ruben's decision

The proposal's "Pending decisions" block flags one open question: can OC lean on OR's `TextExtractionService` + `FileHandler` + Solr-pipeline for document **content** indexing? Until Ruben confirms, this design presents two implementation paths.

### Path A — OR pipeline available (Ruben confirms yes)

- Document content (full body text from PDFs, DOCX, etc.) is indexed by OR's existing pipeline.
- This change's B2 scope includes document-content matching: a search for `"jaarverslag"` returns document rows whose extracted body text contains the term.
- The `document` schema's authorization is wired so OR's Solr-backed query honours `isObjectPublic()` projections.
- No new extraction code in OpenCatalogi (ADR-022).

### Path B — OR pipeline not (yet) available (Ruben confirms no / later)

- This change's B2 scope ships **metadata-only** document search. Matches are based on the document schema's own fields (filename, title, summary, MIME type, linked publication's title/summary) — not document body text.
- Document-content search becomes a separate B3 follow-up OpenSpec change (`add-document-content-search` or similar) that introduces or consumes whatever extraction surface OR exposes when it ships.
- The flat envelope and `@self.schema` discriminator carry over — the B3 change is purely additive matching power.

**Default during implementation:** Path B. Implementers MUST NOT add new extraction code in this change; if Ruben confirms Path A before B2 lands, the integration surface is "wire OR's existing pipeline", not "build a new one".

## Mixed-spec rationale (ADR-032)

This change is `kind: mixed`. The artifacts touch:

- **Config:** new `document` schema + seed objects inside `lib/Settings/publication_register.json` (declarative, JSON-only).
- **Code:** `SearchController::index()` becomes public + RBAC; the assembly service grows mixed-type row handling.

Per ADR-032 the default is to chain config-then-code. Here, the code change is a tight pass-through: ≤ ~50 LOC across the controller and one service helper. The reason the code can't be deferred to a follow-up chain spec is **frontend renderability**: an OC build that ships the new schema but not the public endpoint produces a `document` schema citizens have no way to reach; an OC build that ships the public endpoint but not the schema returns publication-only rows from a surface the proposal explicitly justifies as mixed-typed. The two surfaces must arrive together for the WOO use case to be reachable.

This is borderline "thin glue" territory (ADR-032 §"Thin-glue exception"). It's flagged as a deferred question for the user: split into a config-kind `add-document-schema-declaration` + this change as code-kind with `depends_on: [add-document-schema-declaration]`, OR keep as a single `mixed` change with this rationale documented. The implementation will work either way; the split decision is editorial.

## Seed Data (ADR-001)

The new `document` schema MUST ship with realistic seed objects. The data layer ADR (ADR-001) requires this so the app is functional on first install. Seed objects exercise a municipality and a consultancy scenario:

### Seed publication (already exists in seed corpus; reused for linkage)

```json
{
  "id": "pub-jaarverslag-2024",
  "@self": { "schema": "publication" },
  "title": "Jaarverslag 2024 — Gemeente Buren",
  "slug": "jaarverslag-2024",
  "summary": "Het jaarverslag 2024 van de gemeente Buren.",
  "organization": "gemeente-buren",
  "publicatiedatum": "2025-03-15T00:00:00+00:00"
}
```

### Seed document (new — references publication above)

```json
{
  "id": "doc-jaarverslag-2024-pdf",
  "@self": { "schema": "document" },
  "title": "Jaarverslag 2024 (PDF)",
  "filename": "jaarverslag-2024.pdf",
  "mimeType": "application/pdf",
  "summary": "PDF-versie van het jaarverslag 2024 van Gemeente Buren.",
  "publication": {
    "id": "pub-jaarverslag-2024",
    "slug": "jaarverslag-2024",
    "titel": "Jaarverslag 2024 — Gemeente Buren"
  },
  "organization": "gemeente-buren",
  "publicatiedatum": "2025-03-15T00:00:00+00:00"
}
```

### Seed document — consultancy scenario

```json
{
  "id": "doc-conduction-rapport-q1",
  "@self": { "schema": "document" },
  "title": "Conduction Kwartaalrapport Q1",
  "filename": "conduction-q1-rapport.pdf",
  "mimeType": "application/pdf",
  "summary": "Kwartaalrapport Q1 van Conduction over WOO-implementaties bij Nederlandse gemeenten.",
  "publication": {
    "id": "pub-conduction-q1",
    "slug": "conduction-kwartaalrapport-q1",
    "titel": "Conduction Kwartaalrapport Q1"
  },
  "organization": "conduction",
  "publicatiedatum": "2026-04-01T00:00:00+00:00"
}
```

These seed rows exercise both decision-1 paths: under Path A the body text inside the PDFs becomes searchable; under Path B the surrounding metadata (`title`, `summary`, `filename`, linked publication's `titel`) provides the match surface.

## `document` schema shape (high-level)

Bundled in `lib/Settings/publication_register.json` under `components.schemas.document`. Modelled on the existing `publication` schema's shape and authorization block:

- `slug: "document"`, `title: "Document"`, `version: "0.1.0"`.
- `required: ["title", "publication"]`.
- Properties (minimal, deferring deeper fields to follow-ups): `title`, `filename`, `mimeType`, `summary`, `publication` (object: `{id, slug, titel}`), `organization`, `publicatiedatum`, `depublicatiedatum`.
- `searchable: true`.
- `authorization.read` mirrors `publication`: anonymous read allowed when `publicatiedatum <= $now` AND (effectively, via the controller) linked-publication is public.
- The schema is added to the register's `schemas` list and to `configuration.schemas` so the magic mapper allocates a dedicated table.

ADR-031 applicability is minimal here — no lifecycle transitions, aggregations, or notifications on the `document` schema in this change. Future follow-ups may add depublication transitions or retention metadata; deferred.

## RBAC filter — ordering and transitivity

`PublicationQueryService::isObjectPublic($objectData)` is reused unchanged. Two ordering invariants:

1. **Filter runs AFTER scoring/merge.** OR's `zoeken-filteren` returns a candidate set ordered by `_score`. The controller (or an assembly helper) iterates the candidate rows and drops any whose `isObjectPublic()` returns false. The visibility decision MUST NOT be folded into the OR query, because that would bias scoring against rows the corpus considers more relevant.
2. **Documents are transitively gated.** A document row is included only when (a) the document itself satisfies `isObjectPublic()`, and (b) its linked publication satisfies `isObjectPublic()`. The linked-publication object MAY be looked up lazily during the filter, or the linked publication's `publicatiedatum`/`depublicatiedatum` MAY be denormalised onto the document schema for filter efficiency. The denormalisation choice is left to the implementer; the spec requires only the observable transitivity.

## Out of scope (B3 candidates)

- **Lucene-style operator parsing.** Boolean `AND`/`OR`, phrase quotes (`"foo bar"`), prefix wildcards (`jaar*`) — a closed PR #58 attempted this; defer to a dedicated change once OR's `zoeken-filteren` exposes the relevant query shape.
- **Per-schema field weighting / `searchConfig` block.** Not needed for the WOO MVP; defer.
- **`@self.relevance` response field.** Defer unless the Solr backend already produces it; if so, expose verbatim with no recomputation (ADR-022).
- **Document-content search under Path B.** B3 follow-up.

## References

- WOO-506 (Jira ticket) — source of truth for the decision matrix.
- ADR-001 — data layer + seed data.
- ADR-022 — apps consume OR abstractions.
- ADR-031 — declarative-vs-imperative (minimal applicability here).
- ADR-032 — spec sizing + chains (Mixed-spec rationale above).
- `openspec/specs/search/spec.md` — existing `SCH-OR-001` … `SCH-OR-006` (untouched by this change).
- Closed PR #58 — earlier attempt at this surface, scope expanded; replaced by this minimal change.
