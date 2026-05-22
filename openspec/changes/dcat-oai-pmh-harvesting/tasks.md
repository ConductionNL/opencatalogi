# Tasks: DCAT + OAI-PMH Harvesting

## 1. Data Persistence Layer

- [ ] 1.1 Create HarvestFeed entity with fields: name, sourceUrl, protocol, authType, credentialsRef, schedule, timezone, enabled, ownerOrganization, conflictPolicy, targetCatalog, targetSchema, itemMapping, shaclShapesUrl, maxItemsPerRun, lastRunAt, lastRunStatus, nextRunAt
- [ ] 1.2 Create HarvestedItem entity with fields: feedId, externalUri, localObjectId, checksum, state, conflictReason, firstSeenAt, lastSeenAt, lastChangedAt, sourceRevision, mappedPayload
- [ ] 1.3 Create HarvestRun entity with fields: feedId, startedAt, finishedAt, itemsScanned, itemsNew, itemsUpdated, itemsUnchanged, itemsConflict, itemsRejected, errors[], logUrl, durationMs
- [ ] 1.4 Add database migrations to create tables and indexes
- [ ] 1.5 Create OpenRegister schema definitions for HarvestFeed, HarvestedItem, HarvestRun

## 2. Outbound DCAT-AP-NL 2.1 Endpoint

- [ ] 2.1 Implement DCAT JSON-LD serializer for Catalog → dcat:Catalog RDF nodes with dct:title, dct:description, dcat:dataset
- [ ] 2.2 Implement DCAT JSON-LD serializer for Publication → dcat:Dataset with dct:identifier, dct:title, dct:description, dcat:distribution
- [ ] 2.3 Implement DCAT JSON-LD serializer for Attachment → dcat:Distribution with dcat:mediaType, dcat:accessURL, dcat:byteSize
- [ ] 2.4 Implement content negotiation for JSON-LD, Turtle, RDF/XML formats
- [ ] 2.5 Add language tags (@language in JSON-LD, xml:lang in XML) for multilingual content
- [ ] 2.6 Include publisher (foaf:Organization) metadata with overheid.nl resolver link
- [ ] 2.7 Add DCAT API endpoint at GET `/catalog/{slug}/dcat` with content negotiation
- [ ] 2.8 Add unit tests for DCAT serialization and content negotiation

## 3. Outbound OAI-PMH 2.0 Endpoint

- [ ] 3.1 Implement OAI-PMH Identify verb returning repositoryName, baseURL, protocolVersion, earliestDatestamp, deletedRecord, granularity
- [ ] 3.2 Implement OAI-PMH ListMetadataFormats verb returning oai_dc, dcat, oai_datacite prefixes with schema URLs
- [ ] 3.3 Implement OAI-PMH ListSets verb mapping to catalog slugs (catalog as a "set")
- [ ] 3.4 Implement OAI-PMH ListIdentifiers verb with pagination via resumption tokens
- [ ] 3.5 Implement OAI-PMH ListRecords verb with multiple metadata prefix support (oai_dc, dcat, oai_datacite)
- [ ] 3.6 Implement OAI-PMH GetRecord verb for individual item retrieval
- [ ] 3.7 Implement resumption token generation and state tracking (cursor, expirationDate)
- [ ] 3.8 Implement from/until selective harvesting date range filtering
- [ ] 3.9 Add language tags in metadata records (xml:lang on Dublin Core elements)
- [ ] 3.10 Add OAI-PMH API endpoint at GET `/catalog/{slug}/oai?verb=...`
- [ ] 3.11 Add unit tests for OAI-PMH verb handling and resumption token pagination

## 4. Outbound Sitemap with DCAT Discovery Hints

- [ ] 4.1 Extend existing SitemapService to add `<changefreq>` and `<lastmod>` per dataset
- [ ] 4.2 Add DCAT metadata hints in sitemap (optional extension fields)
- [ ] 4.3 Add GET `/catalog/{slug}/sitemap-dcat.xml` endpoint
- [ ] 4.4 Unit tests for sitemap generation with frequency and modification dates

## 5. Inbound DCAT Feed Parsing

