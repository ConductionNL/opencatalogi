---
kind: mixed
depends_on: []
---

# Proposal: harvest-feed-intake

Second slice of the re-scoped `dcat-oai-pmh-harvesting` umbrella. The delta
spec is authored when this change is picked up, so it is written against the
OR engine surface of that moment instead of going stale here.

## Summary

Inbound harvesting, minimum viable and one protocol only: an admin registers
an external DCAT feed (JSON-LD), a scheduled OpenRegister flow fetches and
maps it, harvested items land as local objects with provenance, and
checksum-based change detection keeps re-runs cheap. Conflict handling in
this slice is deliberately binary: an incoming item that would collide with
a locally-authored object is parked as `conflict` and skipped — the policy
vocabulary and review UI are the next slice (`harvest-conflict-policies`).

## Scope

- OR schemas (register fragment per ADR-037): `HarvestFeed` (name, sourceUrl,
  protocol [fixed `dcat-jsonld` in this slice], schedule, enabled,
  targetCatalog, targetSchema, itemMapping [JSON-path only], maxItemsPerRun),
  `HarvestedItem` (feedId, externalUri, localObjectId, checksum, state
  [new|updated|unchanged|conflict], firstSeenAt, lastSeenAt, sourceRevision),
  `HarvestRun` (feedId, startedAt, finishedAt, per-state counts, errors)
- **Scheduling is the OR flow engine, not an app cron** (One Engine wave 5):
  each enabled feed materialises a flow whose `TriggerScheduleNode` carries
  the feed's schedule and an explicit `runAs` (OR requires it — the owner is
  no fallback); the harvest step is the app's contributed node/handler.
  The app MUST NOT ship its own cron parser, scheduler or background-job
  dispatcher — those umbrella tasks are cut.
- Fetching MUST reuse the fleet HTTP discipline (SSRF outbound-URL guard,
  timeouts, backoff); if integriq (OpenConnector) is installed its source
  abstraction MAY be the fetch layer, but this slice MUST NOT hard-depend on
  it
- JSON-path item mapping (no RML — cut), checksum (SHA-256 over normalised
  payload), provenance (`dct:source`, `prov:wasDerivedFrom`) on the local
  object, soft tombstone flag for items that disappear upstream
- Feed CRUD admin surface (settings section, no dashboard yet)

## Non-Goals

- Turtle/RDF-XML, OAI-PMH, CKAN, schema.org inbound → `harvest-protocol-plugins`
- Conflict policies, manual review, restore UI → `harvest-conflict-policies`
- SHACL validation, run dashboards, log retention → `harvest-observability`

## Capabilities

### New Capabilities

- `harvest-feed-intake`: registered DCAT JSON-LD feeds harvested on an OR
  flow schedule into local objects with provenance and change detection.
