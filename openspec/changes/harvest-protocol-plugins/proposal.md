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
  earlier — the JSON-LD slice needs none)
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

## Non-Goals

- SHACL validation and dashboards (`harvest-observability`)
- RML mapping (cut from the programme; JSON-path only)

## Capabilities

### Modified Capabilities

- `harvest-feed-intake`: feed `protocol` accepts `dcat-jsonld`, `dcat-rdf`,
  `oai-pmh`, `ckan-api`, `schema-org-dataset`, each served by a registered
  protocol plugin.
