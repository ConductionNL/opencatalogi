---
kind: code
depends_on: [harvest-feed-intake]
---

# Proposal: harvest-protocol-plugins

Fourth slice of the re-scoped `dcat-oai-pmh-harvesting` umbrella. Delta spec
authored at pickup.

## Summary

Extend the harvest handler beyond DCAT JSON-LD with three more inbound
protocols behind the same feed/item/run model:

- **DCAT Turtle / RDF-XML** (an RDF parsing dependency enters here, not
  earlier; the JSON-LD slice needs none)
- **OAI-PMH client**: Identify probe, ListRecords with resumption-token
  following, `from` incremental harvesting, deleted-status handling; the
  outbound `oai-pmh-endpoint` capability is the natural integration fixture
  (harvest our own endpoint in tests)
- **CKAN API**: `package_list`/`package_show` walk with mapping to the local
  schema
- **schema.org Dataset**: sitemap walk + JSON-LD `<script>` extraction

Each protocol is a plugin behind one interface; feed `protocol` becomes an
open enum. Everything else (scheduling, mapping, checksums, conflicts,
provenance, tombstones) is already owned by the earlier slices and MUST NOT
be re-implemented per protocol.

Woo capability programme (amendment 2026-10-05):

- Rows: 1.6 (the website and CMS half: REQ-HPP-001 to REQ-HPP-003, plugins `website-sitemap` and `wordpress-rest`).
- Wave: 2.
- Depends on: `harvest-feed-intake` (opencatalogi, open change, 0 of 9 tasks; no `[OpenSpec]` issue found) for the feed, item and run model and its outbound-URL guard.
- Decision: D10 struck OAI-PMH as an endpoint we serve (row 9.10), so task 2.2 harvests a recorded OAI-PMH fixture, not `oai-pmh-endpoint`.
- Build rules: openspec/woo-build-rules.md

## Non-Goals

- SHACL validation and dashboards (`harvest-observability`)
- RML mapping (cut from the programme; JSON-path only)

## Capabilities

### Modified Capabilities

- `harvest-feed-intake`: feed `protocol` accepts `dcat-jsonld`, `dcat-rdf`,
  `oai-pmh`, `ckan-api`, `schema-org-dataset`, each served by a registered
  protocol plugin.

## Amendment 2026-10-05: Woo capability programme

Row 1.6, from `opencatalogi/_round1/compare/M1-rows.md`: "Records arrive from a website or content management system". Ours (`baseline/openwoo.tsv`): partial, production. Evidence: "opencatalogi lib/Service/CmsMigrationService.php (a one way migration from a CMS, not a standing source)". Re-checked on development at 35999c296: the evidence names the wrong thing. `CmsMigrationService` moves OpenCatalogi's own CMS pages and menus onto portaliq; it reads no external website or CMS at all. So nothing takes records from a website or CMS today, and the row's missing half is the whole standing source.

This change is still not started (0 of 9 tasks) and still waits on `harvest-feed-intake` (0 of 9; neither has a delta spec yet). The amendment writes the CMS half of the delta spec now, against the feed, item and run model `harvest-feed-intake` defines (`HarvestFeed`, `HarvestedItem`, `HarvestRun`, scheduling on an OpenRegister flow, checksum change detection, provenance), so it is not left for pickup. The other plugins (DCAT RDF, OAI-PMH client, CKAN, schema.org Dataset) stay as task 0.1 has them. Decision D10 struck OAI-PMH as an endpoint we serve (row 9.10); it did not strike the OAI-PMH client here, but `oai-pmh-endpoint` no longer exists to harvest in task 2.2, so 2.2 must use a fixture instead.

What is added (REQ-HPP-001 to REQ-HPP-003): two protocol plugins, `website-sitemap` (walk a sitemap, read each page's schema.org JSON-LD) and `wordpress-rest` (read posts and pages from the WordPress REST API), mapping into draft publications with the source URL as provenance and change detection by checksum. Fail closed: a harvested record is always created as a draft and never made public by the harvest, and a fetch is refused for a private or internal address. Wave 2. No decision of D1 to D13 applies beyond the D10 note above.

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 1.6 | Records arrive from a website or content management system | partial (stale evidence: nothing reads an external CMS) | REQ-HPP-001 to REQ-HPP-003, scenarios "A WordPress site feeds draft publications" and "A changed page updates its draft, an unchanged one does nothing" |
