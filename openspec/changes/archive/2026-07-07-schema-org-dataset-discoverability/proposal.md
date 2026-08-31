---
kind: mixed
depends_on: []
---

# Proposal: schema-org-dataset-discoverability

## Summary

Emit **schema.org** structured data (JSON-LD) for publications and catalogs so
that generic web crawlers — above all **Google Dataset Search** — discover and
index OpenCatalogi's open-data publications. The emission is driven entirely by
the `x-schema-org` markers already declared on the OpenRegister schemas
(ADR-048/051) — the change *consumes* that marker infrastructure rather than
adding any new semantic machinery:

- a content-negotiated schema.org JSON-LD representation of a **publication**
  (`SDD-001`), typed from its `x-schema-org` marker;
- a schema.org **`DataCatalog`** representation of a **catalog** with
  `schema:dataset` entries (`SDD-002`), mirroring the DCAT catalog but in the
  vocabulary Google indexes;
- for open-data catalogs, publications typed as **`schema:Dataset`** with
  `schema:distribution` (`schema:DataDownload`) built from their attachments and
  a `schema:includedInDataCatalog` backlink (`SDD-003`) — the exact shape Google
  Dataset Search requires;
- discoverability wiring (`SDD-004`) so the JSON-LD is reachable by crawlers and
  embeddable by the external WOO/open-data frontends that render the HTML pages.

## Motivation

OpenCatalogi is API-first: verified at HEAD, `templates/` contains only
`index.php` + `settings/`, and public publication HTML is rendered by external
frontends (e.g. tilburg-woo-ui) consuming the JSON API. That means **no**
schema.org / `Dataset` / `DataCatalog` JSON-LD is emitted anywhere today (grep
for `schema.org`, `"@type":"Dataset"`, `application/ld+json` in
`templates`/`src`/`lib` returns nothing but the DCAT RDF layer, which serves RDF
at `/api/dcat`, not schema.org JSON-LD in a page `<head>`).

Discovery is table-stakes for the competitor set. Intelligence (Specter) ranks
opencatalogi against **CKAN**, **Socrata**, **Magda**, **Dataverse** and
**data.overheid.nl**. Dataverse merged **Croissant / schema.org Dataset markup
into its dataset landing-page `<head>` specifically at the Google Dataset Search
team's request**; CKAN/Socrata portals ship schema.org Dataset markup as a
matter of course. Without schema.org output, an OpenCatalogi publication is
invisible to Google Dataset Search even when its DCAT feed is perfect — DCAT-AP
RDF is for harvesters, schema.org JSON-LD is for the open web.

The foundation is already in place and unused: the bundled schemas declare
`x-schema-org` on every type — `publication → schema:CreativeWork`,
`catalog → schema:DataCatalog`, `listing → schema:DataFeed`,
`organization → schema:Organization` (verified in
`lib/Settings/publication_register.json`). This change turns those declarations
into emitted JSON-LD.

## Goals

1. Serve a schema.org JSON-LD representation of a publication and a catalog,
   content-negotiated (`Accept: application/ld+json`) or via a dedicated
   `?format=schema` / `.jsonld` affordance on the existing public read
   endpoints — reusing the OR object-search path and the RBAC
   `publicatiedatum <= now` visibility rule (no new storage, no new visibility).
2. Let an open-data catalog/schema elect the `schema:Dataset` type so its
   publications carry the Google-Dataset-Search-required shape
   (`name`, `description`, `distribution` → `DataDownload`, `license`,
   `includedInDataCatalog`, `dateModified`, `creator`/`publisher`).
3. Make the JSON-LD crawler-discoverable and embeddable by the external frontend
   (in the page `<head>`), so real Google Dataset Search indexing is achievable.

## Non-Goals

- **No new HTML rendering app.** OpenCatalogi stays API-first; it emits the
  JSON-LD document, the external frontend injects it into `<head>`. The
  representation is a machine format, not a new UI.
- **No new semantic-marker system.** `x-schema-org` (ADR-048/051) is the single
  source of type truth; this change only renders from it. It MUST NOT introduce
  an app-local marker registry.
- **Not DCAT.** The DCAT-AP-NL RDF feed (`dcat-ap-harvest`) stays as-is for
  government/EU harvesters; schema.org JSON-LD is the parallel open-web surface.
- **No Croissant ML export** — noted as a possible follow-up, out of scope here.

## High-Level Approach

A small serializer maps an OR object + its `x-schema-org` marker to a schema.org
JSON-LD node, reusing the DCAT layer's attachment→download-URL resolution for
`schema:distribution`. Publications resolve to their marker type by default
(`schema:CreativeWork`) and to `schema:Dataset` when the catalog/schema elects
it. The catalog representation lists its publicly visible publications as
`schema:dataset`. Emission is content-negotiated on the existing public
endpoints; a `<script type="application/ld+json">` snippet is exposed for the
frontend to embed.
