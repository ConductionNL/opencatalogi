# Design: harvest-observability

## D1. Validation sits in the fetcher

OpenRegister's pipeline maps and saves what a fetcher returns; it has no validation hook for app items. The DCAT fetchers (`DcatJsonLdFetcher` from `harvest-feed-intake`, `DcatRdfFetcher` from `harvest-protocol-plugins`) validate each dataset node before they add it to `items`. `lib/Harvest/ShaclValidator.php` wraps the SHACL library chosen at pickup (pinned and audited in the PR) with the bundled shape `lib/Harvest/shapes/dcat-ap-nl-2.1.ttl` and, when the source's `config.shapeUrl` is set, that shape fetched through the `HarvestHttpClient`. A dataset with a `sh:Violation` goes into the batch's `errors` as `{externalId: <@id>, message: "<path>: <message>"}`; warnings do not block.

**Tombstoning.** OpenRegister tombstones every record not seen in a complete run (REQ-HAF-010) and counts only `items` as seen. A dataset that was imported yesterday and fails validation today would be tombstoned. Until OpenRegister counts an external id in `errors` as seen, the fetcher returns `complete: false` whenever it rejected a dataset that it read, so nothing is tombstoned in that run. The run reads `partial`, which is true. This is raised with OpenRegister (see the spec round part 3 hand-back).

`config.shapeUrl` (uri, optional) and `config.validate` (boolean, default true) are added to the fetcher's config schema.

## D2. The feed page

Route `/settings/harvest/:sourceId` (admin), page `HarvestFeed` in `src/manifest.json`, component `src/views/settings/HarvestFeedDetail.vue`, opened from a feed card's name on the settings page (board `OcInstellingen`, Harvest feeds section of `harvest-feed-intake`). No board draws this page; it follows the card's vocabulary.

- Header: feed name, type, source URL, Run now, Switch off, "Open the source in OpenRegister".
- "Last run" and "Next run": `lastSyncDate`, `lastSyncStatus`, and the next firing from the flow's cron.
- "Records": counts per `SyncRecord.status` and tombstoned, from `GET /apps/openregister/api/sources/{id}/sync-records` with a status filter. The conflict count links to the review queue of `harvest-conflict-policies`.
- "Errors over the last 20 runs": a small bar chart of the summary's `errors` count per run, with the numbers as text beside it.
- "Runs": a paged table of the flow's runs (`GET /apps/openregister/api/flow-runs?flow=<flowId>`): started, duration, status, created, updated, unchanged, conflict, rejected, tombstoned, errors. A row opens the run's error list (`{externalId, message}`, at most 100, as the summary carries).

OpenCatalogi reads these through OpenRegister's APIs in the browser; `lib/Service/Harvest/HarvestFeedService.php` adds one admin read `GET /api/harvest/feeds/{id}/status` that combines source, flow and latest summary, because the browser cannot read the flow's next firing time.

## D3. Retention

`HarvestFeedService` writes the source's flow with a retention override of 30 days when it saves a source, through OpenRegister's flow service. No job here.

## D4. Tests

`ShaclValidatorTest` (a valid and an invalid dataset against the bundled shape; a shape URL refused by the outbound guard is an error, not a crash), `DcatJsonLdFetcherTest::testAViolatingDatasetIsAnErrorAndTheRunIsIncomplete`, `HarvestFeedServiceTest::testTheFlowKeepsRunsThirtyDays`, `HarvestFeedControllerTest::testStatusIsAdminOnly`. e2e on the feed page with a fixture feed holding one invalid dataset.
