# Tasks: woo-obligation-overview

## 1. Read path

- [ ] 1.1 Add `ObligationsRequestedEvent` and `ObligationReadService` (REQ-WOO-001). Verify: `tests/Unit/Service/Publication/ObligationReadServiceTest.php` with one answering listener, one throwing listener and one source without a listener, all on the real event class.
- [ ] 1.2 Register the harvest intake as source `harvest` (REQ-WOO-002). Verify: unit test on the real `obligationSource` fragment with an actual payload.
- [ ] 1.3 Add `GET /api/obligations` and correct the controller docblock (REQ-WOO-001). Verify: `tests/Unit/Controller/PublicationRulesControllerTest.php`, admin and non-admin.

## 2. Page

- [ ] 2.1 Add the Obligations page and its manifest entry (REQ-WOO-003). Verify: `tests/e2e/woo-obligations.spec.ts` with two sources, one unread.

## 3. Docs and strings

- [ ] 3.1 English and Dutch strings, docs, `openspec validate woo-obligation-overview --strict`.
