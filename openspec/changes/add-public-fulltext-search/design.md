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

The current `lib/Settings/publication_register.json` ships **8 seed objects** — 1 catalog (`publications`), 3 pages (`home`, `about`), 3 menus (`main-menu`, `user-menu`, `footer-menu`), 1 theme (`general`), 1 organization (`default-org`). It ships **no seed publications** and **no seed documents**. This change adds both, so the public search endpoint has something meaningful to return on a fresh install.

Envelope shape follows the bundle's existing convention (seen on the `publications` catalog seed):

```json
{ "@self": { "register": "publication", "schema": "<schema-slug>", "slug": "<unique-slug>" }, ... }
```

Identity is `@self.slug`. No top-level `id` field on seed rows — the magic mapper assigns UUIDs at import time. Import matching is by `@self.slug` (ADR-001 idempotency rule).

### Seed publications (NEW — none exist in bundle today)

Two publications spanning municipality + consultancy:

```json
{
  "@self": { "register": "publication", "schema": "publication", "slug": "jaarverslag-2024-gemeente-buren" },
  "title": "Jaarverslag 2024 — Gemeente Buren",
  "summary": "Het jaarverslag 2024 van de gemeente Buren.",
  "description": "Jaarverslag met financiële cijfers, prestatie-indicatoren en beleidsevaluatie over 2024.",
  "organization": "default-org",
  "publicatiedatum": "2025-03-15T00:00:00+00:00",
  "status": "published"
}
```

```json
{
  "@self": { "register": "publication", "schema": "publication", "slug": "conduction-kwartaalrapport-q1-2026" },
  "title": "Conduction Kwartaalrapport Q1 2026",
  "summary": "Kwartaalrapport Q1 2026 van Conduction B.V. over WOO-implementaties bij Nederlandse gemeenten.",
  "description": "Analyse van Q1-implementaties, klantstatussen en aanbevelingen voor productroadmap.",
  "organization": "default-org",
  "publicatiedatum": "2026-04-01T00:00:00+00:00",
  "status": "published"
}
```

### Seed documents (NEW — reference the seed publications above)

```json
{
  "@self": { "register": "publication", "schema": "document", "slug": "jaarverslag-2024-gemeente-buren-pdf" },
  "title": "Jaarverslag 2024 (PDF)",
  "filename": "jaarverslag-2024-gemeente-buren.pdf",
  "mimeType": "application/pdf",
  "summary": "PDF-versie van het jaarverslag 2024 van Gemeente Buren.",
  "publication": {
    "slug": "jaarverslag-2024-gemeente-buren",
    "title": "Jaarverslag 2024 — Gemeente Buren"
  },
  "organization": "default-org",
  "publicatiedatum": "2025-03-15T00:00:00+00:00"
}
```

```json
{
  "@self": { "register": "publication", "schema": "document", "slug": "conduction-kwartaalrapport-q1-2026-pdf" },
  "title": "Conduction Kwartaalrapport Q1 2026 (PDF)",
  "filename": "conduction-q1-rapport.pdf",
  "mimeType": "application/pdf",
  "summary": "Kwartaalrapport Q1 2026 van Conduction over WOO-implementaties bij Nederlandse gemeenten.",
  "publication": {
    "slug": "conduction-kwartaalrapport-q1-2026",
    "title": "Conduction Kwartaalrapport Q1 2026"
  },
  "organization": "default-org",
  "publicatiedatum": "2026-04-01T00:00:00+00:00"
}
```

Notes on the seed shape:

- **Field names are English** (`title`, `summary`, `description`, `filename`, `mimeType`, `organization`) matching the bundled publication schema's convention and ADR-001's "do not hardcode Dutch field names as primary" rule. WOO-domain date fields (`publicatiedatum`, `depublicatiedatum`) stay Dutch because that's the WOO vocabulary the whole publication family already uses.
- **Embedded `publication` summary uses `slug` + `title` only** (no `id`) — the frontend can deeplink by slug via the same catalog-slug routing `/publications/{catalogSlug}/{slug}` uses today, and no UUID lookup is required to render the parent-link on a document card. At API-response time (not seed time) the assembly helper MAY additionally include `id` (the UUID) alongside slug + title, per the `SCH-PFTS-003` spec — the seed doesn't need it because the UUID doesn't exist until import.
- **`organization` is a string slug** referencing the bundled `default-org` seed — matches how the publication schema declares the property (`type: string`).
- These four seed rows exercise both decision-1 paths: under Path A the body text inside the PDFs becomes searchable via OR's `TextExtractionService` + Solr-pipeline; under Path B the surrounding metadata (`title`, `summary`, `filename`, and the embedded publication's `title`) provides the match surface.

## `document` schema shape (high-level)

Bundled in `lib/Settings/publication_register.json` under `components.schemas.document`. Modelled on the existing `publication` schema's shape and authorization block:

- `slug: "document"`, `title: "Document"`, `version: "0.1.0"`.
- `required: ["title", "publication"]`.
- Properties (minimal, deferring deeper fields to follow-ups): `title`, `filename`, `mimeType`, `summary`, `publication` (object: `{id, slug, title}` — English `title`, matching the bundled publication schema convention), `organization`, `publicatiedatum`, `depublicatiedatum`.
- `searchable: true`.
- `authorization.read` mirrors `publication`: anonymous read allowed when `publicatiedatum <= $now` AND (effectively, via the controller) linked-publication is public.
- The schema is registered by adding it under `components.schemas.document` — consistent with how the 9 bundled schemas (`publication`, `catalog`, `page`, `menu`, `theme`, `glossary`, `listing`, `organization`, `usageCounter`) are wired today. The magic mapper auto-allocates `oc_openregister_table_publication_document` on first install; no separate `configuration.schemas` block is required.

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
