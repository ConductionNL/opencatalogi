# structured-data-discoverability Specification

## Purpose

@e2e exclude pure backend/API spec — every scenario asserts a machine-readable schema.org JSON-LD representation (publication + catalog nodes, content negotiation, the elected `schema:Dataset` shape, and the embeddable single-document/CORS contract) served over HTTP; OpenCatalogi renders no browser UI of its own for this surface (the embedding WOO/open-data frontend is external), so it is covered by unit + Newman API tests instead.

OpenCatalogi emits schema.org structured data (JSON-LD) for publications and catalogs so generic web crawlers — above all Google Dataset Search — discover and index its open-data publications. The emission is a read-only rendering layer over the `x-schema-org` markers already declared on the OpenRegister schemas (ADR-048/051): it reuses the same OR object-search path and RBAC `publicatiedatum <= now` visibility the public publications API uses, introducing no bespoke storage, visibility rule, or marker registry (hydra ADR-022). It is the parallel open-web surface to the DCAT-AP-NL RDF feed: DCAT is for government/EU harvesters, schema.org JSON-LD is what the open web indexes.

## Requirements
### Requirement: schema.org JSON-LD representation of a publication (SDD-001)

The system MUST serve a schema.org JSON-LD representation of a publicly visible
publication, content-negotiated via `Accept: application/ld+json` on the
existing public publication read endpoint (and via a `?format=schema` / `.jsonld`
fallback for crawlers that cannot set headers). The node's `@type` MUST be
derived from the publication schema's `x-schema-org` marker (ADR-048/051) — no
app-local marker registry may be introduced. The representation MUST reuse the
OpenRegister object-search path and the `publicatiedatum <= now` RBAC visibility
predicate; a non-visible publication MUST NOT be served.

#### Scenario: publication served as schema.org JSON-LD

- **GIVEN** a publicly visible publication whose schema declares
  `x-schema-org: schema:CreativeWork`
- **WHEN** it is requested with `Accept: application/ld+json`
- **THEN** the response MUST be a schema.org JSON-LD node with
  `@type` `CreativeWork` and `@context` `https://schema.org`
- **AND** it MUST carry at least `name`, `description`, `url`, and `dateModified`

#### Scenario: non-visible publication is not served as JSON-LD

- **GIVEN** a publication with a future `publicatiedatum`
- **WHEN** its schema.org representation is requested anonymously
- **THEN** the response MUST NOT disclose the publication (consistent with
  PUB-001/DCAT-003 visibility)

### Requirement: schema.org DataCatalog representation of a catalog (SDD-002)

The system MUST serve a schema.org `DataCatalog` representation of a catalog,
content-negotiated on the existing public catalog endpoint, typed from the
catalog schema's `x-schema-org` marker (`schema:DataCatalog`). It MUST list each
publicly visible publication in the catalog as a `schema:dataset` entry
referencing that publication's canonical public URL. Only publicly visible
publications MUST appear.

#### Scenario: catalog served as schema.org DataCatalog

- **GIVEN** a catalog with 3 publicly visible publications
- **WHEN** the catalog is requested with `Accept: application/ld+json`
- **THEN** the response MUST be a `@type` `DataCatalog` node
- **AND** it MUST reference exactly 3 `dataset` entries by their canonical URLs

### Requirement: Open-data election to schema:Dataset with distributions (SDD-003)

A catalog or schema MUST be able to elect the `schema:Dataset` type for its
publications (because Google Dataset Search indexes only `Dataset`). When
elected, each publicly visible publication MUST be rendered as a
`schema:Dataset` carrying `name`, `description`, `license`, `dateModified`, a
`publisher`/`creator`, and one `schema:distribution` (`schema:DataDownload`) per
publicly accessible attachment — reusing the DCAT-006 download-URL and
media-type resolution — plus a `schema:includedInDataCatalog` backlink to the
catalog's `DataCatalog` node. When not elected, the publication MUST keep its
`x-schema-org` marker type (e.g. `CreativeWork` for WOO documents).

#### Scenario: elected open-data publication carries dataset shape

- **GIVEN** a catalog that elects `schema:Dataset` and a publication with 2
  publicly accessible attachments (PDF, CSV)
- **WHEN** the publication is requested as JSON-LD
- **THEN** the node MUST be `@type` `Dataset`
- **AND** it MUST carry 2 `distribution` entries typed `DataDownload` with
  working `contentUrl` and `encodingFormat`
- **AND** it MUST carry `includedInDataCatalog` referencing the catalog

#### Scenario: non-elected catalog keeps the marker type

- **GIVEN** a WOO catalog that does not elect `schema:Dataset`
- **WHEN** one of its publications is requested as JSON-LD
- **THEN** the node `@type` MUST be the schema's `x-schema-org` marker
  (`CreativeWork`), not `Dataset`

### Requirement: JSON-LD is crawler-discoverable and frontend-embeddable (SDD-004)

The schema.org representation MUST be reachable at the publication's and
catalog's canonical public URL so that crawlers and the external
WOO/open-data frontend can obtain it, and the response MUST be shaped so the
frontend can embed it directly in the page `<head>` as a
`<script type="application/ld+json">` block (a single well-formed JSON-LD
document, no wrapping envelope). The representation MUST be public
(no authentication) with CORS headers per the cross-origin-api-access spec.

#### Scenario: representation is embeddable as-is

- **GIVEN** a publicly visible publication
- **WHEN** its schema.org JSON-LD is fetched cross-origin
- **THEN** the response MUST be a single JSON-LD document (no pagination or
  result envelope) suitable for direct `<script type="application/ld+json">`
  embedding
- **AND** it MUST carry CORS headers and require no authentication