- [ ] 5.1 Integrate RDF processing library (RDF4J or EasyRdf) for Turtle, RDF/XML, JSON-LD parsing
- [ ] 5.2 Implement DCAT JSON-LD parser (extract dcat:Dataset, dcat:Distribution nodes)
- [ ] 5.3 Implement DCAT Turtle parser (same extraction from N3 syntax)
- [ ] 5.4 Implement DCAT RDF/XML parser (same extraction from XML syntax)
- [ ] 5.5 Implement mapping from DCAT Dataset properties to local "publication" or "dataset" schema
- [ ] 5.6 Implement checksum computation (normalized SHA256 from RDF payload)
- [ ] 5.7 Add unit tests for DCAT parsing across all serializations
- [ ] 5.8 Add integration tests for DCAT feed harvesting end-to-end

## 6. Inbound OAI-PMH Feed Parsing

- [ ] 6.1 Implement OAI-PMH endpoint client (HTTP GET, XML parsing)
- [ ] 6.2 Implement Identify verb parsing (earliestDatestamp extraction)
- [ ] 6.3 Implement ListRecords verb with resumption token following (loop until no more tokens)
- [ ] 6.4 Implement metadata prefix parsing (oai_dc, dcat, oai_datacite support)
- [ ] 6.5 Implement from/until parameter for incremental harvesting
- [ ] 6.6 Implement handling of deleted items (metadata header with status="deleted")
- [ ] 6.7 Implement mapping from Dublin Core and DCAT to local schema
- [ ] 6.8 Add unit tests for OAI-PMH client and resumption token logic
- [ ] 6.9 Add integration tests for OAI-PMH feed harvesting

## 7. Inbound CKAN API Harvesting

- [ ] 7.1 Implement CKAN `/api/3/action/package_list` client
- [ ] 7.2 Implement CKAN `/api/3/action/package_show` client for per-package fetch
- [ ] 7.3 Implement mapping from CKAN package properties to local "dataset" or "publication" schema
- [ ] 7.4 Add unit tests for CKAN API client and package mapping

## 8. Inbound schema.org Dataset Discovery

- [ ] 8.1 Implement sitemap fetcher (GET, XML parsing, extract `<loc>` URLs)
- [ ] 8.2 Implement HTML page fetcher and JSON-LD `<script>` block extraction
- [ ] 8.3 Implement schema.org Dataset parsing (`@type: Dataset`)
- [ ] 8.4 Implement mapping from schema.org properties to local schema
- [ ] 8.5 Add unit tests for sitemap discovery and schema.org parsing

## 9. Feed Registration and Persistence

- [ ] 9.1 Create HarvestFeed admin controller with CRUD endpoints
- [ ] 9.2 Implement protocol selection dropdown (dcat-ap-nl, dcat-ap-eu, oai-pmh, ckan-api, schema-org-dataset)
- [ ] 9.3 Implement sourceUrl input and validation
- [ ] 9.4 Implement credentials management (basic auth, bearer token, OAuth2 config) with vault storage
- [ ] 9.5 Implement cron expression builder UI with timezone selector
- [ ] 9.6 Implement conflict policy selector (shadow-local, overlay, reject-on-conflict, manual-review)
- [ ] 9.7 Implement target catalog selector and target schema selector
- [ ] 9.8 Implement itemMapping editor (JSON-path rules or RML)
- [ ] 9.9 Implement SHACL shape URL input
- [ ] 9.10 Implement maxItemsPerRun limit setter
- [ ] 9.11 Add validation and error messaging for feed registration
- [ ] 9.12 Add unit tests for feed persistence and validation

## 10. Cron Scheduling and Background Job Dispatch

- [ ] 10.1 Integrate cron expression parser library (cron, cronparser, or similar)
- [ ] 10.2 Implement scheduler service to parse schedule + timezone and compute nextRunAt
- [ ] 10.3 Implement background job runner (Nextcloud BackgroundJob or queue-based)
- [ ] 10.4 Implement harvest job dispatcher that picks enabled feeds and runs them
- [ ] 10.5 Implement manual "Harvest Now" trigger on feed detail page
- [ ] 10.6 Add job logging and retry logic on failure
- [ ] 10.7 Add unit tests for scheduler and job dispatch

## 11. Harvester Service Core

- [ ] 11.1 Create Harvester service class with protocol-agnostic interface
- [ ] 11.2 Implement protocol-specific harvester plugins (DCAT, OAI-PMH, CKAN, schema.org)
- [ ] 11.3 Implement OpenConnector integration (register source, create sync rule)
- [ ] 11.4 Implement HTTP client with rate-limiting and exponential backoff (429, 5xx handling)
- [ ] 11.5 Implement retry logic (up to 4 attempts, 2/4/8 sec backoff)
- [ ] 11.6 Implement timeout handling (30s per request, 5m per feed)
- [ ] 11.7 Implement proxy/firewall configuration support

