---
kind: mixed
depends_on: [openregister/app-harvest-fetchers-and-flow-node]
---

# Proposal: harvest-feed-intake

Second slice of the re-scoped `dcat-oai-pmh-harvesting` umbrella. Revised in spec round part 2 (7 October 2026, decision 80): OpenRegister now specifies app harvesting itself in `openregister/app-harvest-fetchers-and-flow-node`, so this change drops the three schemas, the flow materialiser and the harvest node it carried, and keeps only what is OpenCatalogi's.

## Summary

An administrator registers an external DCAT feed (JSON-LD) on the settings page. OpenCatalogi stores it as an OpenRegister `Source` it owns, of type `opencatalogi.dcat-jsonld`, and contributes the fetcher that reads a DCAT catalogue. OpenRegister's harvest node runs it on the source's flow: on a schedule, or when the administrator clicks Run now. Each dataset lands as a draft publication that names its source. A harvest never publishes.

## What OpenCatalogi builds

- The DCAT JSON-LD fetcher, registered through OpenRegister's `RegisterSourceFetchersEvent` as a whole-document fetcher (`IBatchSourceFetcher`).
- The feed settings: a "Harvest feeds" section on the settings page (board `OcInstellingen`), one card per feed with Run now and Switch off, and a modal to add or edit a feed.
- The source settings that keep harvested datasets drafts: `protectedFields` and `provenance` on every OpenCatalogi source, and two provenance properties on the publication schema.

## What OpenRegister does (not built here)

From `openregister/app-harvest-fetchers-and-flow-node` design D-12:

| Was in this change | Now |
|---|---|
| schema `harvest-feed` | OpenRegister `Source` with `application: opencatalogi`, `type: opencatalogi.dcat-jsonld` and the feed settings in `config` |
| schema `harvested-item` | OpenRegister `SyncRecord` |
| schema `harvest-run` | the run of the source's flow; the summary is the output of node `openregister.harvest-source` |
| node `opencatalogi.harvest-feed`, flow materialiser, checksums, tombstones, URL guard | OpenRegister's harvest node, flow per source, pipeline and `HarvestHttpClient` |

## Rows

- `od-harvest`: "Harvest datasets from another DCAT or CKAN portal into a catalogue on a schedule." This change covers DCAT; CKAN follows in `harvest-protocol-plugins`. State stays `specified` until this change and its OpenRegister dependency are built.

## Non-goals

- Turtle, RDF/XML, OAI-PMH, CKAN and schema.org inbound: `harvest-protocol-plugins`.
- Conflict policies and the review queue: `harvest-conflict-policies`.
- SHACL validation, run dashboards and log retention: `harvest-observability`.

## Capabilities

### New capabilities

- `harvest-feed-intake`: DCAT JSON-LD feeds registered on the settings page and harvested by OpenRegister into draft publications with provenance.
