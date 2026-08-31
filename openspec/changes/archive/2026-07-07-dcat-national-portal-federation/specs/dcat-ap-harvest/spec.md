## ADDED Requirements

### Requirement: National open-data portal harvest-source conformance and registration guidance (DCAT-NPF-001)

The instance-level DCAT document (`GET /api/dcat`, DCAT-002) MUST carry the
source-level metadata the national portal **data.overheid.nl (DONL)** harvester
requires to accept the instance as a catalog source: a resolvable
`dct:publisher` as a `foaf:Agent` (name and, when configured, the TOOI
organisation URI), `dct:license`, `dcat:contactPoint`, `dct:modified`, and
`foaf:homepage`. Values the instance cannot supply MUST be completed from the
configured owning Organisation using the DCAT-005 publisher fallback chain.

Admin settings (the existing `admin-settings` surface, DCAT-010) MUST display
the canonical harvest-source URL (`…/api/dcat`) and provide a
"Validate for data.overheid.nl" action that runs the DCAT-010 feed validator
with the DONL mandatory-property rule-set and reports violations before the
operator registers the URL. No active submit/push endpoint is introduced —
registration remains an operator-driven harvest-source configuration.

#### Scenario: instance document carries DONL source metadata

- **GIVEN** an instance with a configured owning Organisation and default license
- **WHEN** `GET /api/dcat` is requested
- **THEN** the instance-level `dcat:Catalog` MUST carry `dct:publisher`
  (`foaf:Agent`), `dct:license`, `dcat:contactPoint`, `dct:modified`, and
  `foaf:homepage`
- **AND** the TOOI organisation URI MUST appear on `dct:publisher` when configured

#### Scenario: admin validates the feed before registering with data.overheid.nl

- **GIVEN** an admin on the DCAT admin settings surface
- **WHEN** the admin runs "Validate for data.overheid.nl"
- **THEN** the canonical harvest-source URL (`…/api/dcat`) MUST be displayed
- **AND** any missing DONL-required source or dataset property MUST be reported
  per dataset (advisory, not a serving gate — consistent with DCAT-010)

### Requirement: High-Value Dataset (HVD) classification (DCAT-NPF-002)

The system MUST support opt-in, declarative High-Value Dataset classification
per the EU Open Data Directive Implementing Regulation (EU) 2023/138. A
publication MAY be classified as a High-Value Dataset; when it is, the
classification MUST be declarative: the `x-dcat` schema annotation (DCAT-004)
MAY carry an `hvd` block
naming the object property that supplies the HVD category and the applicable
legislation ELI, and a catalog MAY declare a default HVD category. When a
dataset resolves an HVD category, its `dcat:Dataset` MUST emit
`dcatap:hvdCategory` (constrained to the six ODD HVD categories via the bundled
value list) and `dcatap:applicableLegislation`. When no HVD category resolves,
no HVD triples MUST be emitted (HVD is opt-in). HVD classification MUST NOT be
hard-coded per schema in PHP.

#### Scenario: HVD-declared publication renders HVD triples

- **GIVEN** a publication whose schema declares `x-dcat.hvd` and whose object
  resolves the HVD category "Mobility"
- **WHEN** it is rendered as a `dcat:Dataset`
- **THEN** the dataset MUST carry `dcatap:hvdCategory` for "Mobility" as an
  authority URI
- **AND** MUST carry `dcatap:applicableLegislation` referencing Regulation (EU) 2023/138

#### Scenario: non-HVD publication emits no HVD triples

- **GIVEN** a publication with no resolvable HVD category and no catalog default
- **WHEN** it is rendered as a `dcat:Dataset`
- **THEN** the dataset MUST NOT contain any `dcatap:hvdCategory` or
  `dcatap:applicableLegislation` triple

### Requirement: Controlled DCAT theme binding with fail-safe (DCAT-NPF-003)

`dcat:theme` MUST be emitted as a controlled authority URI — the EU MDR
`data-theme` authority
(`http://publications.europa.eu/resource/authority/data-theme/*`) and/or
`overheid:thema` for WOO-overlapping values — resolved through a value list that
maps source values to URIs. A source theme value that does not resolve to a
controlled URI MUST NOT be emitted as a literal `dcat:theme` string; it MUST be
omitted from the feed and reported by the DCAT-010 validator. This upgrades the
soft mapping guidance in DCAT-005 to a MUST-bind-or-omit rule for the theme
axis, so DONL and data.europa.eu accept and re-federate the feed.

#### Scenario: mapped theme becomes an authority URI

- **GIVEN** a publication whose source theme value maps to the MDR data-theme
  "TRAN" (Transport)
- **WHEN** it is rendered as a `dcat:Dataset`
- **THEN** `dcat:theme` MUST be the MDR data-theme authority URI, not the raw
  source string

#### Scenario: unmapped theme is omitted, not leaked as a literal

- **GIVEN** a publication whose source theme value has no value-list mapping
- **WHEN** it is rendered as a `dcat:Dataset`
- **THEN** the dataset MUST NOT carry a free-text literal `dcat:theme`
- **AND** the DCAT-010 validator MUST report the unresolved theme for that dataset
