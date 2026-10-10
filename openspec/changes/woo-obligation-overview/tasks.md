# Tasks: woo-obligation-overview

## 1. Read path

- [x] 1.1 Add `ObligationsRequestedEvent` and `ObligationReadService` (REQ-WOO-001). `lib/Event/ObligationsRequestedEvent.php`, `lib/Service/Publication/ObligationReadService.php`; red then green in `build-round/red-obligations.log` / `green-obligations.log`. Verify: `tests/Unit/Service/Publication/ObligationReadServiceTest.php` with one answering listener, one throwing listener and one source without a listener, all on the real event class.
- [ ] 1.2 Register the harvest intake as source `harvest` and add `lib/Listener/HarvestObligationsListener.php` reading OpenRegister sync records of OpenCatalogi sources (design, "The harvest source"); add `publishWithinDays` to the fetcher config schema and the feed modal (REQ-WOO-002). Build after `harvest-feed-intake` and `openregister/app-harvest-fetchers-and-flow-node` have merged. Verify: unit test on the real `obligationSource` fragment with an actual payload; `tests/Unit/Listener/HarvestObligationsListenerTest.php::testAnUnpublishedRecordPastItsTermIsLate`, `::testATombstonedRecordIsNoObligation`, `::testWithoutATermTheDueDateIsUnknown`.
- [x] 1.3 Add `GET /api/obligations` and correct the controller docblock (REQ-WOO-001). `PublicationRulesController::obligations()` (`#[AuthorizedAdminSetting]`), route in `appinfo/routes.php`; tests `testAnAdminReadsTheObligationOverview`, `testANonAdminIsRefusedTheOverview`, `testUnconfiguredSourcesAnswer503RatherThanAnEmptyOverview`. Verify: `tests/Unit/Controller/PublicationRulesControllerTest.php`, admin and non-admin.

## 2. Page

- [ ] 2.1 Add the Obligations page and its manifest entry (REQ-WOO-003). Built: page `Obligations` at `/obligations` (`src/views/obligations/ObligationsIndex.vue`, model `src/services/obligationOverview.js`, menu item under Administration), laid out after board OcWooVerplichtingen; vitest `tests/vitest/obligationOverview.spec.js` red then green. (not run: the e2e needs the live instance) Verify: `tests/e2e/woo-obligations.spec.ts` with two sources, one unread.

## 3. Docs and strings

- [x] 3.1 English and Dutch strings, docs, `openspec validate woo-obligation-overview --strict`. `l10n/en.*`, `l10n/nl.*`; `docs/features/active-publication-and-inspection.md` (The Woo obligations page).
