---
kind: spec-only
depends_on: []
---

# Proposal: dcat-oai-pmh-harvesting (superseded, re-scoped 2026-09-02)

This umbrella sat at 0/177 tasks: far too fat for anyone to ever start, and
it double-counted work that had already shipped. It is superseded by five
sequenced changes of shippable size. No code ever carried `@spec` tags into
this change, so nothing dangles; the original 177-task list and the 51-
scenario delta spec are removed with this re-scope (they remain in git
history) and their surviving content lives in the slices below.

## Disposition of the original scope

| Original scope | Where it went |
| --- | --- |
| Outbound DCAT-AP-NL 2.1 (JSON-LD/Turtle/RDF-XML, content negotiation, sitemap hints) | **Already shipped** before the re-scope: live spec `openspec/specs/dcat-ap-harvest/spec.md` (DCAT-001..010; `DcatService`, `DcatController`, `DcatMappingService`, `SitemapService`) |
| Outbound OAI-PMH 2.0 | `openspec/changes/oai-pmh-endpoint`. Decision D10 (2026-10-05) struck the OAI-PMH protocol; that change now keeps only a changed-since route for re-users |
| Feed registration, scheduling, inbound DCAT JSON-LD, checksums, provenance, tombstones | `openspec/changes/harvest-feed-intake`. Since decision 80 (2026-10-07) feeds, items, runs, scheduling, checksums, provenance and tombstones are OpenRegister's (`openregister/app-harvest-fetchers-and-flow-node`): a feed is a `Source`, an item a `SyncRecord`, a run the source's flow run. OpenCatalogi keeps the DCAT JSON-LD fetcher, the feed settings and the draft-only source settings |
| Conflict policies + manual-review UI | `openspec/changes/harvest-conflict-policies`, on OpenRegister's conflict strategies and resolve API |
| Inbound DCAT-RDF, OAI-PMH client, CKAN, schema.org, website, WordPress | `openspec/changes/harvest-protocol-plugins`, each a fetcher registered with OpenRegister |
| SHACL validation, per-feed dashboard, run logs/retention | `openspec/changes/harvest-observability`: validation in the DCAT fetchers, the page reads OpenRegister's sync records and flow runs, retention is OpenRegister's flow run retention |
| RML mapping engine | **Cut.** JSON-path only; nobody asked for RML |
| `oai_datacite` prefix | **Cut.** No consumer asked for it |
| App-local cron expression parser / scheduler / job dispatcher | **Cut.** The OR flow engine owns scheduling (One Engine) |
| Harvest schemas `harvest-feed`, `harvested-item`, `harvest-run` | **Dropped** (decision 80). OpenRegister's `Source`, `SyncRecord` and flow runs replace them |

## Sequencing

`oai-pmh-endpoint` is independent. The
inbound slices are `harvest-feed-intake` → then, in any order,
`harvest-conflict-policies` / `harvest-protocol-plugins` /
`harvest-observability` (each declares `depends_on: [harvest-feed-intake]`).

## Archival

This directory is retired in place (not moved) so no path anywhere breaks.
Archive it via the normal flow once all five slices have shipped or been
deliberately dropped.

## The one rule this umbrella keeps

Revised in spec round part 3 (7 October 2026). The slices change; one rule holds for all of them and is the delta spec of this umbrella: OpenCatalogi ships no harvest store, scheduler or pipeline of its own. Every inbound harvest is an OpenRegister source with a fetcher OpenCatalogi registers.
