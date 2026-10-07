---
status: proposed
---

# Woo transparency

## ADDED Requirements

### Requirement: The reviewer sees each document as the public will (REQ-WRV-001)

`GET /api/woo/batches/{id}/public-preview` SHALL answer, per document of the batch, `{documentId, assessment, viewerUrl, publicMetadata}`, where `viewerUrl` opens the file that will be published (the verified redacted file for `deels_openbaar`, the original for `openbaar`, nothing for `niet_openbaar`) and `publicMetadata` is the publication payload `BatchPublicationWriter::payload()` will write, reduced to the fields the public read of a publication returns. The batch review page SHALL show this per document in place of the assessment form while the batch is `ready_for_review`. `POST /api/woo/batches/{id}/preview-seen` with `{documentId}` SHALL record per reviewer which previews were opened. Approve SHALL be enabled, and `approve` SHALL be accepted, only when the approving user has opened the preview of every `openbaar` and `deels_openbaar` document.

#### Scenario: The reviewer sees each document as the public will

- **GIVEN** a batch ready for review with one `openbaar` and one `deels_openbaar` document
- **WHEN** the reviewer opens the batch
- **THEN** each document shows its public view: the file that will be published and its public metadata
- **AND** Approve is enabled only after both public views were opened

#### Scenario: Approving without having seen a document
<!-- @e2e exclude Server-side guard; proven by WooBatchReviewTest::testApproveIsRefusedUntilEveryPreviewWasSeen, which fails on today's code because no approve step exists. -->

- **GIVEN** a reviewer who opened one of two previews
- **WHEN** the reviewer calls `approve`
- **THEN** it is refused naming the unseen document

### Requirement: A rejected batch or document goes back to its author with the reason (REQ-WRV-002)

The `wooBatch` schema SHALL declare `x-openregister-lifecycle` on `status` with the states `in_progress`, `ready_for_review`, `approved`, `published` and the transitions `submit` (in_progress to ready_for_review), `approve` (ready_for_review to approved), `reject` (ready_for_review to in_progress, required input `reason`) and `publish` (approved to published), and `x-openregister-approval-chains: {batchReview: {transition: "approve", approvers: [{role: "woo-reviewer", min: 1}]}}`. `WooService::markReadyForReview()` SHALL run `submit`. `WooService::publishBatch()` SHALL refuse a batch that is not `approved`. A listener on OpenRegister's `TaskTerminalEvent` for a `batchReview` task that ended rejected SHALL run `reject` with the task's comment as `reason`. `reject` SHALL store `{reason, by, at}` in the batch's `rejections`, set `assignee` to the batch's `createdBy`, and the `wooBatch` schema SHALL declare an `x-openregister-notifications` rule on the `reject` transition to the assignee naming the batch and the reason. `POST /api/woo/assessments/{id}/send-back` with `{reason}` SHALL set the assessment back to `te_beoordelen` and store the reason as `sentBackReason` with who and when, and SHALL be allowed only while its batch is `ready_for_review`.

#### Scenario: A rejected batch goes back to its author with the reason

- **GIVEN** a batch created by Anna and ready for review
- **WHEN** the reviewer rejects it with the reason "Bijlage 3 bevat nog een BSN"
- **THEN** the batch is `in_progress` and assigned to Anna
- **AND** Anna has a notification naming the batch and the reason
- **AND** the batch page shows the reason with the reviewer and the date

#### Scenario: An unapproved batch is not published
<!-- @e2e exclude Fail-closed path; proven by WooServiceTest::testAnUnapprovedBatchIsNotPublished, which fails on today's code because publishBatch accepts ready_for_review. -->

- **GIVEN** a batch in `ready_for_review`
- **WHEN** `publishBatch()` is called
- **THEN** it is refused and no publication is created

#### Scenario: A rejection in OpenRegister's task list reaches the batch
<!-- @e2e exclude Cross-component wiring; proven by BatchReviewTaskListenerTest::testARejectedReviewTaskRejectsTheBatchWithItsComment, built on the real TaskTerminalEvent. -->

- **GIVEN** a `batchReview` task for a batch
- **WHEN** the reviewer rejects the task in OpenRegister with a comment
- **THEN** the batch runs `reject` with that comment as the reason

### Requirement: The redaction log records what was removed and on which ground (REQ-WRV-003)

`wooAssessment` SHALL gain `redactionLog`: a list of `{relationId, entityType, positionStart, positionEnd, value, grounds}`. After `DocumentRedactor` produced a verified redacted file for a `deels_openbaar` document, it SHALL fill `redactionLog` from OpenRegister's `EntityRelationMapper::findAnonymisedEntitiesWithBasesForFile(<original file id>)`, with `grounds` from each row's `bases`. `DocumentRedactor::assertPublishable()` SHALL block the publish, naming the document, when a `deels_openbaar` document's `redactionLog` is empty, unreadable, or has an entry with no ground. The batch review page and the assessment page SHALL show the log per document. The log SHALL NOT appear in any public response.

#### Scenario: The log says what was removed and why
<!-- @e2e exclude Server-side record; proven by DocumentRedactorTest::testTheRedactionLogListsEachPassageWithItsGrounds, which fails on today's code because no log is stored. -->

- **GIVEN** a `deels_openbaar` document in which a person's name and a phone number were redacted, with grounds art. 5.1 lid 2 sub e on both
- **WHEN** the redaction is verified
- **THEN** its `redactionLog` holds two entries with their types, positions and that ground

#### Scenario: A passage without a ground blocks publishing
<!-- @e2e exclude Fail-closed path; proven by DocumentRedactorTest::testAPassageWithoutAGroundBlocksThePublish and DocumentRedactorTest::testAnEmptyLogForARedactedDocumentBlocksThePublish. -->

- **GIVEN** a `deels_openbaar` document whose log has one entry without a ground
- **WHEN** the batch is published
- **THEN** the publish is refused naming the document

#### Scenario: The reviewer reads the log

- **GIVEN** a batch ready for review with a `deels_openbaar` document
- **WHEN** the reviewer opens that document
- **THEN** the page lists each redacted passage with its type and its ground
