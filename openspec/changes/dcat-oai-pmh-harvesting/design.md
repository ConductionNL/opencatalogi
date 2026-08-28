# Design: DCAT + OAI-PMH Harvesting

## Context

OpenCatalogi must become a full participant in the open-standards ecosystem used by data.overheid.nl, the European Data Portal, and library/archive aggregators (Europeana, NARCIS, BASE, KB). The natural driving use-case is cross-gemeente sharing of zaaktypes, standaarden, selectielijsten (Archiefwet), beleidsregels, and APV-catalogi — Tilburg publishes; Eindhoven harvests nightly; differences become visible; convergence becomes auditable.

## Goals / Non-Goals

**Goals:**
- Outbound DCAT-AP-NL 2.1 JSON-LD + Turtle + RDF/XML endpoints per catalog
- Outbound OAI-PMH 2.0 with oai_dc, oai_datacite, and dcat metadata prefixes, resumption tokens, and language tags
- Inbound harvesting from DCAT feeds (JSON-LD, Turtle, RDF/XML)
- Inbound harvesting from OAI-PMH endpoints with resumption-token following
- Inbound harvesting from CKAN API endpoints
- Inbound harvesting from schema.org Dataset JSON-LD (sitemap discovery)
- Per-feed SHACL validation against DCAT-AP-NL published shape
- Configurable cron scheduling with timezone awareness per feed
- Conflict resolution policies: shadow-local, overlay, reject-on-conflict, manual-review
- Per-feed status dashboard: last-run, item-state buckets, error trends
- Change detection via checksum to skip unchanged items
- Item-level mapping (JSON-path or RML) from source to local schema
- Provenance metadata (dct:source, prov:wasDerivedFrom) on harvested items
- Soft-delete of items disappearing from upstream (tombstone pattern)
- Rate-limited fetch with exponential backoff

**Non-Goals:**
- Automatic machine translation of harvested content
- Real-time event-driven harvesting (cron only)
- Two-way sync to upstream sources
- Direct replication without local mapping rules
- Manual per-item mapping UI (RML/JSON-path config at feed level)

## Decisions

1. **Outbound as projections**: Catalog/publication objects are projected through view-renderers (no new schema). Changes to objects immediately appear in DCAT/OAI-PMH endpoints.
2. **Inbound via OpenConnector**: Each HarvestFeed materializes as an OpenConnector source with dedicated synchronization rule. Fetch, OAuth, rate-limiting, and retries handled by OpenConnector's existing infrastructure.
3. **Conflict model**: HarvestedItem state machine (new | updated | unchanged | conflict | rejected) determines whether item enters local catalog or manual-review queue.
4. **Change detection**: Checksum of normalized payload + sourceRevision stored on HarvestedItem; unchanged items skip re-write.
5. **Retention**: Per-run logs retained 30 days (configurable), HarvestRun telemetry indefinite (for audit).
6. **Language negotiation**: Outbound uses content's native language tags; Accept-Language header and lang query parameter guide best-fit selection.

## File Changes Overview

- **API Endpoints** — Controllers for `/catalog/{slug}/dcat`, `/catalog/{slug}/dcat.ttl`, `/catalog/{slug}/oai`
- **Outbound Serializers** — RDF renderers (JSON-LD, Turtle, RDF/XML) and OAI-PMH metadata prefixes (Dublin Core, DCAT, EDM)
- **Persistence** — HarvestFeed, HarvestedItem, HarvestRun entities
- **Harvester Service** — Pluggable protocol handlers (DCAT-JSON-LD, DCAT-RDF, OAI-PMH, CKAN API, schema.org)
- **Validation** — SHACL shape loader, validator integration per feed
- **Conflict Resolution** — State machine + manual-review UI component
- **Scheduling** — Cron expression parser, background job dispatcher
- **OpenConnector Integration** — Feed-to-source mapper, sync-rule registration
- **Mapping Engine** — JSON-path / RML rule evaluator for item transformation
- **Admin UI** — Feed registration form, per-feed dashboard, conflict manual-review queue
- **Logging** — Chunked per-run log writer, structured error tracking
- **Tests** — Protocol parsers, conflict scenarios, rate-limiter backoff, SHACL validation
