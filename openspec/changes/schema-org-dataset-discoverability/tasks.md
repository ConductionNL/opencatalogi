# Tasks: schema-org-dataset-discoverability

This change is `kind: mixed` (ADR-032): a per-catalog/per-schema `schema:Dataset`
election flag (config) plus the serializer/route code that renders schema.org
JSON-LD from the existing `x-schema-org` markers. The election flag lands first.

- [ ] Freeze the delta spec under
  `openspec/changes/schema-org-dataset-discoverability/specs/structured-data-discoverability/spec.md`
  (ADDED SDD-001…004); confirm `openspec validate schema-org-dataset-discoverability --strict` is green
  - Spec ref: specs/structured-data-discoverability/spec.md (this change)
  - Acceptance: validator reports valid
- [ ] Add a per-catalog/per-schema `schema:Dataset` election flag to the catalog
  DCAT/discovery config (reuse the catalog config surface; no new settings framework)
  - Spec ref: SDD-003
  - Acceptance: flag present, default off; when off, publications keep their
    `x-schema-org` marker type
- [ ] Add a schema.org serializer that maps an OR object + its schema
  `x-schema-org` CURIE to a schema.org JSON-LD node; reuse the DCAT-006
  attachment→download-URL + media-type resolution for `distribution`
  - Spec ref: SDD-001, SDD-003
  - Acceptance: `@type` equals the marker value (or `Dataset` when elected);
    distributions typed `DataDownload` with working `contentUrl`
- [ ] Content-negotiate `application/ld+json` (and `?format=schema`/`.jsonld`)
  on the existing public publication and catalog endpoints; enforce the OR
  `publicatiedatum <= now` visibility predicate; public + CORS
  - Spec ref: SDD-001, SDD-002, SDD-004
  - Acceptance: visible publication/catalog returns a single well-formed JSON-LD
    document; non-visible publication not disclosed; CORS present; no auth
- [ ] Catalog representation lists publicly visible publications as
  `schema:dataset`; elected catalogs render publications as `schema:Dataset`
  with `includedInDataCatalog`
  - Spec ref: SDD-002, SDD-003
  - Acceptance: dataset count matches visible publications; backlink present
- [ ] Newman: assert marker-typed node, elected `Dataset` shape (name +
  description + ≥1 distribution + includedInDataCatalog), and the
  embeddable-single-document contract
  - Spec ref: SDD-001…004
  - Acceptance: Newman collection green; Google Dataset Search required fields
    present on the elected shape
