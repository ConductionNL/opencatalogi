---
status: draft
---

# DCAT + OAI-PMH harvesting

## Purpose

Make OpenCatalogi a full participant in the open standards-based catalog ecosystem: both a harvestable source (other catalogs can pull from us) and a harvester (we can pull from external catalogs over standard protocols). Today OpenCatalogi has internal federation (the `federation` spec) which aggregates results across other OpenCatalogi instances via our own REST API. That covers OpenCatalogi-to-OpenCatalogi but locks us out of the wider open-data and library/archive ecosystem where DCAT 2.0 (Data Catalog Vocabulary, W3C) and OAI-PMH 2.0 (Open Archives Initiative Protocol for Metadata Harvesting) are the lingua franca.

The Dutch open-data landscape standardizes on **DCAT-AP-NL 2.1** (applied profile of DCAT for the Netherlands, mandated by Forum Standaardisatie). The national portal data.overheid.nl harvests DCAT feeds from municipalities, provinces, and ministries. The European Data Portal (data.europa.eu) harvests national portals over DCAT-AP. Library and archive networks (KB, beeldengeluid, regional historical centres, university repositories) use OAI-PMH 2.0 with Dublin Core and EDM (Europeana Data Model). PLOOI (the WOO publication platform) ingests via DCAT-flavoured feeds. Without these protocols, OpenCatalogi catalogs are invisible to the canonical aggregators and we cannot import existing content from CKAN/Drupal/Plone catalogs that competitors interface with daily.

This spec adds a bidirectional bridge. Outbound: each catalog exposes a DCAT-AP-NL endpoint, an OAI-PMH endpoint with Dublin Core + DCAT-AP metadata prefixes, and a sitemap-based discovery hint so harvesters can find new content. Inbound: an admin can register external feeds (DCAT JSON-LD/RDF, OAI-PMH, CKAN API, schema.org Dataset JSON-LD), schedule harvesting, and view a per-feed status dashboard with last-run, items-pulled, errors, conflicts. A canonical-vs-federated conflict resolution layer decides whether harvested items shadow local items or appear alongside them. Validation runs per-feed using SHACL shapes (DCAT-AP-NL provides a published shape) so corrupted upstream data does not poison the catalog.

The natural use-case driving this is **cross-gemeente sharing of zaaktypes en standaarden**: gemeente Tilburg publishes its zaaktypecatalogus; gemeente Eindhoven harvests it nightly; differences become visible; convergence becomes auditable. Same pattern for selectielijsten (Archiefwet), beleidsregels, and APV-catalogi.

## Data Model

Two main schemas plus harvest-run telemetry:

- **HarvestFeed** — id, name, sourceUrl, protocol (dcat-ap-nl | dcat-ap-eu | oai-pmh | ckan-api | schema-org-dataset | rss-atom), authType (none | basic | bearer | oauth2), credentialsRef (vault), schedule (cron expression), enabled, ownerOrganization, conflictPolicy (shadow-local | overlay | reject-on-conflict | manual-review), targetCatalog (which local catalog harvested items belong to), targetSchema (which schema to map into), itemMapping (JSON-path or RML mapping rules), shaclShapesUrl (optional validation shape), maxItemsPerRun, lastRunAt, lastRunStatus, nextRunAt.
- **HarvestedItem** — id, feedId, externalUri (the source URI), localObjectId (resolved OpenRegister object), checksum (of normalized payload, for change detection), state (new | updated | unchanged | conflict | rejected), conflictReason (if state=conflict), firstSeenAt, lastSeenAt, lastChangedAt, sourceRevision.
- **HarvestRun** — id, feedId, startedAt, finishedAt, itemsScanned, itemsNew, itemsUpdated, itemsUnchanged, itemsConflict, itemsRejected, errors[], log (chunked, paginated).

Outbound endpoints have no schema of their own; they project existing catalog/publication objects through view-renderers:

