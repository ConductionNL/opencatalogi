---
status: proposed
---

# DCAT + OAI-PMH Harvesting

## Purpose

Make OpenCatalogi a full participant in the open standards-based catalog ecosystem: both a harvestable source (other catalogs can pull from us) and a harvester (we can pull from external catalogs over standard protocols). Today OpenCatalogi has internal federation (the `federation` spec) which aggregates results across other OpenCatalogi instances via our own REST API. That covers OpenCatalogi-to-OpenCatalogi but locks us out of the wider open-data and library/archive ecosystem where DCAT 2.0 (Data Catalog Vocabulary, W3C) and OAI-PMH 2.0 (Open Archives Initiative Protocol for Metadata Harvesting) are the lingua franca.

The Dutch open-data landscape standardizes on **DCAT-AP-NL 2.1** (applied profile of DCAT for the Netherlands, mandated by Forum Standaardisatie). The national portal data.overheid.nl harvests DCAT feeds from municipalities, provinces, and ministries. The European Data Portal (data.europa.eu) harvests national portals over DCAT-AP. Library and archive networks (KB, beeldengeluid, regional historical centres, university repositories) use OAI-PMH 2.0 with Dublin Core and EDM (Europeana Data Model). PLOOI (the WOO publication platform) ingests via DCAT-flavoured feeds. Without these protocols, OpenCatalogi catalogs are invisible to the canonical aggregators and we cannot import existing content from CKAN/Drupal/Plone catalogs that competitors interface with daily.

## Context

The natural use-case driving this is **cross-gemeente sharing of zaaktypes en standaarden**: gemeente Tilburg publishes its zaaktypecatalogus; gemeente Eindhoven harvests it nightly; differences become visible; convergence becomes auditable. Same pattern for selectielijsten (Archiefwet), beleidsregels, and APV-catalogi.

### Relation to existing specs

- **federation** — Existing OpenCatalogi-to-OpenCatalogi federation continues to work via REST API. DCAT/OAI-PMH is the path to non-OpenCatalogi peers (CKAN, Drupal, Plone, library systems).
- **woo-compliance** — DIWOO sitemaps are a sibling standard. This spec's DCAT outputs and existing DIWOO outputs both project from the same publication objects.
- **auto-publishing** — Auto-published items become harvestable the moment they go live. DCAT lastmod and OAI-PMH datestamp are derived from object updatedAt.

### Relation to external systems

- **OpenConnector** — Used for inbound harvesting: HTTP fetch, OAuth, rate-limiting, retries, mapping.
- **OpenRegister** — HarvestedItem.checksum + sourceRevision live on the registered object as metadata. Audit history shows "this revision was harvested from feed X at time Y".
- **docudesk/metadata-enrichment** — Harvested documents (e.g. from a library OAI-PMH feed) can flow into OCR + classification pipeline.

## Data Model

### HarvestFeed
Configures a single inbound or monitoring feed.

**Fields:**
- `id` — UUID
- `name` — Human-readable feed name (e.g. "Tilburg Zaaktypes")
- `sourceUrl` — Remote feed URL
- `protocol` — One of: `dcat-ap-nl` | `dcat-ap-eu` | `oai-pmh` | `ckan-api` | `schema-org-dataset` | `rss-atom`
- `authType` — One of: `none` | `basic` | `bearer` | `oauth2`
- `credentialsRef` — Reference to vault-stored credentials
- `schedule` — Cron expression (e.g. "0 2 * * *" for 2am daily)
- `timezone` — IANA timezone (e.g. "Europe/Amsterdam")
- `enabled` — Boolean
- `ownerOrganization` — UUID of responsible organization
- `conflictPolicy` — One of: `shadow-local` | `overlay` | `reject-on-conflict` | `manual-review`
- `targetCatalog` — UUID of catalog to add harvested items to
- `targetSchema` — Schema name to map items into (e.g. "publication", "dataset")
- `itemMapping` — JSON object with mapping rules (JSON-path or RML)
- `shaclShapesUrl` — Optional URL to SHACL shape for validation
- `maxItemsPerRun` — Integer, 0 = unlimited
- `lastRunAt` — ISO8601 timestamp or null
- `lastRunStatus` — One of: `pending` | `running` | `success` | `partial-failure` | `failure` or null
- `nextRunAt` — ISO8601 timestamp
- `createdAt`, `updatedAt` — ISO8601 timestamps

**Seed data example:**
```json
{
  "id": "feed-001",
  "name": "Gemeente Tilburg Zaaktypes",
  "sourceUrl": "https://tilburg.nl/api/catalogi/zaaktypes/dcat",
  "protocol": "dcat-ap-nl",
  "authType": "none",
  "schedule": "0 2 * * *",
  "timezone": "Europe/Amsterdam",
  "enabled": true,
  "ownerOrganization": "org-eindhoven",
  "conflictPolicy": "manual-review",
  "targetCatalog": "cat-eindhoven-zaaktypes",
  "targetSchema": "zaaktype",
  "shaclShapesUrl": "https://semiceu.github.io/DCAT-AP/shacl/dcat-ap.ttl",
  "maxItemsPerRun": 1000,
  "lastRunAt": "2026-05-21T02:15:30Z",
  "lastRunStatus": "success",
  "nextRunAt": "2026-05-22T02:00:00Z"
}
```