## 12. Change Detection and Checksum Computation

- [ ] 12.1 Implement payload normalization (RDF canonical form or sorted JSON)
- [ ] 12.2 Implement SHA256 checksum computation from normalized payload
- [ ] 12.3 Implement checksum comparison to detect unchanged items (state=unchanged)
- [ ] 12.4 Implement sourceRevision tracking (from upstream timestamp or revision ID)
- [ ] 12.5 Add unit tests for checksum computation and change detection

## 13. SHACL Validation

- [ ] 13.1 Integrate SHACL validator library (Apache Jena, RDF4J, etc.)
- [ ] 13.2 Implement SHACL shape loader (from file, URL, or embedded)
- [ ] 13.3 Implement DCAT-AP-NL published shape loading (from semiceu.github.io or bundled)
- [ ] 13.4 Implement per-feed custom shape support (shaclShapesUrl config)
- [ ] 13.5 Implement validation runner and error reporting (state=rejected, conflictReason population)
- [ ] 13.6 Add unit tests for SHACL validation with passing and failing shapes

## 14. Conflict Resolution State Machine

- [ ] 14.1 Implement conflict detection logic (matching by title, URI, or custom mapping key)
- [ ] 14.2 Implement shadow-local policy (no linking, state=conflict, no local update)
- [ ] 14.3 Implement overlay policy (link localObjectId, update local object, provenance)
- [ ] 14.4 Implement reject-on-conflict policy (state=rejected, no linking)
- [ ] 14.5 Implement manual-review policy (state=conflict, queue for UI resolution)
- [ ] 14.6 Implement state transition logic (new → updated → unchanged, conflict/rejected tracking)
- [ ] 14.7 Add unit tests for each conflict policy

## 15. Item-Level Mapping (JSON-path / RML)

- [ ] 15.1 Integrate JSON-path library for simple field mapping
- [ ] 15.2 Implement JSON-path expression evaluator ($.title, $.author, etc.)
- [ ] 15.3 Integrate RML (Transformation Mapping Language) library for complex mappings
- [ ] 15.4 Implement RML rule parser and executor (object composition, array handling)
- [ ] 15.5 Implement mapping validator (schema conformance check post-mapping)
- [ ] 15.6 Add unit tests for JSON-path and RML mappings

## 16. Provenance Metadata Recording

- [ ] 16.1 Add dct:source, prov:wasDerivedFrom fields to HarvestedItem
- [ ] 16.2 Implement provenance metadata storage on local OpenRegister objects
- [ ] 16.3 Implement immutable audit trail for provenance (never overwrite, append-only)
- [ ] 16.4 Implement provenance inclusion in API responses (_provenance section)
- [ ] 16.5 Add unit tests for provenance metadata

## 17. Soft-Delete and Tombstones

- [ ] 17.1 Implement disappeared-item detection (externalUri not in new harvest)
- [ ] 17.2 Implement tombstone marking (flag without hard delete)
- [ ] 17.3 Implement tombstone queryability (filter deleted: true)
- [ ] 17.4 Implement admin "Disappeared Items" report with restore/delete/archive options
- [ ] 17.5 Add unit tests for soft-delete logic

## 18. Per-Feed Dashboard

- [ ] 18.1 Create HarvestFeed detail page template (admin UI)
- [ ] 18.2 Display last run status, timestamp, duration
- [ ] 18.3 Display next scheduled run timestamp
- [ ] 18.4 Display item-state bucket summary (new, updated, unchanged, conflict, rejected counts)
- [ ] 18.5 Display item-state distribution chart (pie/bar chart)
- [ ] 18.6 Display error trend over recent 10 runs with counts
- [ ] 18.7 Link to per-run detailed logs (paginated viewer)
- [ ] 18.8 Add "Harvest Now" button for manual trigger
- [ ] 18.9 Add "Edit Feed" button to modify configuration
- [ ] 18.10 Add unit tests for dashboard data aggregation

## 19. Per-Run Detailed Logging

