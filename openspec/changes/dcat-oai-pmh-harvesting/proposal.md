# DCAT + OAI-PMH Harvesting

## Problem
OpenCatalogi operates as an isolated instance unable to participate in the open standards-based ecosystem. The Dutch open-data landscape standardizes on DCAT-AP-NL 2.1 (Forum Standaardisatie mandate) and OAI-PMH 2.0 (used by libraries, archives, and aggregators like Europeana and the European Data Portal). Without these protocols, OpenCatalogi catalogs are invisible to data.overheid.nl and other canonical aggregators, and cannot import content from competitors' CKAN/Drupal/Plone catalogs.

## Proposed Solution
Implement a bidirectional bridge between OpenCatalogi and the open-data ecosystem:

**Outbound (expose)**: Each catalog exposes DCAT-AP-NL 2.1 endpoints (JSON-LD + Turtle + RDF/XML), OAI-PMH 2.0 endpoints with Dublin Core + DCAT metadata prefixes, and sitemap-based discovery hints for harvesters.

**Inbound (harvest)**: Admins can register external feeds (DCAT, OAI-PMH, CKAN API, schema.org Dataset JSON-LD), schedule via cron, and monitor a per-feed dashboard. SHACL validation prevents corrupted upstream data. Conflict resolution (shadow-local, overlay, reject, manual-review) determines whether harvested items shadow or coexist with local items.

Change detection via checksums ensures unchanged items skip re-write. Provenance metadata (`dct:source`, `prov:wasDerivedFrom`) tracks the source of harvested content.

## Scope
- Outbound: DCAT-AP-NL 2.1 JSON-LD/Turtle endpoints, OAI-PMH 2.0 with resumption tokens and language tags, sitemaps
- Inbound: Harvesters for DCAT (JSON-LD/RDF), OAI-PMH (with resumption token following), CKAN API, schema.org Dataset JSON-LD  
- Persistence: HarvestFeed, HarvestedItem, HarvestRun schemas with per-run logs and 30-day retention
- Scheduling: Cron expressions with timezone awareness
- Validation: SHACL shapes (DCAT-AP-NL published shape) per feed
- Conflict resolution: Four policies (shadow-local, overlay, reject-on-conflict, manual-review) with manual-review UI
- Rate-limiting: Exponential backoff on 429/5xx

## Success Criteria
- DCAT-AP-NL 2.1 endpoint per catalog returns valid metadata
- OAI-PMH 2.0 endpoint with correct resumption tokens
- Inbound DCAT/OAI-PMH feeds harvest and validate without data loss
- Conflict-policy enforcement prevents downstream poisoning
- Cross-gemeente sharing of zaaktypes and standards becomes auditable
- data.overheid.nl can discover and harvest OpenCatalogi feeds
