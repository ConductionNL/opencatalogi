---
status: pr-created
---

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

**Decision:** keep as `kind: mixed` per ADR-032 §"Thin-glue exception". A split into a config-kind `add-document-schema-declaration` + a code-kind change depending on it was considered and rejected because the schema-only build would ship a `document` type that citizens cannot reach — the public search endpoint (which is the only way to surface documents to anonymous callers) doesn't exist until the code-kind lands, so the intermediate state has no user-observable value. Shipping the two surfaces as one atomic change avoids that unreachable-intermediate state.

## Service placement decision

The mixed-type assembly (calling OR's `zoeken-filteren` across `publication` + `document`, merging candidate rows, applying `isObjectPublic()` post-filter, embedding the parent-publication summary on document rows) lives in **`PublicationQueryService`** — not a new sibling service.

Rationale:

- `PublicationQueryService` already owns `isObjectPublic()`, which `SCH-PFTS-004` reuses unchanged. Co-locating the mixed-type assembly with the visibility helper it depends on keeps the transitive-visibility logic in one place.
- The current `PublicationQueryService` is small enough (~1 KLoC) that a new method (`assemblePublicSearchResults()` or similar) does not push it past a natural boundary.
- A "sibling service" would need to re-inject `PublicationQueryService` anyway just to reuse `isObjectPublic()`, adding indirection without a coherent domain boundary.

If a future change introduces a genuinely distinct multi-schema surface (e.g. cross-register federation of the document schema), a sibling service becomes justified then — not now.

## Seed Data (ADR-001)

The current `lib/Settings/publication_register.json` ships **8 seed objects** — 1 catalog (`publications`), 3 pages (`home`, `about`), 3 menus (`main-menu`, `user-menu`, `footer-menu`), 1 theme (`general`), 1 organization (`default-org`). It ships **no seed publications** and **no seed documents**. This change adds both, so the public search endpoint has something meaningful to return on a fresh install.

Seed data is **always loaded on install** — `SettingsService::autoConfigure()` runs `ConfigurationService::importFromApp()` on every install, importing every object under `components.objects[]`. Re-import is idempotent (matches on `@self.slug` per ADR-001). Deployers can delete or replace seed rows after install, but by default every fresh install has these four rows present.

Because seed data is always present, **each seed row MUST be self-identifying as demo/example data** so real users landing on a fresh deployment (before real content is imported) understand what they're looking at. This is done in three complementary ways per seed row:

1. **Slug prefix** — every seed slug starts with `example-` so it's identifiable in URLs, admin lists, and log lines.
2. **Title prefix** — every seed title starts with `Voorbeeld:` (Dutch "Example:") so it's obvious in the card view.
3. **Description marker** — the `description` field starts with an explicit "**Voorbeeld / seed data** — dit is …" banner explaining what it is and inviting deployers to delete or replace it.

The municipality scenario uses the fictional **Gemeente Voorbeeld** (not any real municipality) so there's no risk of confusion with a real gemeente. The consultancy scenario uses **Conduction B.V.** — that's the real app maintainer; the seed row remains self-identifying via the `Voorbeeld:` prefix and description banner.

### Envelope shape

Envelope shape follows the bundle's existing convention (seen on the `publications` catalog seed):

```json
{ "@self": { "register": "publication", "schema": "<schema-slug>", "slug": "<unique-slug>" }, ... }
```

Identity is `@self.slug`. No top-level `id` field on seed rows — the magic mapper assigns UUIDs at import time. Import matching is by `@self.slug` (ADR-001 idempotency rule).

### Seed publications (NEW — none exist in bundle today)

Two publications spanning a municipality + consultancy scenario. Both are clearly marked as seed data:

```json
{
  "@self": { "register": "publication", "schema": "publication", "slug": "example-municipality-annual-report" },
  "title": "Voorbeeld: Jaarverslag 2024 — Gemeente Voorbeeld",
  "summary": "Voorbeeld-publicatie (seed data) — fictief jaarverslag van een fictieve gemeente. Aanwezig na fresh install om de search-functionaliteit meteen bruikbaar te maken.",
  "description": "**Voorbeeld / seed data** — dit is geen echte publicatie. Bij een fresh install van OpenCatalogi wordt deze rij automatisch geladen zodat het publieke search-endpoint (`/apps/opencatalogi/api/search`) meteen iets terug kan geven. Vervang of verwijder deze rij zodra de echte publicatie-content geïmporteerd is. Gemeente Voorbeeld is een fictieve gemeente en representeert geen echte overheidsorganisatie.",
  "organization": "default-org",
  "publicatiedatum": "2025-03-15T00:00:00+00:00",
  "status": "published"
}
```

```json
{
  "@self": { "register": "publication", "schema": "publication", "slug": "example-conduction-quarterly-report" },
  "title": "Voorbeeld: Conduction Kwartaalrapport Q1 2026",
  "summary": "Voorbeeld-publicatie (seed data) — kwartaalrapport van Conduction B.V., de maintainer van OpenCatalogi. Aanwezig na fresh install om de search-functionaliteit meteen bruikbaar te maken.",
  "description": "**Voorbeeld / seed data** — dit is geen echte publicatie. Bij een fresh install van OpenCatalogi wordt deze rij automatisch geladen zodat het publieke search-endpoint (`/apps/opencatalogi/api/search`) meteen iets terug kan geven. Vervang of verwijder deze rij zodra de echte publicatie-content geïmporteerd is.",
  "organization": "default-org",
  "publicatiedatum": "2026-04-01T00:00:00+00:00",
  "status": "published"
}
```

### Seed documents (NEW — reference the seed publications above)

```json
{
  "@self": { "register": "publication", "schema": "document", "slug": "example-municipality-annual-report-pdf" },
  "title": "Voorbeeld: Jaarverslag 2024 (PDF)",
  "filename": "voorbeeld-jaarverslag-2024.pdf",
  "mimeType": "application/pdf",
  "summary": "Voorbeeld-document (seed data) — fictieve PDF-bijlage bij het voorbeeld-jaarverslag. Aanwezig na fresh install.",
  "description": "**Voorbeeld / seed data** — dit is geen echte PDF. Bij een fresh install wordt deze rij automatisch geladen om de search-response mixed-typed te maken. Vervang of verwijder deze rij zodra echte documenten geïmporteerd zijn.",
  "publication": {
    "slug": "example-municipality-annual-report",
    "title": "Voorbeeld: Jaarverslag 2024 — Gemeente Voorbeeld"
  },
  "organization": "default-org",
  "publicatiedatum": "2025-03-15T00:00:00+00:00"
}
```

```json
{
  "@self": { "register": "publication", "schema": "document", "slug": "example-conduction-quarterly-report-pdf" },
  "title": "Voorbeeld: Conduction Kwartaalrapport Q1 2026 (PDF)",
  "filename": "voorbeeld-conduction-q1-rapport.pdf",
  "mimeType": "application/pdf",
  "summary": "Voorbeeld-document (seed data) — fictieve PDF-bijlage bij het voorbeeld-kwartaalrapport. Aanwezig na fresh install.",
  "description": "**Voorbeeld / seed data** — dit is geen echte PDF. Bij een fresh install wordt deze rij automatisch geladen om de search-response mixed-typed te maken. Vervang of verwijder deze rij zodra echte documenten geïmporteerd zijn.",
  "publication": {
    "slug": "example-conduction-quarterly-report",
    "title": "Voorbeeld: Conduction Kwartaalrapport Q1 2026"
  },
  "organization": "default-org",
  "publicatiedatum": "2026-04-01T00:00:00+00:00"
}
```

Notes on the seed shape:

- **Seed-data self-identification** — every seed row's slug starts with `example-`, title starts with `Voorbeeld:`, and description opens with a `**Voorbeeld / seed data**` banner explaining what the row is and how to replace it. A deployer's admin surface, a URL, an anonymous user's card view, and a log line all carry the marker.
- **Field names are English** (`title`, `summary`, `description`, `filename`, `mimeType`, `organization`) matching the bundled publication schema's convention and ADR-001's "do not hardcode Dutch field names as primary" rule. WOO-domain date fields (`publicatiedatum`, `depublicatiedatum`) stay Dutch because that's the WOO vocabulary the whole publication family already uses.
- **Embedded `publication` summary uses `slug` + `title` only** (no `id`) — the frontend can deeplink by slug via the same catalog-slug routing `/publications/{catalogSlug}/{slug}` uses today, and no UUID lookup is required to render the parent-link on a document card. At API-response time (not seed time) the assembly helper MAY additionally include `id` (the UUID) alongside slug + title, per the `SCH-PFTS-003` spec — the seed doesn't need it because the UUID doesn't exist until import.
- **`organization` is a string slug** referencing the bundled `default-org` seed — matches how the publication schema declares the property (`type: string`).
- These four seed rows exercise both decision-1 paths: under Path A the body text inside the PDFs (once real content replaces the seed) becomes searchable via OR's `TextExtractionService` + Solr-pipeline; under Path B the surrounding metadata (`title`, `summary`, `filename`, and the embedded publication's `title`) provides the match surface.

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

## Implementation note — register/schema id resolution

`assemblePublicSearchResults()` resolves the publication register id and the
`publication`/`document` schema ids from app config (`publication_register`,
`publication_schema`, `document_schema`) rather than a live `SchemaMapper`/
`RegisterMapper` lookup by slug, mirroring the existing `RetentionService`
pattern. `SettingsService::updateObjectTypeConfiguration()` is extended to
include `'document'` in its object-types list so these keys populate on
install/upgrade, exactly as `publication_register`/`publication_schema`
already do. If the config keys are not (yet) populated — e.g. a fresh
install before the settings-load repair step has run — the assembly method
fails closed to an empty result envelope rather than falling back to an
unscoped, platform-wide search.

## Scope reduction — CHANGELOG.md

`tasks.md`'s CHANGELOG task is intentionally left unchecked. Per ADR-037
(modular config fragments), `CHANGELOG.md` is owned by the release/apply
step — a single version bump per merged change — and editing it from a
builder PR guarantees a conflict against any sibling build in flight on this
app. The backwards-compat note (mixed rows, anonymous reachability, the
removed admin-only shape) is carried in this PR's description instead; the
release step should fold it into the next dated `CHANGELOG.md` entry.

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
