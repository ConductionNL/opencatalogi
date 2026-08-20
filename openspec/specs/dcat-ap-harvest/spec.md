---
status: done
---

# dcat-ap-harvest Specification

## Purpose

@e2e exclude pure backend/API spec — all scenarios test server-side DCAT-AP-NL document generation (JSON-LD / Turtle / RDF-XML over HTTP), content negotiation, mapping, and catalog-scoped OR object queries; no browser-observable UI surface; covered by Newman API tests instead.

OpenCatalogi exposes its publicly visible publications as a DCAT-AP-NL 3.0 harvest feed so external harvesters (e.g. data.overheid.nl) can index them. The feed is a read-only rendering layer over the same OR object-search path the public publications API uses: visibility is governed by OR's RBAC `publicatiedatum <= now` predicate (the removed object-level `@self.published` predicate is gone), and no bespoke storage, visibility rule, or query layer is introduced (hydra ADR-022).
## Requirements
### Requirement: Per-catalog DCAT-AP-NL document endpoint (DCAT-001)
The system MUST serve `GET /api/catalogs/{catalogSlug}/dcat` returning a
DCAT-AP-NL 3.0 catalog document for the catalog: one `dcat:Catalog` node with
a `dcat:dataset` entry per publicly visible publication in that catalog. The
endpoint MUST be public (no authentication), MUST include CORS headers per the
cross-origin-api-access spec, and MUST be registered in `appinfo/routes.php`.

#### Scenario: Harvest a catalog as JSON-LD
- GIVEN a catalog "woo-besluiten" with 12 publicly visible publications
- WHEN `GET /api/catalogs/woo-besluiten/dcat` is requested with
  `Accept: application/ld+json`
- THEN the response MUST be `200` with `Content-Type: application/ld+json`
- AND the body MUST contain one `dcat:Catalog` node and 12 `dcat:Dataset`
  nodes referenced via `dcat:dataset`
- AND the document MUST declare the DCAT-AP-NL `@context`/profile

#### Scenario: DCAT disabled for a catalog
- GIVEN a catalog whose `hasDcat` configuration is `false` (the default)
- WHEN its DCAT endpoint is requested
- THEN the response MUST be `404` with a descriptive error body
- AND the catalog MUST NOT appear in the instance-level DCAT document

#### Scenario: Unknown catalog slug
- GIVEN no catalog with slug "nope" exists
- WHEN `GET /api/catalogs/nope/dcat` is requested
- THEN the response MUST be `404` with a descriptive error (consistent with
  PUB-011)

### Requirement: Instance-level DCAT catalog document (DCAT-002)
The system MUST serve `GET /api/dcat` returning a DCAT-AP-NL document that
lists every DCAT-enabled catalog on the instance as a `dcat:Catalog`
(referencing its per-catalog document), so a harvester needs to know only one
URL per instance.

#### Scenario: Instance document lists enabled catalogs only
- GIVEN 3 catalogs of which 2 have `hasDcat: true`
- WHEN `GET /api/dcat` is requested
- THEN the document MUST contain exactly 2 `dcat:Catalog` entries
- AND each entry MUST reference its `GET /api/catalogs/{slug}/dcat` document

#### Scenario: Publisher metadata on the instance document
- GIVEN the instance has a configured owning Organisation
- WHEN `GET /api/dcat` is requested
- THEN the instance-level `dcat:Catalog` MUST carry `dct:publisher` as a
  `foaf:Agent` derived from that Organisation (name, and TOOI organisation
  URI when configured)

### Requirement: Only publicly visible objects appear in the feed (DCAT-003)
A publication MUST appear in any DCAT document **iff** it is publicly visible
per the OR RBAC `publicatiedatum <= now` predicate — the identical rule used by
the public publications API (PUB-001) and sitemaps (WOO-001). The DCAT layer MUST NOT
implement its own visibility logic, and querying MUST delegate to OR object
search with the catalog's configured registers/schemas (PUB-003); no bespoke
query layer (hydra ADR-022).

#### Scenario: Unpublished object excluded
- GIVEN a publication in a DCAT-enabled catalog with no `publicatiedatum`
  date (or a future one)
