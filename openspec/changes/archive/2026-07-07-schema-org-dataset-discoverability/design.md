# Design: schema-org-dataset-discoverability

## Context

Verified at HEAD `4d8b395`:
- Schemas already declare `x-schema-org` CURIEs (ADR-048/051):
  `publication → schema:CreativeWork`, `catalog → schema:DataCatalog`,
  `listing → schema:DataFeed`, `organization → schema:Organization`
  (`lib/Settings/publication_register.json:98,296,504,725`).
- No schema.org JSON-LD is emitted anywhere (only DCAT RDF at `/api/dcat`).
- Public read endpoints already exist and enforce visibility via the OR
  `publicatiedatum <= now` RBAC predicate (PUB-001/PUB-002, DCAT-003).
- Attachment→public-download-URL resolution already exists in the DCAT
  distribution machinery (DCAT-006) and download-service.

## Decisions

### D1 — Render from `x-schema-org`, never a new marker system
The serializer reads the object's schema `x-schema-org` CURIE and maps it to the
`@type`. This keeps ADR-048's "schema-level `x-schema-org` only, CURIE
`schema:X`" as the single source of truth. The app MUST NOT add a parallel
marker registry.

### D2 — Content-negotiated representation, not a new page
schema.org JSON-LD is a *representation* of the existing publication/catalog
resources: `GET /publications/{…}` and the catalog endpoint gain
`Accept: application/ld+json` (and a `?format=schema`/`.jsonld` fallback for
crawlers that cannot set headers), returning the schema.org node. This mirrors
the DCAT content-negotiation pattern (DCAT-007) and reuses the same OR
object-search path and RBAC visibility. No new storage, no new visibility rule.

### D3 — `schema:Dataset` is an election, not a default
The default `@type` is the marker value (`schema:CreativeWork` for
publications). An open-data catalog/schema MAY elect `schema:Dataset` (a
per-catalog/per-schema config flag), because Google Dataset Search only indexes
`Dataset`. When elected:
- `schema:distribution` → one `schema:DataDownload` per publicly accessible
  attachment (reusing the DCAT-006 download-URL + media-type resolution),
- `schema:includedInDataCatalog` → the catalog's `schema:DataCatalog` node,
- `schema:license`, `schema:dateModified`, `schema:creator`/`publisher`,
  `schema:keywords` completed from the object + catalog defaults (reusing the
  DCAT-005 fallback chain).

WOO document publications stay `schema:CreativeWork` — they are documents, not
datasets — so the election keeps the two audiences correct.

### D4 — Discoverability: embeddable snippet + sitemap
The JSON-LD is exposed as a `<script type="application/ld+json">`-ready document
so the external frontend embeds it in the publication page `<head>` (where
Google Dataset Search reads it). The existing WOO sitemaps already list
publication URLs; no new crawl surface is required beyond making the
representation reachable at the canonical public URL.

## Requirement map

| ID | Capability: structured-data-discoverability |
|----|---------------------------------------------|
| SDD-001 | schema.org JSON-LD representation of a publication from `x-schema-org` |
| SDD-002 | schema.org `DataCatalog` representation of a catalog with `dataset` entries |
| SDD-003 | Open-data election to `schema:Dataset` with `DataDownload` + `includedInDataCatalog` |
| SDD-004 | JSON-LD is crawler-discoverable and frontend-embeddable |

## Testing

Newman assertions: `Accept: application/ld+json` on a publication returns a
valid schema.org node whose `@type` matches the schema marker; a
`schema:Dataset`-elected catalog yields datasets with `distribution` +
`includedInDataCatalog`; the catalog representation lists only publicly visible
publications. Google's Rich Results / Dataset structured-data expectations
(required `name`, `description`, at least one `distribution`) are asserted on the
elected shape. `@e2e exclude` — machine representation, no browser UI surface of
OpenCatalogi's own (the embedding frontend is external).
