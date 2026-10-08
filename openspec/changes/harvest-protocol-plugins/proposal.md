---
kind: code
depends_on: [harvest-feed-intake, openregister/app-harvest-fetchers-and-flow-node]
---

# Proposal: harvest-protocol-plugins

Fourth slice of the re-scoped `dcat-oai-pmh-harvesting` umbrella. Revised in spec round part 3 (7 October 2026): harvesting runs on OpenRegister (`openregister/app-harvest-fetchers-and-flow-node`, decision 80). A protocol is no longer a plugin in an OpenCatalogi registry behind a `HarvestFeed`; it is a fetcher OpenCatalogi registers with OpenRegister through `RegisterSourceFetchersEvent`, of type `opencatalogi.<protocol>`. OpenRegister's `SourceFetcherRegistry` is the registry, a feed is an OpenRegister `Source`, an item is a `SyncRecord`, and change detection, tombstones, provenance, conflicts and the outbound guard are the pipeline's.

## Summary

Six more inbound protocols beside DCAT JSON-LD (`harvest-feed-intake`), each one fetcher class:

| type | reads | fetcher kind |
|---|---|---|
| `opencatalogi.dcat-rdf` | a DCAT catalogue in Turtle or RDF/XML | whole document (`IBatchSourceFetcher`) |
| `opencatalogi.oai-pmh` | an OAI-PMH repository: `ListRecords` with resumption tokens, `from` incremental, deleted records | whole document, paged |
| `opencatalogi.ckan-api` | a CKAN portal: `package_search` paged | whole document, paged |
| `opencatalogi.schema-org-dataset` | a sitemap, each page's JSON-LD `Dataset` | whole document, paged |
| `opencatalogi.website-sitemap` | a sitemap, each page's JSON-LD of the types the feed names | whole document, paged |
| `opencatalogi.wordpress-rest` | `wp-json/wp/v2/posts` and `/pages`, paged, `modified_after` | whole document, paged |

Every one returns raw items keyed by a stable external id and says whether it read the whole source (`complete`). Mapping onto the publication goes through the source's OpenRegister mapping, as for DCAT JSON-LD. Draft-only, provenance and identity come from the source settings `harvest-feed-intake` writes on every OpenCatalogi source (`protectedFields`, `provenance`, `identityProperty`, `deleteStrategy: flag`, `conflictStrategy: manual`).

Woo capability programme (amendment 2026-10-05):

- Rows: 1.6 (the website and CMS half: `website-sitemap` and `wordpress-rest`).
- Wave: 2.
- Decision: D10 struck OAI-PMH as an endpoint we serve (row 9.10), so the OAI-PMH client is tested against a recorded fixture, not against this instance.
- Build rules: openspec/woo-build-rules.md

Row 1.6, from `opencatalogi/_round1/compare/M1-rows.md`: "Records arrive from a website or content management system". Ours: partial, with stale evidence (`CmsMigrationService` moves OpenCatalogi's own CMS pages onto portaliq; it reads no external website). Nothing takes records from a website or CMS today.

## What OpenRegister does (not built here)

| Was in this slice | Now |
|---|---|
| protocol plugin interface and registry | `SourceFetcherInterface`, `IBatchSourceFetcher`, `SourceFetcherRegistry`, `GET /api/sources/types` |
| `HarvestFeed.protocol` enum | `Source.type` |
| SHA-256 checksum per item, `new`/`updated`/`unchanged` | the pipeline's hash and `SyncRecord.status` |
| soft tombstone flag | `SyncRecord.tombstoned` after a `complete` run |
| `dct:source` / `prov:wasDerivedFrom` | `Source.provenance`, set after mapping |
| outbound URL guard, timeouts, backoff | `HarvestHttpClient` with `OutboundUrlGuard` |
| parking an update to a published record | `conflictStrategy: manual` and the collision rules (REQ-HAF-006, REQ-HAF-007) |

## Non-goals

- SHACL validation and the feed page (`harvest-observability`).
- RML mapping (cut from the programme; OpenRegister mappings only).
- A registry, checksum, tombstone or URL guard of OpenCatalogi's own.

## Capabilities

### Modified capabilities

- `harvest-feed-intake`: six more source types an administrator can pick in the feed form, each a fetcher OpenCatalogi registers with OpenRegister.