### HarvestedItem
Represents one item from a feed after ingest.

**Fields:**
- `id` — UUID
- `feedId` — FK to HarvestFeed
- `externalUri` — The source URI (e.g. DCAT dcat:identifier or OAI-PMH header/identifier)
- `localObjectId` — FK to OpenRegister object after resolution/mapping, or null if unresolved
- `checksum` — SHA256 of normalized payload (for change detection)
- `state` — One of: `new` | `updated` | `unchanged` | `conflict` | `rejected`
- `conflictReason` — Nullable string explaining conflict (e.g. "Local version newer (20240301 vs 20240228)")
- `firstSeenAt`, `lastSeenAt`, `lastChangedAt` — ISO8601 timestamps
- `sourceRevision` — Source's revision ID/timestamp (for audit trail on registered object)
- `mappedPayload` — JSON object after mapping applied
- `createdAt`, `updatedAt` — ISO8601 timestamps

**Seed data example:**
```json
{
  "id": "harvested-zaaktype-001",
  "feedId": "feed-001",
  "externalUri": "https://tilburg.nl/zaaktypes/behandelen-aanvraag",
  "localObjectId": "zaaktype-eindhoven-001",
  "checksum": "a1b2c3d4e5f6...",
  "state": "updated",
  "conflictReason": null,
  "firstSeenAt": "2026-05-14T02:10:00Z",
  "lastSeenAt": "2026-05-21T02:15:00Z",
  "lastChangedAt": "2026-05-21T02:15:00Z",
  "sourceRevision": "2026-05-20T14:30:00Z",
  "mappedPayload": {
    "title": "Behandelen aanvraag",
    "description": "Indiening en behandeling van aanvragen"
  }
}
```

### HarvestRun
Telemetry for a single harvesting pass.

**Fields:**
- `id` — UUID
- `feedId` — FK to HarvestFeed
- `startedAt`, `finishedAt` — ISO8601 timestamps
- `itemsScanned` — Integer count of items from source
- `itemsNew`, `itemsUpdated`, `itemsUnchanged`, `itemsConflict`, `itemsRejected` — State bucket counts
- `errors` — Array of error objects with `timestamp`, `code`, `message`, `detail`
- `logUrl` — Pointer to chunked log (stored in blob/object storage, paginated)
- `durationMs` — Milliseconds elapsed
- `createdAt` — ISO8601 timestamp

**Seed data example:**
```json
{
  "id": "run-20260521-020000",
  "feedId": "feed-001",
  "startedAt": "2026-05-21T02:00:00Z",
  "finishedAt": "2026-05-21T02:15:30Z",
  "itemsScanned": 87,
  "itemsNew": 3,
  "itemsUpdated": 5,
  "itemsUnchanged": 79,
  "itemsConflict": 0,
  "itemsRejected": 0,
  "errors": [],
  "logUrl": "s3://harvest-logs/feed-001/20260521-020000.jsonl",
  "durationMs": 930000
}
```

### Outbound view models (no new schema)

Existing catalog/publication objects are projected through view-renderers:

- `GET /catalog/{slug}/dcat` → DCAT-AP-NL 2.1 JSON-LD response
- `GET /catalog/{slug}/dcat.ttl` → Turtle serialization
- `GET /catalog/{slug}/oai?verb=...` → OAI-PMH 2.0 endpoint
- `GET /catalog/{slug}/sitemap-dcat.xml` → Sitemap with DCAT metadata per dataset

## Requirements

### REQ-001: Outbound DCAT-AP-NL 2.1 endpoint

The system MUST expose each catalog as a DCAT-AP-NL 2.1 JSON-LD endpoint with content negotiation for Turtle and RDF/XML serializations.

#### Scenario: Catalog exposes DCAT JSON-LD with correct structure
- GIVEN a catalog "Publicaties Gemeente Utrecht" with 10 published publications
- WHEN `GET /catalog/pubs-utrecht/dcat` is called with `Accept: application/ld+json`
- THEN the response MUST contain valid DCAT-AP-NL 2.1 JSON-LD with:
  - One `dcat:Catalog` node with `dct:title`, `dct:description`, `dcat:dataset` array
  - Each publication mapped to a `dcat:Dataset` with `dct:title`, `dct:identifier`, `dcat:distribution` array
  - Each attachment mapped to a `dcat:Distribution` with `dcat:mediaType`, `dcat:accessURL`
  - All URIs using stable HTTP(S) URLs from the catalog base
