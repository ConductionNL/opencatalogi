---
status: draft
---

# dcat-ap-harvest Specification

## Purpose

@e2e exclude pure API/backend spec — all scenarios assert RDF/JSON-LD document
shape, content negotiation, caching headers, and harvester paging on public
endpoints with no browser-observable UI surface (the small admin toggle/validate
surface is part of the existing admin-settings UI spec); covered by Newman API
tests instead.

Expose OpenCatalogi catalogs as machine-readable **DCAT-AP-NL** catalog
documents so national (data.overheid.nl) and EU (data.europa.eu) open-data
portals can harvest publications automatically. This is a read-only rendering
layer over the existing published-object set: the `@self.published` predicate
remains the only publication mechanism, OR remains the only storage/query
layer (hydra ADR-022), and the federation directory remains the only discovery
channel (extended with one field, not duplicated).

## Context
The README claims DCAT-AP as OpenCatalogi's metadata standard, but no surface
serves it. Sitemaps (woo-compliance WOO-001..010) serve KOOP/DIWOO crawlers —
DCAT harvesters cannot consume them. CKAN-class portals expose DCAT natively;
without it, every OpenCatalogi instance must be hand-registered at
data.overheid.nl dataset-by-dataset.

**Relation to existing specs:**
- `publications` (PUB-001..015): supplies the published-object query path
  (catalog register/schema filter, `@self.published` visibility) and the
  canonical public URLs reused as dataset/distribution IRIs.
- `woo-compliance`: adjacent public-crawler surface (sitemaps); this spec is
  the DCAT equivalent and reuses its pagination ceiling and public-route
  posture.
- `federation` (FED-009, FED-OR-001/002): the directory/listing machinery is
  extended with a `dcatEndpoint` field; broadcast/retry behaviour is unchanged.
- `admin-settings`: hosts the per-catalog DCAT toggle, publisher defaults, and
  the feed-validation action.
- `cross-origin-api-access`: CORS posture for the new public endpoints.

## ADDED Requirements

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
per the `@self.published` predicate — the identical rule used by the public
publications API (PUB-001) and sitemaps (WOO-001). The DCAT layer MUST NOT
implement its own visibility logic, and querying MUST delegate to OR object
search with the catalog's configured registers/schemas (PUB-003); no bespoke
query layer (hydra ADR-022).

#### Scenario: Unpublished object excluded
- GIVEN a publication in a DCAT-enabled catalog with no `@self.published`
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

## Non-Requirements
- This spec does NOT build inbound DCAT harvesting (importing datasets from
  other portals) — source synchronization is OpenConnector's domain.
- This spec does NOT store DCAT documents or introduce new registers/schemas
  beyond the one optional Listing property — documents are derived per
  request (ADR-022).
- This spec does NOT change publication visibility semantics — the
  `@self.published` predicate remains the only publication mechanism.
- This spec does NOT replace sitemaps/robots (woo-compliance) — DIWOO and
  DCAT are distinct, complementary crawler surfaces.
- This spec does NOT proxy or re-serve federated remote datasets — harvesters
  harvest each instance at its own endpoint (discovered via DCAT-009).

## Dependencies
- OpenRegister object search (`searchObjects` / `zoeken-filteren`) with
  catalog register/schema filtering — consumed for dataset selection
  (ADR-022; same path as PUB-001/PUB-003).
- `@self.published` predicate — publication visibility (NOTE: the known OR
  magic-mapping gap where magic-mapped objects cannot set `@self.published`
  also hides them from this feed; tracked as an OR coordination, not worked
  around here).
- Existing public URL surfaces: PUB-002 (dataset IRI), PUB-007 /
  download-service (distribution `downloadURL`).
- Federation DirectoryService + Listing schema (FED-009) — extended with
  `dcatEndpoint`.
- admin-settings spec — hosts toggle, defaults, validation action.
- cross-origin-api-access spec — CORS posture of the new public routes.
- DCAT-AP-NL 3.0 profile + TOOI registers (organisation/theme URIs).

### Current Implementation Status
- **Not yet implemented**: no DCAT serialization, mapping, or routes exist;
  no `x-dcat` annotation exists on any schema; Listing has no `dcatEndpoint`.
- **Building blocks that exist**: public catalog-scoped object querying
  (PublicationsController/ObjectsController), SitemapService (the
  architectural twin of the new serializer: public, paginated, derived,
  cached), DirectoryService broadcast/serve, admin-settings
  configuration UI, CORS middleware.
