---
status: proposed
---

# Publication withdrawal aftercare

## ADDED Requirements

### Requirement: A corrected publication keeps its earlier public versions reachable (REQ-PWA-001)

A schema `publicationVersion` SHALL be added in `lib/Settings/register.d/publication-withdrawal-aftercare.json` with `publication` (uuid), `version` (integer, from 1), `supersededAt` (date-time), `fields` (object) and `documents` (array of `{title, format, fileId}`), and no public read rule. A listener on OpenRegister's `ObjectUpdatingEvent` for the publication schema SHALL, when the old object is publicly readable (`PublicationStateService::stateOf()` answers `public`) and a field the public read returns, or the set of public files, changes, call `PublicationVersionService::snapshot(array $old)`. The snapshot SHALL store the old public projection in `fields`, copy each public file onto the `publicationVersion` object with OpenRegister's `FileService::copyFile(ObjectEntity $source, int $fileId, ObjectEntity $target)`, and set `supersededAt` to now. When the snapshot fails, the update SHALL be refused, so a correction never erases a public version unrecorded.

`GET /api/{catalogSlug}/{id}/versions` SHALL list `{version, supersededAt, url}` for the publication's snapshots, and `GET /api/{catalogSlug}/{id}/versions/{version}` SHALL answer the snapshot's `fields`, its documents with public download URLs, `superseded: true` and the URL of the current version. Both SHALL be public routes and SHALL answer 404 unless the publication itself is publicly readable at that moment, checked through the same public read path as `PublicationsController::show()`. The current public response SHALL carry `versions` with the list URL.

#### Scenario: The cited version is still there
<!-- @e2e exclude Public API contract; proven by PublicationVersionControllerTest::testACorrectionKeepsTheEarlierVersionAtItsOwnUrl, which fails on today's code because no version route exists. -->

- **GIVEN** a public publication titled "Besluit parkeren 2026" with one document
- **WHEN** an editor corrects the title to "Besluit parkeren binnenstad 2026" and replaces the document
- **THEN** `GET /api/{catalogSlug}/{id}/versions/1` answers the old title and the old document, with `superseded: true`
- **AND** the current publication answers the new title and lists version 1

#### Scenario: A version of a withdrawn publication is not served
<!-- @e2e exclude Fail-closed path; proven by PublicationVersionControllerTest::testAVersionOfAPublicationThatIsNoLongerPublicIs404. -->

- **GIVEN** a publication with version 1 that has since been withdrawn
- **WHEN** an anonymous reader asks for version 1
- **THEN** the answer is 404

#### Scenario: A snapshot never carries a hidden field
<!-- @e2e exclude Service rule; proven by PublicationVersionServiceTest::testTheSnapshotHoldsOnlyTheFieldsThePublicReadReturned. -->

- **GIVEN** a public publication with a property the public read does not return
- **WHEN** it is corrected
- **THEN** the snapshot's `fields` do not contain that property

#### Scenario: A failed snapshot refuses the correction
<!-- @e2e exclude Fail-closed path; proven by PublicationVersionListenerTest::testAFailedSnapshotRefusesTheUpdate, built on the real ObjectUpdatingEvent. -->

- **GIVEN** a public publication and a file copy that throws
- **WHEN** an editor saves a correction
- **THEN** the save is refused and the publication is unchanged

### Requirement: A withdrawn publication answers with a tombstone about the withdrawal (REQ-PWA-002)

The `depublication` schema SHALL gain `publicReason` (string) and `tombstoneShowsTitle` (boolean, default `false`). `WithdrawPublicationDialog.vue` SHALL ask for both beside the internal reason. When the public read of a publication by id finds nothing, `PublicationsController::show()` SHALL look, as the system, for a `depublication` whose `publication` is that id, or for a publication with that id in state `archived` with `firstReleasedAt` set. When one exists it SHALL answer 410 with `{id, withdrawnAt, publicReason, title?}`, the title only when `tombstoneShowsTitle` is `true`. It SHALL answer nothing else of the record. When neither exists it SHALL answer 404 as today. The publication remains absent from search, sitemaps, DCAT and federation (RET-001, RET-006).