- AND the HTTP response MUST include `Content-Type: application/ld+json; charset=utf-8`

#### Scenario: Content negotiation returns Turtle on request
- GIVEN the DCAT endpoint is available
- WHEN `GET /catalog/pubs-utrecht/dcat.ttl` or `GET /catalog/pubs-utrecht/dcat` with `Accept: text/turtle`
- THEN the response MUST be valid Turtle (RDF N3 syntax)
- AND the HTTP response MUST include `Content-Type: text/turtle; charset=utf-8`

#### Scenario: Content negotiation returns RDF/XML on request
- GIVEN the DCAT endpoint is available
- WHEN `GET /catalog/pubs-utrecht/dcat` with `Accept: application/rdf+xml`
- THEN the response MUST be valid RDF/XML
- AND the HTTP response MUST include `Content-Type: application/rdf+xml; charset=utf-8`

#### Scenario: DCAT includes language tags for multilingual content
- GIVEN a publication with `title.nl: "Jaarverslag"` and `title.en: "Annual Report"`
- WHEN `GET /catalog/.../dcat` is called
- THEN the JSON-LD MUST include:
  ```json
  "dct:title": [
    { "@value": "Jaarverslag", "@language": "nl" },
    { "@value": "Annual Report", "@language": "en" }
  ]
  ```

#### Scenario: DCAT includes publisher metadata
- GIVEN the catalog belongs to organization "Gemeente Utrecht"
- WHEN `GET /catalog/.../dcat` is called
- THEN the `dcat:Catalog` node MUST include `dct:publisher` pointing to a `foaf:Organization` with:
  - `foaf:name`: "Gemeente Utrecht"
  - `foaf:homepage`: URI to organization page
  - A link to the organization's entry in the Dutch organization registry (overheid.nl resolver)

### REQ-002: Outbound OAI-PMH 2.0 endpoint

The system MUST expose each catalog as an OAI-PMH 2.0 endpoint supporting Identify, ListMetadataFormats, ListSets, ListIdentifiers, ListRecords, and GetRecord verbs.

#### Scenario: OAI-PMH Identify verb returns correct metadata
- GIVEN the OAI-PMH endpoint at `/catalog/{slug}/oai`
- WHEN `GET /catalog/pubs-utrecht/oai?verb=Identify` is called
- THEN the response MUST contain XML with:
  - `<repositoryName>`: "OpenCatalogi Publicaties Gemeente Utrecht"
  - `<baseURL>`: Full URL to the endpoint
  - `<protocolVersion>`: "2.0"
  - `<earliestDatestamp>`: Timestamp of oldest publication
  - `<deletedRecord>`: "transient" (as per spec policy)
  - `<granularity>`: "YYYY-MM-DDThh:mm:ssZ"

#### Scenario: OAI-PMH ListMetadataFormats returns supported formats
- GIVEN the OAI-PMH endpoint
- WHEN `GET /catalog/.../oai?verb=ListMetadataFormats` is called
- THEN the response MUST list at least:
  - `<metadataPrefix>oai_dc</metadataPrefix>` (Dublin Core)
  - `<metadataPrefix>dcat</metadataPrefix>` (DCAT-AP-NL)
  - `<metadataPrefix>oai_datacite</metadataPrefix>` (DataCite)
- AND each prefix MUST include a valid `<schema>` URL

#### Scenario: OAI-PMH ListSets reflects catalog structure
- GIVEN catalogs "Publicaties" and "Zaaktypes"
- WHEN `GET /catalog/.../oai?verb=ListSets` is called
- THEN the response MUST include:
  - `<set><setSpec>publicaties</setSpec><setName>Publicaties</setName></set>`
  - `<set><setSpec>zaaktypes</setSpec><setName>Zaaktypes</setName></set>`

#### Scenario: OAI-PMH ListRecords with pagination via resumption tokens
- GIVEN a catalog with 250 published items
- WHEN `GET /catalog/.../oai?verb=ListRecords&metadataPrefix=oai_dc` is called
- THEN the response MUST contain:
  - First 100 records (batch size configurable)
  - A `<resumptionToken>` element with cursor state
  - `<resumptionToken completeListSize="250" cursor="0" expirationDate="...">token-abc123</resumptionToken>`
- AND calling `GET /catalog/.../oai?verb=ListRecords&resumptionToken=token-abc123` MUST return records 101-200
- AND the final batch MUST have an empty or absent `<resumptionToken>` element

#### Scenario: OAI-PMH GetRecord returns single item in requested format
- GIVEN an item with OAI identifier "oai:pubs-utrecht:publication-12345"
- WHEN `GET /catalog/.../oai?verb=GetRecord&identifier=oai:pubs-utrecht:publication-12345&metadataPrefix=oai_dc` is called
- THEN the response MUST contain:
  - `<record><header><identifier>oai:pubs-utrecht:publication-12345</identifier><datestamp>2026-05-21T14:30:00Z</datestamp></header>`
  - `<metadata>` node with Dublin Core fields (`dc:title`, `dc:creator`, `dc:date`, `dc:description`)

