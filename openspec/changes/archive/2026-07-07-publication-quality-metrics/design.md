# Design: publication-quality-metrics

## Context

Verified at HEAD `4d8b395`:
- The DCAT layer already renders a complete `dcat:Dataset` per publication
  (`DcatMappingService`/`DcatService`), including publisher, license, themes,
  keywords, and distributions with format/download-URL (DCAT-004…006).
- The only quality signal is `DcatService::validateCatalog` /
  `mandatoryViolations` (`DcatService.php:438-481`) — a boolean presence check of
  `dct:title`/`dct:publisher`/`dcat:landingPage`, advisory + admin-only.
- `publication-usage-analytics` (ANA-001…008) established the pattern for
  privacy-safe derived metrics: aggregate-only counters stored as OR objects
  (`UsageCounterService`), an authenticated stats API (`StatsController`),
  catalog roll-ups, a dashboard widget, CSV export, and Prometheus families.

## Decisions

### D1 — Score is derived from the rendered DCAT dataset, not re-mapped
`QualityService` takes the `dcat:Dataset` node the existing mapping already
produces and scores it. This guarantees the score reflects exactly what a
harvester sees, and avoids a second, drifting mapping. No new query layer
(ADR-022).

### D2 — Five MQA dimensions, 0–100 total
Mirror the EU MQA methodology so the score is comparable to data.europa.eu:

| Dimension | Signals from the DCAT dataset |
|-----------|-------------------------------|
| Findability | `dcat:keyword`, `dcat:theme` bound to a taxonomy, `dct:spatial`/`dct:temporal` when present |
| Accessibility | resolvable `dcat:accessURL` / `dcat:downloadURL` (status reachable) |
| Interoperability | non-proprietary `dct:format` + `dcat:mediaType`, machine-readable |
| Reusability | `dct:license`, `dct:publisher`, `dcat:contactPoint`, rights |
| Contextuality | `dct:issued`/`dct:modified`, `dcat:byteSize`, documentation |

Each dimension yields a sub-score; the total is a weighted 0–100. Weights are
admin-config, defaulting to the EU MQA weights.

### D3 — Advisory, privacy-safe, derived storage only
The score never gates publishing and never alters visibility. If persisted for
roll-ups, it is a derived metric object on OR (same shape as `usageCounter`),
recomputed on publication save/retention evaluation — no bespoke table, no
request/PII data (the score is over metadata, not readers).

### D4 — DQV in the feed is optional and additive
When enabled per catalog, the DCAT serializer adds `dqv:hasQualityMeasurement`
nodes (W3C Data Quality Vocabulary) so national/EU MQA can read the score
without recomputation. Off by default; additive to the existing graph.

### D5 — Dashboard reuses the analytics surface
The per-catalog roll-up (average, distribution, worst-N, missing-property
breakdown) is served through the authenticated stats API and rendered as a
dashboard widget, reusing the `publication-usage-analytics` ANA-005/ANA-006
patterns rather than a new settings/stats framework.

## Requirement map

| ID | Capability: publication-quality |
|----|---------------------------------|
| PQM-001 | Per-publication MQA/FAIR score over five dimensions, derived from the DCAT dataset |
| PQM-002 | Optional DQV (`dqv:QualityMeasurement`) exposure in the DCAT feed |
| PQM-003 | Per-catalog quality dashboard/roll-up (authenticated), reusing the analytics surface |
| PQM-004 | Score is advisory + privacy-safe + derived-storage-only |

## Testing

Newman: a fully-populated seed publication scores high; a seed publication
missing license + theme scores lower with those exact dimensions penalised; the
catalog roll-up returns average + worst-N; DQV nodes appear only when enabled.
`@e2e exclude` — backend/API scoring; the dashboard widget is covered by the
analytics widget e2e already in place.
