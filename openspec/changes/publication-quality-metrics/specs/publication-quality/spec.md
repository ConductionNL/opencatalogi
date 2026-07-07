## ADDED Requirements

### Requirement: Per-publication metadata-quality score over MQA dimensions (PQM-001)

The system MUST compute a metadata-quality score for a publication by inspecting
the `dcat:Dataset` node the DCAT layer already renders for it (reusing
`DcatMappingService`/`DcatService`; no second mapping and no bespoke query
layer). The score MUST break down into the five DCAT-AP/EU-MQA dimensions —
findability, accessibility, interoperability, reusability, contextuality — each
with a sub-score, combined into a weighted 0–100 total. Dimension weights MUST be
admin-configurable, defaulting to the EU MQA weights. The score MUST reflect only
what a harvester sees in the dataset (it MUST NOT read fields that are not
emitted to the feed).

#### Scenario: fully-populated publication scores high

- **GIVEN** a publication whose dataset carries publisher, license, bound theme,
  keywords, and a reachable non-proprietary distribution
- **WHEN** its quality score is computed
- **THEN** all five dimension sub-scores MUST be non-zero
- **AND** the total MUST be a 0–100 value in the high band

#### Scenario: missing license and theme penalise the right dimensions

- **GIVEN** an otherwise-complete publication with no `dct:license` and no bound
  `dcat:theme`
- **WHEN** its quality score is computed
- **THEN** the reusability and findability sub-scores MUST be reduced
- **AND** the breakdown MUST name `dct:license` and `dcat:theme` as the
  missing/invalid properties

### Requirement: Optional DQV exposure of the score in the DCAT feed (PQM-002)

When enabled per catalog, the DCAT serializer MUST attach the quality score to
each `dcat:Dataset` as W3C Data Quality Vocabulary `dqv:hasQualityMeasurement`
nodes (one per dimension plus the total), so national/EU MQA harvesters can read
the score without recomputation. DQV exposure MUST default to off and MUST be
additive to the existing graph (no existing DCAT triple changes).

#### Scenario: DQV nodes present only when enabled

- **GIVEN** a catalog with DQV exposure enabled
- **WHEN** its DCAT document is generated
- **THEN** each `dcat:Dataset` MUST carry `dqv:hasQualityMeasurement` nodes for
  the five dimensions and the total

#### Scenario: DQV absent by default

- **GIVEN** a catalog with DQV exposure at its default (off)
- **WHEN** its DCAT document is generated
- **THEN** no `dqv:` node MUST appear, and the pre-existing DCAT triples MUST be
  byte-identical to before this change

### Requirement: Per-catalog quality dashboard and roll-up (PQM-003)

The system MUST expose an authenticated per-catalog quality roll-up — average
score, score distribution, worst-N datasets, and the aggregate missing/invalid
property breakdown — reusing the `publication-usage-analytics` stats API and
dashboard-widget surface (ANA-005/ANA-006). The roll-up MUST aggregate over the
OpenRegister object-search path (like `UsageCounterService.aggregateCatalog`),
not a bespoke store. Endpoints MUST be authenticated (not public) and MUST guard
per-object reads (no IDOR), consistent with the analytics stats API.

#### Scenario: catalog roll-up returns average and worst-N

- **GIVEN** a catalog with publications of varying quality
- **WHEN** an authorised publisher requests the catalog quality roll-up
- **THEN** the response MUST include the average score, the distribution, and the
  worst-N datasets with their dimension breakdowns

#### Scenario: quality stats require authorisation

- **GIVEN** an anonymous caller
- **WHEN** it requests the catalog quality roll-up
- **THEN** the request MUST NOT succeed (the endpoint is authenticated, mirroring
  the usage-analytics stats API)

### Requirement: Score is advisory, privacy-safe, and derived-storage-only (PQM-004)

The quality score MUST be advisory: it MUST NOT gate publishing, depublishing, or
retention, and MUST NOT alter the OR `publicatiedatum <= now` visibility
predicate. Any persistence for roll-ups MUST be a derived metric object on
OpenRegister (the `usageCounter`-style pattern), recomputed on publication
save/retention evaluation; it MUST NOT introduce a bespoke database table and
MUST NOT store any reader/request/PII data (the score is over metadata only).

#### Scenario: score never blocks publishing

- **GIVEN** a publication whose quality score is low
- **WHEN** it is published (its `publicatiedatum` reached)
- **THEN** it MUST become publicly visible exactly as it would without this
  change — the score MUST NOT block or delay publication

#### Scenario: no PII in quality persistence

- **GIVEN** quality roll-ups are persisted
- **WHEN** the stored metric objects are inspected
- **THEN** they MUST contain only derived metadata-quality figures
- **AND** MUST NOT contain any IP, user agent, session, or reader identity