#### Scenario: OAI-PMH from/until selective harvesting
- GIVEN publications with `updatedAt` spanning 2026-05-14 to 2026-05-21
- WHEN `GET /catalog/.../oai?verb=ListIdentifiers&from=2026-05-18&until=2026-05-20&metadataPrefix=oai_dc` is called
- THEN the response MUST include ONLY identifiers for items updated between 2026-05-18 and 2026-05-20 (inclusive)

### REQ-003: Inbound DCAT feed harvesting

The system MUST ingest DCAT-AP-NL and DCAT-AP-EU feeds in JSON-LD, Turtle, and RDF/XML formats.

#### Scenario: Harvest DCAT JSON-LD feed with new items
- GIVEN a DCAT JSON-LD feed at `https://tilburg.nl/dcat.jsonld` with 5 datasets
- WHEN the feed is registered and harvest runs
- THEN the system MUST:
  - Parse the JSON-LD into RDF triples
  - Extract `dcat:Dataset` nodes and map to local "publication" schema
  - Create 5 HarvestedItem rows with `state=new`
  - Link each to a new OpenRegister object
  - Store `externalUri` (dcat:identifier) and `sourceRevision` (dct:modified timestamp)
- AND the HarvestRun MUST report `itemsNew: 5, itemsScanned: 5`

#### Scenario: Incremental harvest detects changed datasets
- GIVEN a feed previously harvested (5 items, checksums stored)
- AND the feed now has 6 datasets (1 new, 3 unchanged, 2 updated)
- WHEN the feed harvests again
- THEN HarvestedItem states MUST be:
  - 1 item with `state=new`
  - 3 items with `state=unchanged` (checksum matches)
  - 2 items with `state=updated` (checksum differs, local object updated)
- AND the HarvestRun MUST report counts matching those states

#### Scenario: DCAT with missing required fields is rejected
- GIVEN a DCAT feed with a Dataset missing required `dct:title`
- WHEN SHACL validation runs against DCAT-AP-NL shape
- THEN the item MUST be marked `state=rejected` with `conflictReason: "SHACL validation failed: missing dct:title"`
- AND the error MUST be logged in HarvestRun.errors

#### Scenario: Harvest Turtle format DCAT feed
- GIVEN a DCAT feed at `https://example.com/dcat.ttl` in Turtle format
- WHEN the feed is registered with `protocol: dcat-ap-nl` and harvest runs
- THEN the system MUST:
  - Parse Turtle syntax to RDF triples
  - Extract datasets and map as above
  - Report successful harvest with correct item counts

### REQ-004: Inbound OAI-PMH feed harvesting

The system MUST ingest OAI-PMH 2.0 endpoints with resumption-token following, including `oai_dc`, `dcat`, and `oai_datacite` metadata prefixes.

#### Scenario: Harvest OAI-PMH endpoint with resumption-token pagination
- GIVEN an OAI-PMH endpoint serving 250 items in batches of 100
- WHEN the feed is registered and harvest runs
- THEN the system MUST:
  - Call `ListRecords` verb with requested `metadataPrefix`
  - Parse each batch of 100 records
  - Follow resumption tokens until no more records
  - Create HarvestedItem for all 250 items
- AND the HarvestRun MUST report `itemsScanned: 250`

#### Scenario: OAI-PMH selective harvesting with from/until
- GIVEN a registered OAI-PMH feed
- AND the last successful harvest was 2026-05-14
- WHEN an incremental harvest runs
- THEN the system MUST call `ListRecords` with `from=2026-05-14T00:00:00Z` to fetch only new/changed items
- AND unchanged items from before 2026-05-14 are not re-fetched

#### Scenario: OAI-PMH deleted items become soft-deleted
- GIVEN an OAI-PMH feed where an item was marked as deleted upstream
- WHEN the harvest runs
- THEN the corresponding HarvestedItem MUST be marked with `state=rejected` and a note in `conflictReason`
- AND the local OpenRegister object MUST NOT be hard-deleted; instead a tombstone entry MUST be created
- AND the item MUST remain queryable for audit purposes

### REQ-005: Inbound CKAN API harvesting

The system MUST ingest data from CKAN `/api/3/action/` endpoints.

#### Scenario: Harvest CKAN package_list and package_show
- GIVEN a CKAN instance at `https://ckan-province.nl/api/3/action/`
- WHEN the feed is registered with `protocol: ckan-api` and harvest runs
- THEN the system MUST:
  - Call `package_list` to get all package names
  - Call `package_show` for each package to fetch full metadata
  - Map CKAN `package` fields to local "dataset" schema
  - Create HarvestedItem for each package
- AND the HarvestRun MUST report total item count from `package_list`

