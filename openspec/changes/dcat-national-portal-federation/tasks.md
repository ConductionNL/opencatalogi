# Tasks: dcat-national-portal-federation

This change is `kind: mixed` (ADR-032): a bundled value list + `x-dcat`
annotation extension (config) plus the rendering/validation code that consumes
them. Tasks order the value-list/annotation declarations before the code.

- [ ] Freeze the delta spec under
  `openspec/changes/dcat-national-portal-federation/specs/dcat-ap-harvest/spec.md`
  (ADDED DCAT-NPF-001…003); confirm `openspec validate dcat-national-portal-federation --strict` is green
  - Spec ref: specs/dcat-ap-harvest/spec.md (this change)
  - Acceptance: validator reports valid; existing DCAT-001…010 untouched
- [ ] Bundle two value lists (HVD categories → authority URIs; EU MDR
  `data-theme` + `overheid:thema` → source-value map) in the OpenCatalogi
  register bundle, self-identifying as reference data
  - Spec ref: DCAT-NPF-002, DCAT-NPF-003
  - Acceptance: the six ODD HVD categories and the MDR data-theme authority set
    are present and resolvable at render time; no real municipality data
- [ ] Extend the `x-dcat` annotation contract (DCAT-004) with an optional `hvd`
  block (`categoryProperty`, `legislation`) and a `theme` value-list reference;
  add a catalog-level default HVD category to the catalog DCAT config
  - Spec ref: DCAT-NPF-002, DCAT-NPF-003
  - Acceptance: annotation parses; absence yields today's behaviour (no HVD, no
    change to existing feeds)
- [ ] `DcatMappingService` / `DcatService`: render `dcatap:hvdCategory` +
  `dcatap:applicableLegislation` when an HVD category resolves; bind
  `dcat:theme` to authority URIs via the value list and OMIT unresolved themes
  (never emit a literal)
  - Spec ref: DCAT-NPF-002, DCAT-NPF-003
  - Acceptance: HVD-declared dataset carries both HVD triples; unmapped theme
    absent from the graph in all three serializations (JSON-LD/Turtle/RDF-XML)
- [ ] Instance-level source metadata: ensure `GET /api/dcat`'s `dcat:Catalog`
  carries `dct:publisher`/`dct:license`/`dcat:contactPoint`/`dct:modified`/`foaf:homepage`,
  completing from the owning Organisation via the DCAT-005 fallback chain
  - Spec ref: DCAT-NPF-001
  - Acceptance: all five source properties present on the instance catalog node
- [ ] Admin settings: display the canonical harvest-source URL and add the
  "Validate for data.overheid.nl" action (DONL rule-set over the existing
  DCAT-010 validator); report HVD/theme/source violations per dataset
  - Spec ref: DCAT-NPF-001
  - Acceptance: URL shown; validation lists violations; feed still served
    (validation advisory)
- [ ] Newman: assert instance source metadata, HVD triples on an HVD-declared
  seed publication, and no-literal-theme on an unmapped-theme seed publication
  - Spec ref: DCAT-NPF-001…003
  - Acceptance: Newman collection green against a seeded catalog