- WHEN the catalog DCAT document is generated
- THEN that publication MUST NOT appear as a `dcat:Dataset`

#### Scenario: Depublished object disappears from the feed
- GIVEN a publication that was harvested yesterday and is depublished today
- WHEN the catalog DCAT document is generated
- THEN the publication MUST be absent from the document
- AND the document's `Last-Modified` MUST advance so conditional harvesters
  re-fetch

### Requirement: Schema-driven DCAT mapping via `x-dcat` annotation (DCAT-004)
The publication-schema-to-DCAT property mapping MUST be declared on the
OpenRegister schema via an `x-dcat` extension (class + property map),
mirroring how `x-openregister-lifecycle` and `x-openregister-notifications`
declare behaviour on schemas. Schemas without an `x-dcat` annotation MUST fall
back to a conservative built-in default mapping (title, description, modified,
landing page); a schema MAY opt out entirely with `"x-dcat": false`. The
mapping MUST NOT be hard-coded per schema in PHP.

#### Scenario: Annotated schema controls the mapping
- GIVEN a publication schema declaring
  `"x-dcat": { "mapping": { "dct:title": "naam", "dcat:keyword": "tags[]" } }`
- WHEN one of its objects is rendered as a `dcat:Dataset`
- THEN `dct:title` MUST carry the object's `naam` value
- AND each element of `tags` MUST become a separate `dcat:keyword`

#### Scenario: Unannotated schema uses the default mapping
- GIVEN a schema in a DCAT-enabled catalog without `x-dcat`
- WHEN its objects are rendered
- THEN they MUST appear as `dcat:Dataset` nodes carrying at least
  `dct:title`, `dct:description` (when available), `dct:modified`, and
  `dcat:landingPage`

#### Scenario: Opted-out schema excluded
- GIVEN a schema declaring `"x-dcat": false`
- WHEN the catalog DCAT document is generated
- THEN no object of that schema appears in the document

### Requirement: DCAT-AP-NL mandatory-property completion (DCAT-005)
Every emitted `dcat:Dataset` MUST satisfy the DCAT-AP-NL mandatory
properties. Values the object itself cannot supply MUST be completed from
catalog-level configuration (default license, contactPoint, publisher) and,
failing that, from the catalog's owning Organisation. Theme values SHOULD be
emitted as TOOI/overheid-thema taxonomy URIs when the source value maps to
one.

#### Scenario: Publisher fallback chain
- GIVEN a publication object with no publisher field
- AND the catalog has a configured owning Organisation "Gemeente Tilburg"
- WHEN the dataset is rendered
- THEN `dct:publisher` MUST be a `foaf:Agent` for "Gemeente Tilburg"

#### Scenario: Default license applied
- GIVEN a catalog configured with default license
  `http://creativecommons.org/publicdomain/zero/1.0/`
- AND a publication without a license field
- WHEN its distributions are rendered
- THEN each `dcat:Distribution` MUST carry that `dct:license` URI

### Requirement: Attachments rendered as distributions with stable IRIs (DCAT-006)
Each publicly accessible attachment of a publication MUST be rendered as a
`dcat:Distribution` whose `dcat:downloadURL` is the existing public download
URL (PUB-007 / download-service) and whose format is emitted as
`dct:format`/`dcat:mediaType`. Dataset IRIs MUST be the publication's
existing canonical public URL (PUB-002). IRIs MUST be stable across requests
so harvesters can dedupe and update rather than duplicate.

#### Scenario: Publication with two attachments
- GIVEN a publicly visible publication with 2 published file attachments
  (PDF and CSV)
- WHEN the dataset is rendered
- THEN it MUST contain 2 `dcat:Distribution` nodes
- AND each MUST carry the working public `dcat:downloadURL` and the correct
  `dcat:mediaType` (`application/pdf`, `text/csv`)

#### Scenario: IRI stability across harvests
- GIVEN a dataset harvested on two consecutive days without changes
- WHEN both documents are compared
- THEN the dataset and distribution IRIs MUST be byte-identical