### REQ-006: Inbound schema.org Dataset JSON-LD harvesting

The system MUST discover and ingest schema.org Dataset JSON-LD from sitemaps.

#### Scenario: Discover schema.org Dataset via sitemap
- GIVEN a sitemap at `https://example.com/sitemap-datasets.xml` listing 50 dataset pages
- WHEN the feed is registered with `protocol: schema-org-dataset` and harvest runs
- THEN the system MUST:
  - Fetch the sitemap
  - For each `<loc>` URL, fetch the HTML page
  - Extract `<script type="application/ld+json">` blocks containing `@type: Dataset`
  - Parse and map schema.org Dataset properties to local "dataset" schema
  - Create HarvestedItem for each discovered dataset
- AND the HarvestRun MUST report item count

### REQ-007: Configurable cron scheduling

The system MUST support cron expression scheduling with timezone awareness per feed.

#### Scenario: Feed scheduled to run daily at 02:00 Amsterdam time
- GIVEN a HarvestFeed with `schedule: "0 2 * * *"` and `timezone: "Europe/Amsterdam"`
- WHEN the cron scheduler runs
- THEN the next run MUST be scheduled for 02:00 Amsterdam time (which may be 01:00 UTC in summer, 02:00 UTC in winter)
- AND daylight-saving transitions MUST be handled correctly

#### Scenario: Manual trigger runs harvest immediately
- GIVEN an admin clicks "Harvest Now" on a feed
- WHEN the action is processed
- THEN a harvest MUST start immediately, regardless of schedule
- AND the next regular cron run is NOT affected

### REQ-008: Per-feed SHACL validation

The system MUST validate harvested items against a DCAT-AP-NL SHACL shape (or custom shape per feed).

#### Scenario: SHACL validation passes for valid DCAT
- GIVEN a DCAT feed with correct cardinality and value types per DCAT-AP-NL
- WHEN validation runs using the published DCAT-AP-NL SHACL shape
- THEN validation MUST pass
- AND the item MUST be marked `state=new|updated|unchanged` (not rejected)

#### Scenario: SHACL validation rejects invalid structure
- GIVEN a DCAT feed with a Dataset missing `dct:title` (required)
- WHEN validation runs
- THEN validation MUST fail
- AND the item MUST be marked `state=rejected`
- AND the validation error MUST be logged in HarvestedItem.conflictReason and HarvestRun.errors

#### Scenario: Custom SHACL shape per feed
- GIVEN a feed with `shaclShapesUrl: "https://example.com/custom-shape.ttl"`
- WHEN harvest runs
- THEN validation MUST use the custom shape instead of the default DCAT-AP-NL shape

### REQ-009: Conflict resolution policies

The system MUST enforce a configurable conflict-resolution policy per feed to handle collisions between harvested and local items.

#### Scenario: shadow-local policy — harvested items don't replace local items
- GIVEN a feed with `conflictPolicy: shadow-local`
- AND a local item "Jaarverslag 2024" (localObjectId: pub-001)
- AND the upstream feed also has "Jaarverslag 2024" (externalUri: remote-001)
- WHEN the feed is harvested
- THEN:
  - HarvestedItem.localObjectId is null (no link)
  - Item is created with `state=conflict`, `conflictReason: "Local copy exists; shadowing per feed policy"`
  - Local object pub-001 is untouched
  - The item MAY appear in search under a "harvested" marker, but local version takes precedence in normal catalog view

#### Scenario: overlay policy — harvested items replace local items
- GIVEN a feed with `conflictPolicy: overlay`
- AND a local item "Jaarverslag 2024" (pub-001, modified 2026-05-10)
- AND the upstream item "Jaarverslag 2024" (remote-001, modified 2026-05-20)
- WHEN the feed is harvested
- THEN:
  - HarvestedItem.localObjectId = pub-001
  - HarvestedItem.state = updated (if checksum differs) or unchanged
  - Local object pub-001 is updated with harvested data, overwriting all mapped fields
  - The provenance metadata MUST record the source: `dct:source` = remote-001, `prov:wasDerivedFrom` = remote-001

#### Scenario: reject-on-conflict policy — items with local duplicates are rejected
- GIVEN a feed with `conflictPolicy: reject-on-conflict`
- AND a local item "Jaarverslag 2024" (pub-001)
- AND the upstream feed also has "Jaarverslag 2024" (remote-001)
- WHEN the feed is harvested
- THEN:
  - HarvestedItem is created with `state=rejected`
  - `conflictReason: "Local item exists with identical title; rejected per feed policy"`
  - Local object pub-001 is untouched
  - The item is NOT added to the catalog

