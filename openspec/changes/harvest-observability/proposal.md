---
kind: mixed
depends_on: [harvest-feed-intake]
---

# Proposal: harvest-observability

Fifth slice of the re-scoped `dcat-oai-pmh-harvesting` umbrella. Delta spec
authored at pickup.

## Summary

Make harvesting inspectable and validated:

- **SHACL validation**: per-feed shape URL plus the bundled DCAT-AP-NL
  published shape; a failing item becomes `rejected` with the violation as
  its reason. The validator library choice is a pickup-time decision
  (bundled shape + library pinned and audited).
- **Per-feed dashboard**: last/next run, item-state buckets, error trend over
  recent runs, "Harvest Now" (which triggers the feed's OR flow — never a
  second execution path).
- **Run logs**: structured per-run logging with a paginated viewer and a
  configurable 30-day retention pass that rides the existing retention
  machinery's discipline (idempotent daily evaluation, stamped last-evaluated).

## Non-Goals

- New protocols or policies; log export formats beyond JSON.

## Capabilities

### New Capabilities

- `harvest-observability`: SHACL gatekeeping, the per-feed dashboard and
  retained run logs.