### Requirement: Content negotiation across RDF serializations (DCAT-007)
DCAT endpoints MUST support `application/ld+json` (default), `text/turtle`,
and `application/rdf+xml` via the `Accept` header, and MUST honour an
equivalent `?format=jsonld|turtle|rdfxml` query parameter for harvesters that
cannot set headers. All serializations MUST express the same graph.

#### Scenario: Turtle via Accept header
- GIVEN a DCAT-enabled catalog
- WHEN its DCAT endpoint is requested with `Accept: text/turtle`
- THEN the response MUST be `200` with `Content-Type: text/turtle`
- AND parse as valid Turtle containing the same dataset count as the JSON-LD
  form

#### Scenario: Format query parameter overrides
- GIVEN a request with `Accept: */*` and `?format=rdfxml`
- WHEN the endpoint responds
- THEN the response MUST be `application/rdf+xml`

#### Scenario: Unsupported format rejected
- GIVEN a request with `?format=excel`
- WHEN the endpoint responds
- THEN the response MUST be `406` listing the supported serializations

### Requirement: Harvester-grade pagination and caching (DCAT-008)
Per-catalog DCAT documents MUST paginate the dataset list at the same ceiling
as sitemaps (1000 entries per page, WOO-005) using `hydra:PagedCollection`
(`hydra:next`/`hydra:previous`), and MUST serve `Last-Modified` and `ETag`
headers honouring conditional requests with `304 Not Modified`, so daily
harvester polls of unchanged catalogs are near-free.

#### Scenario: Large catalog paginates
- GIVEN a DCAT-enabled catalog with 2,400 publicly visible publications
- WHEN page 1 is requested
- THEN it MUST contain 1000 datasets and a `hydra:next` link
- AND following `hydra:next` twice MUST yield the remaining 1,400 with no
  duplicates or gaps

#### Scenario: Conditional re-harvest of unchanged catalog
- GIVEN a harvester holding the `ETag` from yesterday's harvest
- AND no publication in the catalog changed since
- WHEN it requests the document with `If-None-Match`
- THEN the response MUST be `304` with no body

### Requirement: Federation directory advertises the DCAT endpoint (DCAT-009)
The Listing schema MUST gain an optional `dcatEndpoint` property, and
DirectoryService MUST populate it for DCAT-enabled catalogs when serving and
broadcasting the directory — extending the existing federation
directory/listing machinery (FED-009) so remote instances and aggregators
discover where to harvest. Broadcast transport, retry, and dead-letter
behaviour MUST remain exactly as specified by FED-OR-001/FED-OR-002 (no new
broadcast channel or cron).

#### Scenario: Directory entry carries the harvest URL
- GIVEN a DCAT-enabled catalog listed in this instance's directory
- WHEN a remote instance fetches the directory
- THEN the catalog's listing MUST include `dcatEndpoint` with the absolute
  per-catalog DCAT URL

#### Scenario: Disabled catalog advertises nothing
- GIVEN a catalog with `hasDcat: false`
- WHEN the directory is served
- THEN its listing MUST NOT contain a `dcatEndpoint` value

### Requirement: Admin configuration and feed validation (DCAT-010)
Admin settings MUST provide: a per-catalog DCAT enable toggle (`hasDcat`,
default off), catalog-level publisher/license/contactPoint defaults, and a
"Validate DCAT feed" action that checks the generated document against the
DCAT-AP-NL mandatory-property profile and reports violations per dataset.
These settings live in the existing admin-settings surface (no new settings
framework).

#### Scenario: Enabling DCAT for a catalog
- GIVEN an admin enables `hasDcat` for catalog "subsidies"
- WHEN the settings are saved
- THEN `GET /api/catalogs/subsidies/dcat` MUST start serving the document
- AND the instance document and directory listing MUST include it

#### Scenario: Validation reports a missing mandatory property
- GIVEN a DCAT-enabled catalog where one dataset lacks any resolvable
  publisher
- WHEN the admin runs "Validate DCAT feed"
- THEN the result MUST list that dataset's IRI with the violated property
  (`dct:publisher`)
- AND the feed itself MUST still be served (validation is advisory, not a
  serving gate)

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