#### Scenario: manual-review policy — conflicted items go to queue for human decision
- GIVEN a feed with `conflictPolicy: manual-review`
- AND a local item "Jaarverslag 2024" (pub-001, modified 2026-05-10)
- AND the upstream item "Jaarverslag 2024" (remote-001, modified 2026-05-20)
- WHEN the feed is harvested
- THEN:
  - HarvestedItem is created with `state=conflict`
  - `conflictReason: "Local and remote versions differ; manual review required"`
  - The item is queued in the manual-review dashboard
  - An admin can view a side-by-side diff: local vs. harvested
  - The admin can choose to: accept harvested (link HarvestedItem.localObjectId and update pub-001), keep local, merge fields, or discard
  - Until resolved, the item MUST NOT appear in the public catalog

### REQ-010: Per-feed status dashboard

The system MUST provide an admin dashboard showing per-feed harvest status, item-state distribution, and error trends.

#### Scenario: Dashboard shows last run status and next scheduled run
- GIVEN a feed with `lastRunAt: 2026-05-21T02:15:30Z`, `lastRunStatus: success`, `nextRunAt: 2026-05-22T02:00:00Z`
- WHEN an admin views the feed detail page
- THEN the dashboard MUST display:
  - Last run: "May 21, 2:15 AM — Success"
  - Next run: "May 22, 2:00 AM"
  - Duration: "15 minutes 30 seconds" (from HarvestRun.durationMs)

#### Scenario: Dashboard shows item-state bucket distribution
- GIVEN a HarvestRun with itemsNew=3, itemsUpdated=5, itemsUnchanged=79, itemsConflict=0, itemsRejected=0
- WHEN the admin views the feed detail page
- THEN the dashboard MUST show:
  - A summary card: "87 items scanned: 3 new, 5 updated, 79 unchanged, 0 conflicts, 0 rejected"
  - A chart or progress bar breaking down the percentages

#### Scenario: Dashboard shows error trend over recent runs
- GIVEN a feed with 10 most recent HarvestRuns showing:
  - Runs 1-8: 0 errors
  - Runs 9-10: 2-3 errors each (e.g. "Connection timeout", "SHACL validation failure")
- WHEN the admin views the feed detail page
- THEN the dashboard MUST display:
  - A recent-errors section showing the last 10 runs
  - Error counts per run
  - Clickable detail to view full error messages and stack traces

#### Scenario: Dashboard links to per-run logs
- GIVEN a HarvestRun with `logUrl: "s3://harvest-logs/feed-001/20260521-020000.jsonl"`
- WHEN the admin clicks "View Logs" on a past run
- THEN a paginated log viewer MUST open showing:
  - Structured log entries (timestamp, level, message, context)
  - Filtering by level (info, warning, error)
  - Search within logs
  - Export option for debugging

### REQ-011: Change detection via checksum

The system MUST use checksums to detect whether harvested items have changed, skipping re-write of unchanged items.

#### Scenario: Unchanged item is detected and skipped
- GIVEN a previously harvested item with `checksum: "a1b2c3d4..."`
- AND the item is fetched again from upstream with identical normalized payload
- WHEN checksum computation runs
- THEN the new checksum MUST match the stored checksum
- AND the item MUST be marked `state=unchanged`
- AND the local OpenRegister object MUST NOT be written (no unnecessary updates)

#### Scenario: Changed item is detected and updated
- GIVEN a previously harvested item with `checksum: "a1b2c3d4..."` and `sourceRevision: "2026-05-10T10:00:00Z"`
- AND the item is fetched again with a different normalized payload
- AND the new sourceRevision is "2026-05-20T15:30:00Z"
- WHEN checksum computation runs
- THEN the new checksum MUST differ
- AND the item MUST be marked `state=updated`
- AND the local OpenRegister object MUST be updated with new data
- AND sourceRevision MUST be updated to "2026-05-20T15:30:00Z"

#### Scenario: Checksum is computed from normalized payload
- GIVEN items from different protocols (DCAT JSON-LD, OAI-PMH XML, CKAN JSON) with the same logical content
- WHEN checksums are computed
- THEN normalization MUST convert all to a canonical form (e.g. RDF triples or a sorted JSON structure)
- AND all three representations of the same item MUST produce the same checksum
- AND a comparison field-by-field comparison MUST verify semantic equivalence

### REQ-012: Item-level mapping rules

The system MUST support JSON-path and RML mapping rules to transform harvested items from source vocabulary to local schema.

#### Scenario: JSON-path mapping transforms CKAN fields to local schema
- GIVEN a CKAN item with fields: `{ "title": "...", "notes": "...", "url": "...", "author": "..." }`
- AND a HarvestFeed with `itemMapping`:
  ```json
  {
    "title": "$.title",
    "description": "$.notes",
    "sourceUrl": "$.url",
    "author": "$.author"
  }
  ```
- WHEN the item is mapped
- THEN the output MUST be:
  ```json
  {
    "title": "from CKAN.title",
    "description": "from CKAN.notes",
    "sourceUrl": "from CKAN.url",
    "author": "from CKAN.author"
  }
  ```
- AND the mapped item MUST match the local schema shape for "publication"

