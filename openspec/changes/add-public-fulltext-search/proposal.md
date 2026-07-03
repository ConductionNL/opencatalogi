---
kind: mixed
depends_on: []
---

# Proposal: add-public-fulltext-search

## Summary

Add a public, RBAC-filtered full-text search endpoint at `GET /apps/opencatalogi/api/search` that returns publications **and** documents as mixed-typed rows in a flat envelope, discriminated by `@self.schema`. The existing admin-only `SearchController` endpoint is **absorbed** into this new endpoint — it becomes public, anonymous-reachable, and applies the same anonymous visibility filter the `/publications` endpoint already uses. A new dedicated `document` schema is bundled in `lib/Settings/publication_register.json` so document rows are schema-discoverable and can carry their own search-relevant metadata.

The companion `GET /publications` endpoint is explicitly **out of scope** — its current behaviour (searches inside publication objects only) MUST remain unchanged.

## Motivation

WOO portals must let citizens search across **both** publication records and the documents attached to those publications from a single search box. Today:

- `GET /publications` searches publication objects only (schema-properties + metadata). Documents — the actual files citizens want to find — are not searchable from any public endpoint.
- `GET /api/search` exists but is admin-only (returns 401 to anonymous callers) and only returns publications.
- OpenRegister's `File` entity (`oc_openregister_files`) holds documents but is not schema-discoverable, so the federation/orchestrator pattern in `search` can't reach it via `zoeken-filteren` without a schema to anchor on.

A single public search surface that returns mixed publication/document rows — schema-discriminated so the frontend can render distinct cards — is the smallest change that unblocks the WOO use case. By introducing a `document` schema bundled in OC's register, OR's `zoeken-filteren` becomes reusable for document metadata search without OC re-implementing any of it (ADR-022).

## Scope decision (locked 2026-07-03)

This change ships **metadata-only search** across publications + documents (bestandsnaam, titel, samenvatting, MIME, embedded publicatie-summary). Content-search inside PDF/DOCX bodies is **deferred** and tracked separately.

- **Follow-up ticket:** [WOO-517](https://conduction.atlassian.net/browse/WOO-517) — "Zoeken in bestandsinhoud (document content full-text) — indexering-strategie kiezen + implementeren". Refinement, assigned to Ruben. Contains the full architectural context (team review found Solr not production-ready; alternatives considered: Elasticsearch, PostgreSQL FTS, lightweight extractor + inverted-index).
- **No archive gate on this change:** it can be archived as soon as the code lands + verify passes. There is no pending Ruben-answer dependency on `add-public-fulltext-search`.
- **When WOO-517 is decided:** a separate OpenSpec change (working title `add-document-content-search`) will additively extend the same endpoint with content-search capability. The flat envelope + `@self.schema` discriminator this change introduces stay unchanged; only the match surface grows.

## Scope

In scope:

- Promote the existing admin-only `SearchController::index` to a public endpoint with anonymous reachability and RBAC filtering identical to `/publications`.
- Add a `document` schema as a bundled schema in `lib/Settings/publication_register.json`, with seed data demonstrating realistic municipality + consultancy values.
- Extend the search assembly to return mixed publication/document rows in a flat envelope keyed by `@self.schema`.
- Embed a `publication: { id, slug, title }` summary on each document row so the frontend can link back without a second lookup. Field name is English (`title`, not Dutch `titel`) to match the publication schema's canonical property names and ADR-001's "Dutch government fields SHOULD use a mapping layer, do not hardcode Dutch field names as primary" rule.
- Apply the same `isObjectPublic()` anonymous-visibility filter publications use, ordered AFTER scoring/merge.

Explicitly **out of scope**:

- Any modification to `GET /publications` — it stays exactly as-is.
- **OC-internal admin UI for this endpoint.** The anonymous consumer of `/apps/opencatalogi/api/search` is an external public-portal frontend (e.g. the Tilburg WOO portal), not an OC-internal admin surface. OC's existing federation store (`SCH-OR-004`) is unaffected. No new OC-internal Vue view, search bar, or admin surface is added by this change; the WOO-506 use case is a headless-API consumption pattern.
- Lucene-style operator parsing (boolean OR/AND, phrase quotes, prefix wildcards) — deferred to a B3 follow-up change.
- Per-schema field weighting and `searchConfig` blocks — deferred.
- A `@self.relevance` field on responses — deferred unless the Solr backend is already producing it.

## Affected specs

- **search** — ADDED requirements `SCH-PFTS-001` … `SCH-PFTS-007`. The existing `SCH-OR-001` and `SCH-OR-002` (single-catalog passthrough, federated orchestrator) are NOT modified or removed; this change adds a new public surface alongside the existing federation orchestration.

## Affected code

- `lib/Controller/SearchController.php` — `index()` becomes public, anonymous-reachable, applies RBAC.
- `lib/Service/PublicationQueryService.php` (or a sibling helper) — extend the assembly to merge mixed publication/document rows.
- `lib/Settings/publication_register.json` — new `document` schema (bundled, not deployer-provided) and seed data.
- `appinfo/routes.php` — wire the public route `/api/search` to `SearchController::index` with `#[PublicPage]` annotation.

## Out-of-scope clarifications

- `/publications` endpoint remains the publication-only search; documents are addressable only via the new endpoint (or eventually via federation if/when the document schema is added to a federated catalog).
- The frontend's existing federation store path (`SCH-OR-004`) is unaffected. The new `/api/search` is a separate caller surface for anonymous public-portal use.

## Risks

- **Documents leak via the new endpoint.** Mitigation: the RBAC filter MUST apply the same `isObjectPublic()` logic to documents, and a document's effective visibility MUST also be gated by its linked publication's visibility (a document attached to an unpublished/depublished publication MUST NOT surface in anonymous results).
- **Backwards-compat break for admin callers of the old admin `/api/search`.** The absorb is intentional (the old admin form was deprecated per WOO-506's rationale — vendors still calling it are on a very old version and expected to switch). Existing admin-side consumers will see a different response shape after this lands: mixed publication + document rows via `@self.schema` discriminator, no more auth-required 401 for anonymous, and the response now surfaces documents alongside publications. Documented here so it's explicit; not blocking merge.
