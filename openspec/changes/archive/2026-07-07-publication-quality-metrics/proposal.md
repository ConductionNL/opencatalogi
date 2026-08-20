---
kind: mixed
depends_on: []
---

# Proposal: publication-quality-metrics

## Summary

Add a **metadata-quality score** to publications, computed over the DCAT-AP
**MQA** (Metadata Quality Assessment) / FAIR dimensions, plus a publisher-facing
**quality dashboard** roll-up per catalog. The score is *derived* from the same
DCAT dataset rendering the feed already produces (`dcat-ap-harvest`) — it adds
no authoritative field and no new visibility rule:

- `PQM-001`: a per-publication quality score over the five MQA dimensions
  (findability, accessibility, interoperability, reusability, contextuality),
  derived from the emitted `dcat:Dataset` (presence + validity of publisher,
  license, theme, keywords, access/download URLs, format, etc.);
- `PQM-002`: expose the score in the DCAT feed as W3C DQV
  (`dqv:QualityMeasurement`) so data.overheid.nl and the EU MQA can read it;
- `PQM-003`: a per-catalog quality dashboard/roll-up (average score, worst-N
  datasets, missing-property breakdown), reusing the stats + dashboard-widget
  pattern of `publication-usage-analytics`;
- `PQM-004`: the score is advisory (never gates publication) and privacy-safe,
  computed on the OR object-search path with no new storage beyond a derived
  metric.

## Motivation

Being *harvested* is table-stakes (see `dcat-national-portal-federation`); being
*ranked well* is the differentiator. Intelligence (Specter) shows the bar:

- **data.overheid.nl** ships a first-class **"Quality Dashboard"** feature — the
  national portal scores every source's metadata and surfaces it publicly.
- **Magda** ships **"Data Quality Scoring"** as a headline capability.
- DCAT-AP's **MQA** and the EU **data.europa.eu MQA** score every harvested
  dataset on findability/accessibility/interoperability/reusability/
  contextuality; a low-scoring source is deprioritised in the national and EU
  catalogs.

OpenCatalogi has no quality score today (verified: no `quality`/`MQA`/`FAIR`/
`dqv:` anywhere in `lib/`). Its only quality signal is the DCAT-010 validator,
which is a **pass/fail presence check** of three mandatory properties
(`dct:title`/`dct:publisher`/`dcat:landingPage`), advisory and admin-only — not
a graded score, no dimensions, no publisher dashboard. Publishers therefore have
no way to see *why* their datasets rank poorly in data.overheid.nl or how to
improve them. A derived MQA score + dashboard closes that loop using metadata the
DCAT layer already computes.

## Goals

1. Compute a per-publication MQA/FAIR score by inspecting the already-rendered
   `dcat:Dataset` (reuse `DcatMappingService`/`DcatService`; no second mapping),
   broken down by the five MQA dimensions with a total 0–100 score.
2. Optionally surface the score in the DCAT feed via W3C DQV so national/EU MQA
   can consume it without recomputation.
3. Give publishers a per-catalog quality dashboard: average score, distribution,
   worst-N datasets, and the specific missing/invalid properties dragging the
   score down — reusing the `publication-usage-analytics` stats/widget surface.

## Non-Goals

- **No content/data quality** (validating the *contents* of a CSV/PDF). This is
  *metadata* quality (MQA/FAIR of the catalog record), matching data.overheid.nl
  and the EU MQA scope.
- **No new authoritative field or visibility rule.** The score is derived; it
  never becomes a publish gate and never changes the OR
  `publicatiedatum <= now` visibility.
- **No new storage engine.** Any persisted roll-up is a derived metric object on
  OR (the same pattern `publication-usage-analytics` uses for counters), not a
  bespoke table.
- **DONL/EU registration and HVD/theme binding** live in
  `dcat-national-portal-federation`; this change only *scores* the metadata.

## High-Level Approach

A `QualityService` consumes the DCAT dataset node for a publication and scores
each MQA dimension from the presence/validity of the relevant DCAT properties
(e.g. accessibility ← resolvable `dcat:downloadURL`/`dcat:accessURL`;
interoperability ← non-proprietary `dct:format` + `dcat:mediaType`;
reusability ← `dct:license` + `dct:publisher`; findability ← `dcat:keyword` +
`dcat:theme` bound to a taxonomy; contextuality ← `dct:issued`/`dct:modified` +
rights). Roll-ups aggregate over the OR object-search path exactly like
`UsageCounterService.aggregateCatalog`. DQV output extends the DCAT serializer.
