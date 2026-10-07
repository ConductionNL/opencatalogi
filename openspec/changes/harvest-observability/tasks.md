# Tasks: harvest-observability

Build after `harvest-feed-intake` (the DCAT fetcher, `HarvestFeedService`, the feed cards) and after `openregister/app-harvest-fetchers-and-flow-node` has merged its code. Read `design.md`; D1 explains the `complete: false` rule.

- [x] 0.1 Author the delta spec (spec round part 3, 2026-10-07, on OpenRegister's sources, sync records and flow runs).
- [ ] 1.1 `lib/Harvest/ShaclValidator.php` with the bundled shape `lib/Harvest/shapes/dcat-ap-nl-2.1.ttl` and the optional shape URL through `HarvestHttpClient`; pick, pin and audit the SHACL library (REQ-HOB-001). Verify: `tests/Unit/Harvest/ShaclValidatorTest.php`; `composer audit`.
- [ ] 1.2 Call it from the DCAT fetchers; add `shapeUrl` and `validate` to their config schema; violators to `errors`, `complete: false` when any (REQ-HOB-001). Verify: `tests/Unit/Harvest/DcatJsonLdFetcherTest.php::testAViolatingDatasetIsAnErrorAndTheRunIsIncomplete`, `::testValidateFalseSkipsTheShape`.
- [ ] 2.1 `GET /api/harvest/feeds/{id}/status` (admin) in `HarvestFeedController`, through `HarvestFeedService` (design D2). Verify: `tests/Unit/Controller/HarvestFeedControllerTest.php::testStatusIsAdminOnly`; hydra route-auth gate.
- [ ] 2.2 Page `HarvestFeed` at `/settings/harvest/:sourceId` with `src/views/settings/HarvestFeedDetail.vue`: header actions, last and next run, record counts, error trend with the numbers as text, paged runs, a run's errors (REQ-HOB-002). Verify: vitest `HarvestFeedDetailTest` for Run now; e2e `tests/e2e/harvest-observability.spec.ts` runs a fixture feed with one invalid dataset and reads the reason on the run, carrying `@e2e` for "An administrator reads why a run was partial".
- [ ] 3.1 Write the 30-day run retention override on the source's flow in `HarvestFeedService` (REQ-HOB-003). Verify: `tests/Unit/Service/Harvest/HarvestFeedServiceTest.php::testTheFlowKeepsRunsThirtyDays`.
- [ ] 4.1 nl and en strings; diff check while building; once before push `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, `format`. One PR `--base development`.