#### Scenario: RML mapping handles complex DCAT transformations
- GIVEN a DCAT feed with a `dcat:Distribution` listing multiple formats
- AND an RML mapping rule that extracts PDF distributions and maps to "attachment" objects
- WHEN the item is mapped
- THEN each matching Distribution MUST generate a separate attachment entry
- AND the mapping MUST compose into the parent publication object

### REQ-013: Provenance metadata on harvested items

The system MUST record provenance triples (`dct:source`, `prov:wasDerivedFrom`) on harvested items to track their origin.

#### Scenario: Harvested item includes source provenance
- GIVEN an item harvested from feed "Gemeente Tilburg Zaaktypes"
- WHEN the item is stored in the local catalog
- THEN the OpenRegister object MUST include provenance metadata:
  - `dct:source`: "https://tilburg.nl/api/catalogi/zaaktypes/dcat"
  - `prov:wasDerivedFrom`: "{externalUri}" (the original remote URI)
  - `dct:issued`: Timestamp when item was first harvested
  - `dct:modified`: Timestamp of last harvest update
- AND these fields MUST be immutable (audit trail), not overwritten on re-harvest

#### Scenario: Provenance visible in API response
- GIVEN a harvested item in the catalog
- WHEN `GET /api/publications/{id}` is called
- THEN the response MUST include a `_provenance` section with:
  ```json
  "_provenance": {
    "harvested": true,
    "source": "Gemeente Tilburg Zaaktypes",
    "sourceUrl": "https://tilburg.nl/dcat",
    "externalUri": "https://tilburg.nl/zaaktypes/behandelen-aanvraag",
    "firstHarvestedAt": "2026-05-14T02:10:00Z",
    "lastHarvestedAt": "2026-05-21T02:15:00Z"
  }
  ```

### REQ-014: Soft-delete of disappeared items

The system MUST track items that disappear from upstream without hard-deleting local copies.

#### Scenario: Item disappears from feed — tombstone created
- GIVEN a HarvestedItem that was previously harvested
- AND the item no longer appears in a new harvest (checked by externalUri)
- WHEN the harvest completes
- THEN:
  - The HarvestedItem MUST be marked with a tombstone status
  - The local OpenRegister object MUST NOT be deleted
  - The item MUST be queryable with a filter `deleted: true` for audit
  - The OpenCatalog admin can view a report of disappeared items

#### Scenario: Disappeared item can be reviewed before removal
- GIVEN 5 items disappeared from upstream in the last harvest
- WHEN an admin views the "Disappeared Items" report in the dashboard
- THEN the admin MUST see:
  - List of items that vanished
  - Last-seen timestamp for each
  - Option to: restore (un-tombstone), hard-delete, or keep in archive
  - Until action is taken, the item remains in the system

### REQ-015: Rate-limited fetch with exponential backoff

The system MUST respect upstream rate limits and implement exponential backoff on 429 and 5xx responses.

#### Scenario: Rate limiting honored on CKAN API calls
- GIVEN an upstream CKAN instance that rate-limits to 10 requests/minute
- WHEN the harvester fetches 100 packages
- THEN the harvester MUST:
  - Space requests to respect the 10/min limit
  - OR detect 429 responses and implement exponential backoff
  - OR read `Retry-After` headers if provided
- AND the HarvestRun MUST complete successfully despite rate limiting
- AND the `durationMs` MUST reflect the time taken to respect limits

#### Scenario: Exponential backoff on 5xx errors
- GIVEN an upstream feed that returns `500 Internal Server Error` on the first attempt
- WHEN the harvest retries
- THEN the retry logic MUST:
  - Wait 2 seconds, then retry (attempt 2)
  - If 500 again, wait 4 seconds, then retry (attempt 3)
  - If 500 again, wait 8 seconds, then retry (attempt 4)
  - Give up after 4 attempts
- AND the HarvestRun MUST record the failure with error logs

### REQ-016: Provenance metadata in DCAT outbound

The system MUST include provenance triples in outbound DCAT feeds for items that were harvested.

#### Scenario: Outbound DCAT includes source attribution
- GIVEN a dataset in the local catalog that was harvested from "Gemeente Tilburg"
- WHEN `GET /catalog/{slug}/dcat` is called
- THEN the dataset's RDF nodes MUST include:
  - `dct:source`: "https://tilburg.nl/dcat" (or similar)
  - `prov:wasDerivedFrom`: The original remote dataset URI
- AND a harvester downstream MUST be able to trace the item's origin

### REQ-017: Outbound feeds include language tags

The system MUST include language tags on DCAT and OAI-PMH outbound feeds per field.

#### Scenario: DCAT JSON-LD outbound uses language tags
- GIVEN publications with `title.nl` and `title.en`
- WHEN `GET /catalog/.../dcat` is called
- THEN the JSON-LD MUST include:
  ```json
  "dct:title": [
    { "@value": "Jaarverslag", "@language": "nl" },
    { "@value": "Annual Report", "@language": "en" }
  ]
  ```
