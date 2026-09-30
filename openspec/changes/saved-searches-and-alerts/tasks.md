# Tasks: saved-searches-and-alerts

## 1. Schema and actions

- [x] 1.1 Add `savedSearch` in `lib/Settings/register.d/saved-searches-and-alerts.json`, bump the register version (REQ-SSA-001). Verify: `tests/Unit/Settings/WooJourneyRegisterTest.php`.
- [x] 1.2 Manifest: `mySavedSearches`, the four actions, the page and the change rule (REQ-SSA-001, REQ-SSA-004). Verify: `tests/Unit/Portal/PortalContributionProviderTest.php`.
- [x] 1.3 `SavedSearchService` + `PortalCollectionController`: save, pause, delete (REQ-SSA-001). Verify: `tests/Unit/Service/Portal/SavedSearchServiceTest.php`, `tests/Unit/Controller/PortalCollectionControllerTest.php`.

## 2. Matching

- [ ] 2.1 `SearchQueryTranslator` (REQ-SSA-002). Verify: `tests/Unit/Service/SearchQueryTranslatorTest.php`.
- [ ] 2.2 `SavedSearchMatcher` + `SavedSearchMatchingJob`: due rules, window, notices, `lastRunAt` after hand-over (REQ-SSA-002..004). Verify: `tests/Unit/Service/SavedSearchMatcherTest.php`.

## 3. Docs

- [ ] 3.1 Dutch and English strings, `openspec validate saved-searches-and-alerts --strict`.