- `GET /catalog/{slug}/dcat` → DCAT-AP-NL 2.1 JSON-LD (catalog + dataset + distribution + dataservice nodes).
- `GET /catalog/{slug}/dcat.ttl` → Turtle serialization.
- `GET /catalog/{slug}/oai?verb=...` → OAI-PMH 2.0 endpoint supporting Identify, ListMetadataFormats, ListSets, ListIdentifiers, ListRecords, GetRecord.
- `GET /catalog/{slug}/sitemap-dcat.xml` → sitemap with `<changefreq>` + `<lastmod>` per dataset.

## Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| DOAI-001 | Outbound DCAT-AP-NL 2.1 endpoint per catalog (JSON-LD + Turtle + RDF/XML content negotiation) | Must |
| DOAI-002 | Outbound OAI-PMH 2.0 endpoint with `oai_dc` and `oai_datacite` and `dcat` metadata prefixes | Must |
| DOAI-003 | OAI-PMH must support resumption tokens for paginated harvesting | Must |
| DOAI-004 | OAI-PMH sets correspond to OpenCatalogi catalog slugs | Must |
| DOAI-005 | Inbound harvester for DCAT-AP feeds (JSON-LD + Turtle + RDF/XML parsing) | Must |
| DOAI-006 | Inbound harvester for OAI-PMH endpoints with resumption-token following | Must |
| DOAI-007 | Inbound harvester for CKAN API (`/api/3/action/package_list` + `package_show`) | Should |
| DOAI-008 | Inbound harvester for schema.org Dataset JSON-LD discovered via sitemap | Could |
| DOAI-009 | Configurable cron schedule per feed with timezone awareness | Must |
| DOAI-010 | Per-feed SHACL validation against DCAT-AP-NL published shape | Must |
| DOAI-011 | Conflict-resolution policy per feed: shadow-local, overlay, reject, manual-review | Must |
| DOAI-012 | Status dashboard per feed: last-run timestamp, items in each state bucket, error trend | Must |
| DOAI-013 | Per-run detailed log retained for 30 days (configurable) | Must |
| DOAI-014 | Change detection via checksum so unchanged items skip re-write | Must |
| DOAI-015 | Item-level mapping rules (JSON-path / RML) from source vocabulary to local schema | Must |
| DOAI-016 | Outbound feeds register with data.overheid.nl harvester catalog (manual one-time registration documented) | Should |
| DOAI-017 | Outbound feed includes language tags (Dutch + English where available) per DCAT-AP-NL | Must |
| DOAI-018 | Rate-limited fetch with exponential backoff on upstream 429/5xx | Must |
| DOAI-019 | Manual-review queue UI for items in `conflict` state with side-by-side diff | Should |
| DOAI-020 | OAI-PMH `from` / `until` selective harvesting on incremental runs | Must |
| DOAI-021 | Provenance triples on harvested items (`dct:source`, `prov:wasDerivedFrom`) | Must |
| DOAI-022 | Soft-delete of items that disappear from upstream (tombstone, not hard delete) | Must |
| DOAI-023 | Harvest-run summary email/notification to feed owner on failure | Should |
| DOAI-024 | Bulk-export of a catalog as a DCAT-AP RDF dump (single Turtle file) | Could |

## Standards & Sources

