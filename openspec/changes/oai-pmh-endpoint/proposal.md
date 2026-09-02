---
kind: code
depends_on: []
---

# Proposal: oai-pmh-endpoint

First slice of the re-scoped `dcat-oai-pmh-harvesting` umbrella (see that
change's proposal for the disposition of the whole scope).

## Summary

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

- No inbound harvesting of any protocol (later slices: `harvest-feed-intake`
  and `harvest-protocol-plugins`)
- No new schemas or stored state (resumption tokens are stateless)
- No `oai_datacite`, no EDM

## Capabilities

### New Capabilities

- `oai-pmh-endpoint`: outbound OAI-PMH 2.0 repository per catalog, projected
  from the same objects and visibility predicate as `dcat-ap-harvest`.
