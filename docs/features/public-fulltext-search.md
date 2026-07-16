# Publieke fulltext search — publicaties + documenten

Public, RBAC-filtered full-text search endpoint that returns publications **and** documents as mixed-typed rows in a flat envelope, discriminated by `@self.schema`. Anonymous-reachable — no login required.

## Standards references

- **WOO (Wet open overheid)** — public discoverability of published documents.
- **GEMMA Catalogus / Data-catalogus** — cross-organization publication discovery layer.
- **ADR-022 (Consume OpenRegister abstractions)** — search fans out through OR's `zoeken-filteren`, does not re-implement query building.

## Overview

`GET /apps/opencatalogi/api/search` absorbs the previously admin-only search endpoint into a public surface. Rather than exposing a per-object-type endpoint (publications-only + documents-only), it returns a single flat result array containing both types. Frontends switch rendering on the `@self.schema` field per row.

Every document row includes an embedded `publication: { id, slug, title }` summary so a "document card" can link back to its parent publication without a second API roundtrip. Documents with no linked publication are suppressed from the anonymous result set.

Visibility is enforced server-side via `isObjectPublic()` post-scoring: `publicatiedatum <= now` (and `depublicatiedatum` in the future) — no drafts or depublished content leak into the response. Documents inherit their linked publication's visibility transitively.

## Key capabilities

- **Public endpoint** — `#[PublicPage]` + `#[NoCSRFRequired]`; anonymous callers reach it, no session required (SCH-PFTS-001).
- **Mixed envelope with `@self.schema` discriminator** — publications + documents share one flat `results[]` array; each row carries `@self.schema` set to its schema slug (SCH-PFTS-002).
- **Embedded publication summary on document rows** — `publication: { id, slug, title }` on every document row for direct-linking (SCH-PFTS-003).
- **Post-scoring visibility filter** — `isObjectPublic()` applied after search + merge; scoring decisions run against the full corpus before visibility is enforced (SCH-PFTS-004).
- **Transitive publication visibility** — documents whose linked publication fails `isObjectPublic()` are dropped (SCH-PFTS-004).
- **Companion `/publications` endpoint unchanged** — the existing publication-scoped endpoint keeps its exact contract (no field / admission / shape drift).
- **Config route enforces scope** — the endpoint returns whatever schemas the catalog's `registers` + `schemas` array is configured for. Freshly-seeded catalogs contain only `publication`; admins can broaden by adding `document` (or other schemas) to the catalog config.

## Related documentation

- [openwoo full-text search docs](https://openwoo.conduction.nl/docs/Integrations/fulltext-search/) — endpoint-facing reference for external API consumers.
- Follow-up: [`add-document-content-search`](../../openspec/changes/add-document-content-search/) — WOO-517, adds opt-in `_content=true` that widens matching to include document body text (PDF/DOCX/XLSX body extracted by OpenRegister's text-extraction pipeline).

## OpenSpec provenance

Shipped by change [`add-public-fulltext-search`](../../openspec/changes/archive/2026-07-16-add-public-fulltext-search/) (archived 2026-07-16). Requirements landed in canonical spec `openspec/specs/search/spec.md` as `SCH-PFTS-001..007` (plus MODIFIED `SCH-OR-003` for the anonymous-reachability + multi-schema delegation clauses).

Jira parent: [WOO-506](https://conduction.atlassian.net/browse/WOO-506). Follow-up: [WOO-517](https://conduction.atlassian.net/browse/WOO-517) for document body-text content search.