#### Scenario: A withdrawn link answers with a tombstone
<!-- @e2e exclude Public API status code; proven by PublicationTombstoneTest::testAWithdrawnPublicationAnswers410WithItsTombstone, which fails on today's code because the answer is 404. -->

- **GIVEN** a publication withdrawn yesterday with public reason "Gepubliceerd in strijd met de AVG" and `tombstoneShowsTitle` false
- **WHEN** an anonymous reader requests it by id
- **THEN** the answer is 410 with its id, yesterday's date and that public reason
- **AND** the answer carries no title, summary or document

#### Scenario: A never-public draft stays a 404
<!-- @e2e exclude Fail-closed path; proven by PublicationTombstoneTest::testADraftThatWasNeverPublicStays404. -->

- **GIVEN** a draft that was never public
- **WHEN** an anonymous reader requests it by id
- **THEN** the answer is 404

#### Scenario: The officer chooses what the tombstone says

- **GIVEN** a public publication
- **WHEN** an editor withdraws it with an internal reason, a public reason and Show the title ticked
- **THEN** the depublication stores both reasons and the choice
- **AND** the public link answers 410 with the title and the public reason

### Requirement: A withdrawn publication is frozen with the withdrawal reason (REQ-PWA-003)

The publication schema SHALL declare `x-openregister-archive` with `enabled: true`. `DepublicationService::depublish()` SHALL, after storing the depublication, freeze the publication through OpenRegister's `ArchiveHandler::freeze(identifier: <uuid>, reason: <the internal reason>)`. When the freeze throws, the withdrawal SHALL stand and the answer SHALL carry `frozen: false` with the error; the page SHALL show the publication as not frozen. Every document write inside OpenCatalogi (`EventService::publishObjectAttachments()`, `PublicationStateController::withdrawFile()`, the attachment upload and mass attach paths) SHALL check the publication's frozen marker first and refuse with the message of `ObjectStateWriteException::frozen()`. The publication page SHALL show the marker (who, when, why) and SHALL offer no edit, upload or file action while frozen.

#### Scenario: An edit to a withdrawn publication is refused with the reason
<!-- @e2e exclude Refusal by OpenRegister's save path on a frozen object; proven by DepublicationServiceTest::testWithdrawingFreezesThePublicationWithTheReason and a controller test saving the frozen object, which fail on today's code because nothing freezes. -->

- **GIVEN** a publication an editor withdrew with the reason "Verkeerde bijlage"
- **WHEN** an editor saves a new title for it
- **THEN** the save is refused with a message naming the editor who froze it and the date
- **AND** the stored title is unchanged

#### Scenario: A document write is refused
<!-- @e2e exclude Refusal on file write paths; proven by FrozenPublicationDocumentWriteTest::testAnUploadToAFrozenPublicationIsRefused in opencatalogi, and on OpenRegister's file endpoint by the object-archive-state amendment's own test. -->

- **GIVEN** a withdrawn, frozen publication
- **WHEN** an editor uploads a file to it through OpenCatalogi or through OpenRegister's file endpoint
- **THEN** the upload is refused with the frozen message and no file is added

#### Scenario: The page says why

- **GIVEN** a withdrawn, frozen publication
- **WHEN** an editor opens it
- **THEN** the page shows who froze it, when and why, and offers no edit or upload

#### Scenario: A failed freeze is not hidden
<!-- @e2e exclude Failure path; proven by DepublicationServiceTest::testAFailedFreezeKeepsTheWithdrawalAndSaysSo. -->

- **GIVEN** a freeze that throws
- **WHEN** an editor withdraws a publication
- **THEN** the publication is withdrawn and the answer carries `frozen: false` with the error
