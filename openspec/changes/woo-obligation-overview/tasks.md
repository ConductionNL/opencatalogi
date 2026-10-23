# Tasks: woo-obligation-overview

## 1. Read path

- [x] 1.1 Add `ObligationsRequestedEvent` and `ObligationReadService` (REQ-WOO-001). Verify: `tests/Unit/Service/Publication/ObligationReadServiceTest.php` with one answering listener, one throwing listener and one source without a listener, all on the real event class.
- [ ] 1.2 Register the harvest intake as source `harvest` and add `lib/Listener/HarvestObligationsListener.php` reading OpenRegister sync records of OpenCatalogi sources (design, "The harvest source"); add `publishWithinDays` to the fetcher config schema and the feed modal (REQ-WOO-002). Build after `harvest-feed-intake` and `openregister/app-harvest-fetchers-and-flow-node` have merged. Verify: unit test on the real `obligationSource` fragment with an actual payload; `tests/Unit/Listener/HarvestObligationsListenerTest.php::testAnUnpublishedRecordPastItsTermIsLate`, `::testATombstonedRecordIsNoObligation`, `::testWithoutATermTheDueDateIsUnknown`.
- [x] 1.3 Add `GET /api/obligations` and correct the controller docblock (REQ-WOO-001). Verify: `tests/Unit/Controller/ObligationControllerTest.php`, admin and non-admin. Built as its own `ObligationController` rather than growing PublicationRulesController.

## 2. Page

- [x] 2.1 Add the Obligations page and its manifest entry (REQ-WOO-003). Built as manifest configuration (`src/manifest.d/woo-obligation-overview.json`, a dashboard page over the endpoint) rather than a custom view. Verify: Ajv against the app-manifest-v2 schema.

## 3. Docs and strings

- [x] 3.1 English and Dutch strings, docs, `openspec validate woo-obligation-overview --strict`.