- AND harvesters downstream MUST be able to select language via Accept-Language negotiation

#### Scenario: OAI-PMH Dublin Core preserves language information
- GIVEN a publication with multilingual title
- WHEN OAI-PMH is fetched with `metadataPrefix=oai_dc`
- THEN the response MUST include:
  ```xml
  <dc:title xml:lang="nl">Jaarverslag</dc:title>
  <dc:title xml:lang="en">Annual Report</dc:title>
  ```

### REQ-018: Manual-review queue UI for conflicts

The system MUST provide an admin UI to manually review and resolve harvested items in `conflict` state.

#### Scenario: Admin sees list of items awaiting review
- GIVEN 5 HarvestedItems with `state=conflict`
- WHEN the admin navigates to "Harvesting > Manual Review"
- THEN a list MUST display:
  - Checkbox to select items
  - Item title from both local and harvested versions
  - Source feed name
  - Last attempted resolution time
  - A "Review" button per item or bulk action

#### Scenario: Side-by-side diff view for conflict resolution
- GIVEN an item with local version and harvested version
- WHEN the admin clicks "Review" on a conflict
- THEN a modal MUST open showing:
  - Left side: Local version with all fields and values
  - Right side: Harvested version with all fields and values
  - Differences highlighted
  - A conflict reason explanation (e.g., "Local version newer")
  - Action buttons: "Keep Local", "Use Harvested", "Merge", "Discard"

#### Scenario: Merge action allows field-by-field selection
- GIVEN a conflict where local and harvested versions differ in 3 fields
- WHEN the admin selects "Merge" action
- THEN a UI MUST allow:
  - Per-field toggle: pick which version (local or harvested) to use
  - Preview of resulting merged object
  - "Apply Merge" button to save the resolved state

#### Scenario: Resolved conflict is linked and marked complete
- GIVEN an admin resolves a conflict by selecting "Use Harvested"
- WHEN the action is applied
- THEN:
  - HarvestedItem.localObjectId MUST be linked to the chosen local object
  - HarvestedItem.state MUST change to "updated"
  - A log entry MUST record the resolution action and timestamp
  - The item MUST disappear from the manual-review queue
  - The local object MUST be updated with harvested data

## MODIFIED Requirements

_None — this is a new capability._

## REMOVED Requirements

_None._

## Current Implementation Status

- **Not implemented**: No DCAT, OAI-PMH, CKAN, or schema.org harvesting support exists.
- **Building blocks that exist**:
  - OpenRegister for object storage
  - OpenConnector for HTTP fetching, OAuth, rate-limiting, mapping
  - SearchService for indexing harvested content
  - SitemapService for outbound discovery hints
- **Key gaps**:
  - No DCAT-AP-NL JSON-LD/Turtle/RDF/XML serializers
  - No OAI-PMH endpoint implementation
  - No HarvestFeed / HarvestedItem / HarvestRun persistence layer
  - No feed registration UI
  - No conflict resolution state machine
  - No SHACL validator integration
  - No manual-review queue and UI
  - No per-feed dashboard
  - No cron scheduling for feeds
  - No mapping engine (JSON-path / RML)

## Standards & References

- **DCAT 2.0** — W3C recommendation (Data Catalog Vocabulary)
- **DCAT-AP 2.1** — European applied profile maintained by SEMIC
- **DCAT-AP-NL 2.1** — Dutch national profile (Forum Standaardisatie, status: verplicht)
- **OAI-PMH 2.0** — Open Archives Initiative, 2002
- **Dublin Core Terms (DCMI)** — metadata vocabulary for OAI-PMH `oai_dc` records
- **EDM (Europeana Data Model)** — for cultural-heritage aggregation
- **SHACL** (Shapes Constraint Language, W3C) — validates RDF against published shapes
- **CKAN API** — Drupal-based data portals (`/api/3/action/`)
- **schema.org Dataset** — JSON-LD metadata format
- **PLOOI / KOOP** — Dutch WOO publication feeds
- **TOOI** (Thesaurus Overheid Informatie) — Dutch government controlled vocabularies
- **data.overheid.nl** — Dutch national open-data portal
- **data.europa.eu** — European Data Portal
- **RFC 3339** — Date and time on the Internet: Timestamps
- **RFC 7231** — HTTP/1.1 Semantics and Content (Accept-Language, Content-Language)

## Dependencies

- OpenRegister (for storing HarvestFeed, HarvestedItem, HarvestRun)
- OpenConnector (for fetch, OAuth, rate-limiting, mapping)
- RDF processing library (RDF4J, EasyRdf, or similar for parsing Turtle, RDF/XML)
- SHACL validator library (for shape validation)
- Cron expression parser library
- Elasticsearch / SearchService (for indexing harvested items)
- Nextcloud IRequest (for HTTP operations)
