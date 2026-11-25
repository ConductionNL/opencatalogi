---
kind: code
depends_on: []
---

# Proposal: oai-pmh-endpoint

First slice of the re-scoped `dcat-oai-pmh-harvesting` umbrella (see that
change's proposal for the disposition of the whole scope).

## Summary

A re-user reads only published records through one read-only route per catalogue, `GET /api/{catalogSlug}/changes?since=<moment>`, and learns what changed and what was withdrawn since that moment. Decision D10 struck the OAI-PMH endpoint this change first specified; do not build OAI-PMH.

- Rows: 8.12 (OAI-006, OAI-007). Row 9.10 is struck by D10.
- Wave: 1.
- Depends on: nothing new. Reuses `DcatMappingService` and the anonymous public read of `PublicationQueryService`.
- Decision: D10 (9.10 struck; this change keeps only 8.12). D5, row 5.5, sets the scope of a withdrawn entry (id and date, no title or content).
- Build rules: openspec/woo-build-rules.md

## Original summary (struck by D10, not to be built)

Expose each WOO/DCAT-enabled catalog as an OAI-PMH 2.0 repository:
`GET /catalog/{slug}/oai?verb=...` implementing the six protocol verbs
(Identify, ListMetadataFormats, ListSets, ListIdentifiers, ListRecords,
GetRecord) with resumption-token pagination, `from`/`until` selective
harvesting and the `oai_dc` + `dcat` metadata prefixes. Records are
projections of the same publication objects the live `dcat-ap-harvest`
capability already renders — no new persistence, no harvesting, no inbound
anything.

## Motivation

Outbound DCAT already shipped (`openspec/specs/dcat-ap-harvest/spec.md`,
DCAT-001..010: `DcatService`, `DcatController`, content negotiation,
harvester-grade pagination). What keeps OpenCatalogi invisible to
library/archive aggregators (Europeana, NARCIS, BASE, KB) is the missing
OAI-PMH surface — those harvest OAI-PMH, not DCAT. This slice closes exactly
that gap and nothing else, which makes it shippable in one change: the
object-to-metadata mapping reuses `DcatMappingService`, the visibility rules
reuse DCAT-003 (only publicly visible objects), and the endpoint shape
follows the existing `DcatController`.

## Scope

- `GET /catalog/{slug}/oai` controller: the six verbs, OAI-PMH XML envelope,
  `badVerb`/`badArgument`/`noRecordsMatch`/`idDoesNotExist`/
  `badResumptionToken`/`cannotDisseminateFormat` error codes
- Metadata prefixes: `oai_dc` (Dublin Core, mapped from the DCAT projection)
  and `dcat` (the existing JSON-LD dataset node, wrapped per record);
  `oai_datacite` is OUT (cut in the re-scope — no consumer asked for it)
- Sets: one OAI-PMH set per DCAT-enabled catalog slug
- Resumption tokens: stateless (encoded cursor + filters + expiry), same
  page-size discipline as DCAT-008
- `from`/`until` on the publication's modified timestamp; `deletedRecord:
  transient` with tombstone headers for depublished objects the feed
  previously exposed
- Language tags (`xml:lang`) on Dublin Core elements where the source field
  carries a language

## Non-Goals

- No inbound harvesting of any protocol. Inbound harvesting runs on
  OpenRegister (`openregister/app-harvest-fetchers-and-flow-node`, decision 80),
  with OpenCatalogi's fetchers in `harvest-feed-intake` and
  `harvest-protocol-plugins`. A re-user who harvests this changes route with
  an OpenRegister source reads withdrawn entries as records to tombstone.
- No new schemas or stored state (resumption tokens are stateless)
- No `oai_datacite`, no EDM

## Capabilities

### New Capabilities

- `oai-pmh-endpoint`: outbound OAI-PMH 2.0 repository per catalog, projected
  from the same objects and visibility predicate as `dcat-ap-harvest`.

## Amendment 2026-10-05: Woo capability programme

Decision D10 (Ruben, 2026-10-05): row 9.10 (OAI-PMH) is struck, and this change keeps only row 8.12. The OAI-PMH protocol requirements OAI-001 to OAI-005 are removed; tasks groups 1 to 5 above specify them and are not to be built. The DCAT dataset node and a changed-since read for re-users stay.

Row 8.12, from `opencatalogi/_round1/compare/M1-rows.md`: "A re-user reads only published records through a separate read-only access point, and can ask what changed since a given moment". Ours: partial, production. Evidence (`baseline/openwoo.tsv`): "publications#index GET /api/{catalogSlug} is an anonymous read-only API that lib/Service/PublicationQueryService.php runs inside OR runAsAnonymous so only published records return, paged and capped at PUBLIC_LIMIT_MAX 100; a since query rides only on OpenRegister's generic @self metadata operator filter, which neither openapi.json nor any test names, and a withdrawn record simply vanishes so a re-user cannot learn what was removed".

What is added: one read-only route per catalogue, `GET /api/{catalogSlug}/changes?since=<moment>`, answering the published records changed since that moment as DCAT dataset nodes, and the records withdrawn since then as id and date only (OAI-006, OAI-007). It reuses `DcatMappingService` for the node and the anonymous public read of `PublicationQueryService`. Fail closed: a withdrawn entry is listed only for a record that was once public, and carries no title or content, in line with the tombstone scope of decision D5 (row 5.5). It is wave 1 and depends on nothing new; when `publication-lifecycle-on-or` lands, `firstReleasedAt` replaces the depublication-record test for "once public", and when `publication-withdrawal-aftercare` lands the withdrawn entry links to its tombstone.

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 8.12 | A re-user reads only published records through a separate read-only access point, and can ask what changed since a given moment | partial | OAI-006 and OAI-007, scenarios "A re-user asks what changed since yesterday" and "A withdrawn record is reported, not shown" |
| 9.10 | (struck by D10) | | not built |
