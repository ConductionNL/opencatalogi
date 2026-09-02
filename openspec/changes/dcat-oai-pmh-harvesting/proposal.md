---
kind: spec-only
depends_on: []
---

# Proposal: dcat-oai-pmh-harvesting (superseded — re-scoped 2026-09-02)

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
| Outbound OAI-PMH 2.0 | `openspec/changes/oai-pmh-endpoint` — **first shippable slice**, fully authored (proposal/design/tasks/delta spec) |
| Feed registration, scheduling, inbound DCAT JSON-LD, checksums, provenance, tombstones | `openspec/changes/harvest-feed-intake` — scheduling moves onto the OR flow engine (`TriggerScheduleNode` with explicit `runAs`, One Engine wave 5); the app-local cron parser/scheduler/dispatcher tasks are cut |
| Conflict policies + manual-review UI | `openspec/changes/harvest-conflict-policies` |
| Inbound DCAT-RDF, OAI-PMH client, CKAN, schema.org | `openspec/changes/harvest-protocol-plugins` |
| SHACL validation, per-feed dashboard, run logs/retention | `openspec/changes/harvest-observability` |
| RML mapping engine | **Cut.** JSON-path only; nobody asked for RML |
| `oai_datacite` prefix | **Cut.** No consumer asked for it |
| App-local cron expression parser / scheduler / job dispatcher | **Cut.** The OR flow engine owns scheduling (One Engine) |

## Sequencing

`oai-pmh-endpoint` is independent and ready to hand to an agent today. The
inbound slices are `harvest-feed-intake` → then, in any order,
`harvest-conflict-policies` / `harvest-protocol-plugins` /
`harvest-observability` (each declares `depends_on: [harvest-feed-intake]`).

## Archival

This directory is retired in place (not moved) so no path anywhere breaks.
Archive it via the normal flow once all five slices have shipped or been
deliberately dropped.
