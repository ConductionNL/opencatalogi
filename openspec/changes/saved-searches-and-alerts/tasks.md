# Tasks: saved-searches-and-alerts

## 1. Schema and actions

- [x] 1.1 Add `savedSearch` in `lib/Settings/register.d/saved-searches-and-alerts.json`, bump the register version (REQ-SSA-001). Verify: `tests/Unit/Settings/WooJourneyRegisterTest.php`.
- [x] 1.2 Manifest: `mySavedSearches`, the four actions, the page and the change rule (REQ-SSA-001, REQ-SSA-004). Verify: `tests/Unit/Portal/PortalContributionProviderTest.php`.
- [x] 1.3 `SavedSearchService` + `PortalCollectionController`: save, pause, delete (REQ-SSA-001). Verify: `tests/Unit/Service/Portal/SavedSearchServiceTest.php`, `tests/Unit/Controller/PortalCollectionControllerTest.php`.

## 2. Matching

- [x] 2.1 `SearchQueryTranslator` (REQ-SSA-002). Verify: `tests/Unit/Service/SearchQueryTranslatorTest.php`.
- [x] 2.2 `SavedSearchMatcher` + `SavedSearchMatchingJob`: due rules, window, notices, `lastRunAt` after hand-over (REQ-SSA-002..004). Verify: `tests/Unit/Service/Portal/SavedSearchMatcherTest.php`, `tests/Unit/BackgroundJob/SavedSearchMatchingJobTest.php`.

## 3. Docs

- [x] 3.1 Dutch and English strings, `openspec validate saved-searches-and-alerts --strict`.

## 4. The match message (found while filming J6, 2 October)

- [x] 4.1 `SavedSearchNoticeWriter` writes one Dutch `portalMessage` per notice with the rule key and a link to the search; the manifest declares the key without a change rule (REQ-SSA-004). Verify: `tests/Unit/Service/Portal/SavedSearchMatcherTest.php`, `tests/Unit/Service/Portal/PortalObjectStoreMessageTest.php`, `tests/Unit/Portal/PortalContributionProviderTest.php`.
