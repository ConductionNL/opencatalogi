# Tasks: woo-review-surface

Read `openspec/woo-build-rules.md` first. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`. Real classes checked in openregister `development` at 1dc6a46: `TransitionEngine::transition(string $objectId, string $action, array $data = []): ObjectEntity`, `OCA\OpenRegister\Event\TaskTerminalEvent::getTask(): Task` (read the outcome and comment from the real `Task` entity; its getters are magic), `EntityRelationMapper::findAnonymisedEntitiesWithBasesForFile(int $fileId): array` (rows with `relation_id`, `entity_type`, `entity_value`, `position_start`, `position_end`, `bases`, `anonymized`). Check whether `FileService::anonymizeDocument()` marks relations `anonymized`; if it does not, stop and raise it on the OpenRegister lane instead of reading the detection set.

## 1. Lifecycle and approval

- [ ] 1.1 Declare the `wooBatch` lifecycle, the `batchReview` approval chain and the reject notification, add `rejections` and `assignee`, bump versions; a repair step maps stored `status` values (REQ-WRV-002). Verify: `tests/Unit/Settings/WooBatchLifecycleTest.php::testTheBatchDeclaresItsLifecycleAndApprovalChain` and `tests/Unit/Repair/WooBatchStatusRepairTest.php` with its registration check.
- [ ] 1.2 `markReadyForReview()` runs `submit`; `publishBatch()` refuses a batch not `approved` (REQ-WRV-002). Verify: `tests/Unit/Service/WooServiceTest.php::testAnUnapprovedBatchIsNotPublished` (fails today) and `::testMarkReadyForReviewRunsSubmit`.
- [ ] 1.3 Add the `TaskTerminalEvent` listener registered in `Application::register()` (REQ-WRV-002). Verify: `tests/Unit/Listener/BatchReviewTaskListenerTest.php::testARejectedReviewTaskRejectsTheBatchWithItsComment` and `::testAnApprovedTaskApprovesTheBatch`, on the REAL event; an `ApplicationRegisterInvariantTest` case.
- [ ] 1.4 Add the send-back route for one assessment (REQ-WRV-002). Verify: `tests/Unit/Controller/WooControllerTest.php::testSendBackResetsTheAssessmentWithTheReason` and `::testSendBackOutsideReviewIsRefused`.

## 2. Public view

- [ ] 2.1 Add `GET /api/woo/batches/{id}/public-preview` and `POST /api/woo/batches/{id}/preview-seen`, and the seen-everything check on `approve` (REQ-WRV-001). Verify: `tests/Unit/Service/WooBatchReviewTest.php::testThePreviewCarriesThePublishedFileAndThePublicFields`, `::testApproveIsRefusedUntilEveryPreviewWasSeen` (fails today), `::testANietOpenbaarDocumentHasNoViewer`.
- [ ] 2.2 Replace the assessment form with the public view on `WooBatchDetail` while the batch is in review, with Approve and Reject (Reject asks the reason in its own dialog under `src/dialogs/`) (REQ-WRV-001, REQ-WRV-002). Verify: `tests/e2e/woo-review-surface.spec.ts` "the reviewer sees each document as the public will" and "a rejected batch goes back to its author with the reason", carrying `@e2e` references.

## 3. Redaction log

- [ ] 3.1 Add `redactionLog` to `#wooAssessment`, fill it in `DocumentRedactor` after verification, and block in `assertPublishable()` (REQ-WRV-003). Verify: `tests/Unit/Service/Woo/DocumentRedactorTest.php::testTheRedactionLogListsEachPassageWithItsGrounds` (fails today), `::testAPassageWithoutAGroundBlocksThePublish`, `::testAnEmptyLogForARedactedDocumentBlocksThePublish`, `::testAnUnreadableLogBlocksThePublish`.
- [ ] 3.2 Assert no public route returns `redactionLog` (REQ-WRV-003). Verify: `tests/Unit/Service/PublicationQueryServiceTest.php::testNoPublicResponseCarriesARedactionLog`.
- [ ] 3.3 Show the log on the review and assessment pages (REQ-WRV-003). Verify: `tests/e2e/woo-review-surface.spec.ts` "the reviewer reads the log".
- [ ] 3.4 Live: on the dev instance redact a document with two grounded passages, read the assessment back, and paste its `redactionLog` (values masked) in the PR body. Verify: the pasted log.

## 4. Docs

- [ ] 4.1 Document the review flow and the redaction log for officers in `docs/` and the strings in `l10n/` (en, nl). Verify: `npm run check:l10n` and a grep for U+2014 on the changed docs.

## 5. Verification

- [ ] 5.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`. The publish path is central: run the full unit suite once.
- [ ] 5.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran; gate-18 checks the notification rule, the modal-isolation gate the reject dialog.
- [ ] 5.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 5.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Rows 4.2, 4.7 and 4.12 become `production` only once a store release ships it.
