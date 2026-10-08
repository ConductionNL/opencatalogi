---
kind: mixed
depends_on: [harvest-feed-intake, openregister/app-harvest-fetchers-and-flow-node]
---

# Proposal: harvest-observability

Fifth slice of the re-scoped `dcat-oai-pmh-harvesting` umbrella. Revised in spec round part 3 (7 October 2026): harvesting runs on OpenRegister (`openregister/app-harvest-fetchers-and-flow-node`, decision 80), so the old feed, item and run schemas this slice read are gone. A harvest feed is an OpenRegister `Source` with `application: opencatalogi`, an item is a `SyncRecord`, a run is a run of the source's flow whose output is the run summary. This slice now reads those and builds no store of its own.

## Summary

Make harvesting inspectable and validated:

- **SHACL validation.** The DCAT fetchers validate each dataset against the bundled DCAT-AP-NL shape, plus a shape URL the feed may name. A failing dataset is not handed to OpenRegister as an item; it is reported in the batch's `errors` with the violation, so it shows in the run summary and nothing is written for it.
- **Feed page.** One page per feed: last and next run, the counts of its sync records per status (imported, unchanged, conflict, shadowed, rejected, tombstoned), the error trend over its recent runs, and Run now, which starts the source's flow. There is no second way to run a feed.
- **Run history.** The feed page lists the flow's runs with their summaries, paged, and opens one run's errors. Run history is OpenRegister's flow run log; its retention is OpenRegister's `FlowRunRetentionJob`, which this slice sets to 30 days for harvest flows.

## What OpenRegister does (not built here)

| Was in this slice | Now |
|---|---|
| item states `new`, `updated`, `rejected` on `harvested-item` | `SyncRecord.status` (`imported`, `unchanged`, `conflict`, `shadowed`, `rejected`) and `tombstoned` |
| run rows and run logs on `harvest-run` | flow runs (`GET /api/flow-runs?flow=<source uuid>`), the summary as the output of node `openregister.harvest-source` |
| a 30-day retention pass in this app | `FlowRunRetentionJob` (`flow_run_retention_days`, per-flow override) |
| Harvest Now | `POST /api/sources/{id}/sync` |

## Rows

- No row of its own. This slice serves `od-harvest` (the operator can see what a harvest did) and `od-harvest-conflict` (the conflict count links to the review queue of `harvest-conflict-policies`). Neither row's state changes for it.

## Non-goals

- New protocols or policies; log export formats beyond JSON.
- A run store, log table or retention job in OpenCatalogi.

## Capabilities

### New capabilities

- `harvest-observability`: SHACL validation in the DCAT fetchers, a page per feed, and the run history read from OpenRegister.