- **DCAT 2.0** — W3C recommendation (Data Catalog Vocabulary). Defines `dcat:Catalog`, `dcat:Dataset`, `dcat:Distribution`, `dcat:DataService` with linkage to `dct:` (Dublin Core terms), `foaf:` (publisher), `skos:` (themes).
- **DCAT-AP 2.1** — European applied profile maintained by SEMIC. Tightens cardinalities and mandates EU vocabularies (publisher type, theme, file format).
- **DCAT-AP-NL 2.1** — Dutch national profile (Forum Standaardisatie, status: verplicht). Adds NL-specific publisher list (overheid.nl resolver), TOOI vocabularies, and language tagging.
- **OAI-PMH 2.0** — Open Archives Initiative, 2002. Six verbs (Identify, ListMetadataFormats, ListSets, ListIdentifiers, ListRecords, GetRecord). Used by every major library and archive aggregator (Europeana, NARCIS, BASE).
- **Dublin Core Terms (DCMI)** — the lowest-common-denominator metadata vocabulary for OAI-PMH `oai_dc` records.
- **EDM (Europeana Data Model)** — used by Europeana; relevant for catalogs that want to feed cultural-heritage aggregators.
- **SHACL** (Shapes Constraint Language, W3C) — validates RDF against published shapes. DCAT-AP-NL publishes a SHACL shape at semiceu.github.io/DCAT-AP/.
- **CKAN API** — de facto standard for the Drupal-based data portals run by many ministries and provinces; first-party JSON over `/api/3/action/`.
- **schema.org Dataset** — Google-pushed JSON-LD embedded in HTML; harvested by Google Dataset Search and increasingly by national portals.
- **PLOOI / KOOP feeds** — Dutch national WOO platform; uses DCAT-flavoured ingest (handled in `woo-publicatie-pipeline`, this spec exposes the upstream feed shape).
- **TOOI** (Thesaurus Overheid Informatie) — controlled vocabularies for Dutch government metadata (organisaties, taxonomieën, locaties). Used in DCAT-AP-NL theme + publisher fields.
- **data.overheid.nl** — Dutch national open-data portal, harvests DCAT feeds.
- **data.europa.eu** — European Data Portal, harvests national portals over DCAT-AP.

## Cross-app integration

- **opencatalogi base** — every catalog automatically exposes the DCAT + OAI-PMH endpoints based on its schemas; no per-catalog configuration needed unless the admin wants to suppress.
- **opencatalogi/federation** — the existing OpenCatalogi-to-OpenCatalogi federation continues to work; DCAT/OAI-PMH is the path to non-OpenCatalogi peers. The federation spec's directory listing can advertise the DCAT endpoint URL alongside the federation URL.
- **opencatalogi/woo-compliance** — DIWOO sitemaps are a sibling standard; this spec's DCAT outputs and the existing DIWOO outputs both project from the same publication objects.
- **opencatalogi/auto-publishing** — auto-published items become harvestable the moment they go live (DCAT lastmod + OAI-PMH datestamp are derived from object updatedAt).
- **OpenConnector** — used for inbound harvesting: HTTP fetch, OAuth, rate-limiting, retries, mapping. Each HarvestFeed materializes as an OpenConnector source with a dedicated synchronization rule.
- **OpenRegister** — HarvestedItem.checksum + sourceRevision live on the registered object as metadata so audit history shows "this revision was harvested from feed X at time Y".
- **docudesk/metadata-enrichment** — harvested documents (e.g. from a library OAI-PMH feed) can flow into the docudesk pipeline for OCR + classification.
- **softwarecatalog** — a future use-case: harvest the OSC-NL software catalog (which has a DCAT-flavoured feed) into the Conduction softwarecatalog ecosystem.

## Target users

**Primary:**
- **Open-data coordinators** at municipalities, provinces, ministries, and waterschappen. They are mandated by the Wet hergebruik overheidsinformatie + Forum Standaardisatie to publish DCAT-AP-NL feeds and want one-click compliance.
- **WOO-coordinators** publishing to PLOOI need DCAT-flavoured outputs.
- **Architecture teams** running multi-catalog federations (across departments or across collaborating gemeenten).

**Secondary:**
- **Library and archive curators** (regional historical centres, KB, university repositories) using OAI-PMH to expose holdings to Europeana / NARCIS / BASE.
- **Research-data managers** at universities and ministries (RWS, RIVM) who want their datasets harvested by data.overheid.nl and the European Data Portal.
- **Procurement teams** importing standards catalogs (Forum Standaardisatie, NEN, ISO) into a local reference so tenders can cite live records.
- **Data-quality officers** who use the conflict-resolution queue to reconcile authoritative vs federated copies of the same record.

Tertiary: external consumers (researchers, journalists, civic-tech developers) who scrape DCAT feeds today and would prefer a clean OAI-PMH or RDF endpoint.
