# Tasks: publication-quality-metrics

This change is `kind: mixed` (ADR-032): admin-config weights + a per-catalog DQV
toggle (config) plus the scoring/roll-up/serializer code. Config keys land in the
admin-settings inventory first.

- [ ] Freeze the delta spec under
  `openspec/changes/publication-quality-metrics/specs/publication-quality/spec.md`
  (ADDED PQM-001…004); confirm `openspec validate publication-quality-metrics --strict` is green
  - Spec ref: specs/publication-quality/spec.md (this change)
  - Acceptance: validator reports valid
- [ ] Add admin-config: MQA dimension weights (default EU MQA weights) and a
  per-catalog `dqvExposure` toggle (default off); register both in the
  `admin-settings` inventory
  - Spec ref: PQM-001, PQM-002
  - Acceptance: keys present in the admin-settings inventory; defaults applied
- [ ] Add a `QualityService` that consumes the rendered `dcat:Dataset` for a
  publication and scores the five MQA dimensions into a weighted 0–100 total with
  a per-dimension breakdown naming missing/invalid properties
  - Spec ref: PQM-001
  - Acceptance: fully-populated dataset scores high; missing license/theme
    reduces reusability/findability and is named in the breakdown
- [ ] Extend the DCAT serializer to emit `dqv:hasQualityMeasurement` nodes when a
  catalog's `dqvExposure` is on; additive only (existing triples unchanged)
  - Spec ref: PQM-002
  - Acceptance: DQV nodes present when enabled; byte-identical DCAT when disabled
- [ ] Add the per-catalog quality roll-up on the authenticated stats API
  (average, distribution, worst-N, aggregate missing-property breakdown),
  aggregating over the OR object-search path; guard per-object reads (no IDOR);
  add a dashboard widget reusing the analytics widget surface
  - Spec ref: PQM-003
  - Acceptance: roll-up returns average + worst-N; anonymous callers rejected
- [ ] Persist roll-ups (if needed) as derived `qualityMetric` OR objects
  (usageCounter-style), recomputed on save/retention evaluation; no bespoke
  table; no reader/PII data
  - Spec ref: PQM-004
  - Acceptance: derived metric objects only; score never gates publishing; no PII
- [ ] Newman: assert high vs. penalised scores, DQV on/off, catalog roll-up, and
  anonymous rejection of the quality stats endpoint
  - Spec ref: PQM-001…004
  - Acceptance: Newman collection green against a seeded catalog