- [ ] 19.1 Implement HarvestRun log writer (chunked JSONL to blob storage or file)
- [ ] 19.2 Implement structured logging (timestamp, level, message, context fields)
- [ ] 19.3 Implement log retention policy (30 days configurable, older logs archived/deleted)
- [ ] 19.4 Implement log viewer UI (paginated, filterable by level, searchable)
- [ ] 19.5 Implement log export option (CSV, JSON, raw JSONL)
- [ ] 19.6 Add unit tests for log writing and reading

## 20. Manual-Review Queue and UI

- [ ] 20.1 Create manual-review queue page (list of HarvestedItems with state=conflict)
- [ ] 20.2 Display item title, source feed, local version title, conflict reason
- [ ] 20.3 Add "Review" button to open conflict resolution modal
- [ ] 20.4 Implement side-by-side diff view (local vs. harvested)
- [ ] 20.5 Implement field-level diff highlighting (show which fields differ)
- [ ] 20.6 Implement action buttons: "Keep Local", "Use Harvested", "Merge", "Discard"
- [ ] 20.7 Implement merge UI (per-field toggles to select local or harvested value)
- [ ] 20.8 Implement merge preview (show resulting merged object before save)
- [ ] 20.9 Implement conflict resolution action handler (link localObjectId, update state, log resolution)
- [ ] 20.10 Implement bulk action support (select multiple conflicts, apply same action to all)
- [ ] 20.11 Add unit tests for conflict resolution logic

## 21. Unit Tests (ADR-009 -- Testing Strategy)

- [ ] 21.1 Test DCAT parsing all serializations (JSON-LD, Turtle, RDF/XML)
- [ ] 21.2 Test OAI-PMH client and resumption token pagination
- [ ] 21.3 Test CKAN API and schema.org discovery
- [ ] 21.4 Test checksum computation and change detection
- [ ] 21.5 Test SHACL validation (passing and failing shapes)
- [ ] 21.6 Test conflict resolution all four policies
- [ ] 21.7 Test JSON-path and RML mappings
- [ ] 21.8 Test rate-limiting and exponential backoff
- [ ] 21.9 Test cron scheduling and timezone handling
- [ ] 21.10 Test provenance metadata recording and immutability
- [ ] 21.11 Test soft-delete and tombstone logic

## 22. Integration Tests

- [ ] 22.1 End-to-end DCAT feed harvest with conflict resolution
- [ ] 22.2 End-to-end OAI-PMH feed harvest with incremental runs
- [ ] 22.3 End-to-end CKAN API harvest
- [ ] 22.4 End-to-end schema.org Dataset discovery
- [ ] 22.5 Test feed registration → harvest → conflict resolution → dashboard flow
- [ ] 22.6 Test outbound DCAT endpoint discoverability by external harvesters
- [ ] 22.7 Test outbound OAI-PMH endpoint with standard OAI-PMH tools

## 23. Documentation (ADR-010 -- Documentation Standard)

- [ ] 23.1 Feature documentation at docs/features/dcat-oai-pmh-harvesting.md
- [ ] 23.2 Admin guide for registering feeds and configuring harvests
- [ ] 23.3 API documentation for DCAT and OAI-PMH endpoints (OpenAPI spec)
- [ ] 23.4 Troubleshooting guide for common harvest failures
- [ ] 23.5 Data model documentation (entity relationship diagrams)
- [ ] 23.6 Mapping rules examples (JSON-path, RML templates)

## 24. Internationalization (ADR-005 -- i18n Strategy)

- [ ] 24.1 UI labels for feed registration form (nl/en)
- [ ] 24.2 UI labels for conflict resolution modal (nl/en)
- [ ] 24.3 UI labels for dashboard (nl/en)
- [ ] 24.4 Error messages and notifications (nl/en)
- [ ] 24.5 Admin help text and field descriptions (nl/en)
- [ ] 24.6 Use l10n-ai.js to add all new translatable strings

## 25. Performance and Scale

- [ ] 25.1 Load test: harvest 10,000-item DCAT feed without memory leak
- [ ] 25.2 Load test: handle 5 concurrent harvest jobs
- [ ] 25.3 Database index on HarvestedItem(feedId, externalUri, state) for efficient lookup
- [ ] 25.4 Implement pagination for manual-review queue (>100 conflicts)
- [ ] 25.5 Implement log pagination and chunking (avoid loading massive logs into memory)
- [ ] 25.6 Add performance monitoring (harvest duration, items/second throughput)
