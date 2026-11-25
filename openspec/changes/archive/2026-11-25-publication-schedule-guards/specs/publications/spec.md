---
status: proposed
---

# Publication schedule guards

## ADDED Requirements

### Requirement: An embargo refuses every write that would release the record early (REQ-PSG-001)

A publication SHALL be under embargo while its stored `publicationDate` lies after now. A guard, `OCA\OpenCatalogi\Service\Publication\EmbargoGuard`, SHALL refuse, for a caller without the `liftEmbargo` right: the `publish` and `publishWithoutReview` transitions (REQ-PLC-001) and `POST /api/publications/{id}/publish` on a publication under embargo; and any save, through OpenCatalogi or OpenRegister's object API, that sets `publicationDate` earlier than the stored value. The refusal SHALL answer 423 with the code `embargo-holds` and the embargo moment. Moving `publicationDate` later and clearing it SHALL be allowed. The guard SHALL run in a listener on OpenRegister's `ObjectUpdatingEvent` for the publication schema, so every write path meets it, and the controller SHALL check it first to answer with a clear message. When the guard cannot read the stored date or resolve the caller's groups it SHALL refuse.

#### Scenario: Publish now is refused under embargo
<!-- @e2e exclude API refusal; proven by PublicationStateControllerTest::testPublishNowIsRefusedUnderEmbargo, which fails on today's code because publish() refuses only public and archived publications. -->

- **GIVEN** an approved publication with `publicationDate` tomorrow 09:00 and an editor outside `publication_embargo_lift_groups`
- **WHEN** the editor calls `POST /api/publications/{id}/publish`
- **THEN** the answer is 423 with code `embargo-holds` and tomorrow 09:00
- **AND** anonymous readers still get 404 for the publication

#### Scenario: An earlier date is refused under embargo
<!-- @e2e exclude Pre-save refusal on OpenRegister's object API; proven by EmbargoGuardListenerTest::testAnEarlierDateThroughTheObjectApiIsRefused, built on the real ObjectUpdatingEvent. -->

- **GIVEN** a publication with `publicationDate` tomorrow 09:00
- **WHEN** an editor without the right saves it through `PUT /apps/openregister/api/objects/{register}/{schema}/{id}` with `publicationDate` today 08:00
- **THEN** the save is refused with `embargo-holds`
- **AND** the stored `publicationDate` is still tomorrow 09:00

#### Scenario: A later date is allowed
<!-- @e2e exclude Pre-save path; proven by EmbargoGuardListenerTest::testALaterDateIsAllowed. -->

- **GIVEN** a publication with `publicationDate` tomorrow 09:00
- **WHEN** an editor saves it with `publicationDate` next week
- **THEN** the save is stored

#### Scenario: The guard cannot read the caller's groups
<!-- @e2e exclude Fail-closed path; proven by EmbargoGuardTest::testAnUnresolvableCallerIsRefused. -->

- **GIVEN** a publication under embargo and a group lookup that throws
- **WHEN** a publish is attempted
- **THEN** it is refused

### Requirement: Lifting an embargo takes a named right and a reason, and is logged (REQ-PSG-002)

`POST /api/publications/{id}/lift-embargo` with `{reason, publicationDate?}` SHALL be allowed only to a user in a group listed in the app config `publication_embargo_lift_groups` (default `["admin"]`, editable in the publications admin settings). It SHALL require a non-empty reason. It SHALL set `publicationDate` to the given moment, or now, run the publish transition as the lifting user, and write one audit entry through `AuditTrailMapper::createAuditTrail()` with action `publication.embargo.lifted`, the user, the reason, the old and the new date. The publication page SHALL offer Lift embargo only to a user with the right.

#### Scenario: A lift is logged

- **GIVEN** a publication under embargo until next week and a user in `publication_embargo_lift_groups`
- **WHEN** the user chooses Lift embargo, enters the reason "Raad heeft het besluit vervroegd" and confirms
- **THEN** the publication is public
- **AND** its audit trail shows `publication.embargo.lifted` with the user, the reason and both dates

#### Scenario: A lift without the right or without a reason
<!-- @e2e exclude API refusal; proven by EmbargoLiftControllerTest::testALiftByAUserOutsideTheGroupsIsRefused and EmbargoLiftControllerTest::testALiftWithoutAReasonIsRefused. -->

- **GIVEN** a publication under embargo
- **WHEN** an editor outside the groups, or a user in the groups without a reason, calls the lift route
- **THEN** the answer is 403 or 422 and nothing changes

### Requirement: Automatic depublication is shown and the officer is reminded (REQ-PSG-003)

When a publication has a `depublicationDate` in the future, `PublicationVisibilityWidget.vue` SHALL show "Automatic depublication on <date>" and the publications list SHALL show a marker with the date in the state column. A save that sets or changes `depublicationDate` through OpenCatalogi SHALL answer with a `warnings` entry `automatic-depublication` naming the date, which the page shows as a notice. The publication schema SHALL declare an `x-openregister-notifications` rule `depublication-due-soon` with `trigger: {type: "scheduled", intervalSec: 86400, filter: {"depublicationDate": {"operator": "withinNext", "value": "P7D"}}}`, channel `nc-notification`, recipients `object-acl` with permission `manage`, and Dutch and English subjects naming the title and the date. Each publication SHALL be notified once per depublication date.

#### Scenario: Automatic depublication is shown

- **GIVEN** a public publication with `depublicationDate` on 1 December
- **WHEN** an editor opens the publications list and the publication page
- **THEN** the list row carries the marker with 1 December
- **AND** the page shows "Automatic depublication on 1 December"

#### Scenario: The officer is warned at save

- **GIVEN** a public publication without a depublication date
- **WHEN** an editor sets `depublicationDate` to 1 December and saves
- **THEN** the page shows a notice that the publication will be taken down automatically on 1 December

#### Scenario: The officer is reminded before the date
<!-- @e2e exclude Scheduled notification, no deterministic browser surface; proven by DepublicationReminderDeclarationTest::testTheRuleParsesAndMatchesAPublicationDueInFiveDays, which runs OpenRegister's ScheduledFilterParser and ScheduledFilterEvaluator over the shipped rule, and by the live check in tasks 3.3. -->

- **GIVEN** a public publication with `depublicationDate` five days from now and an editor with manage rights
- **WHEN** OpenRegister's scheduled notification job runs twice
- **THEN** the editor has one notification naming the publication and the date

### Requirement: Publishing a record with no documents asks twice (REQ-PSG-004)

`PublishPublicationDialog.vue` SHALL show the number of documents the publication carries: its attached files plus the `documentReference` objects that point at it. With zero it SHALL show a warning that the publication has no documents and SHALL require the editor to tick a confirmation before Publish is enabled. `POST /api/publications/{id}/publish` and the publish transitions run through OpenCatalogi SHALL refuse with 409 `no-documents` when the count is zero and the call does not carry `confirmNoDocuments: true`. When the count cannot be read it SHALL be treated as zero.

#### Scenario: Publishing with no documents asks twice

- **GIVEN** an approved publication with no files and no document references
- **WHEN** the editor opens Publish
- **THEN** the dialog says the publication has no documents and Publish stays disabled until the editor ticks the confirmation
- **AND** after confirming, the publication is public

#### Scenario: A reference counts as a document

- **GIVEN** an approved publication with no files and one document reference
- **WHEN** the editor opens Publish
- **THEN** the dialog shows one document and no warning

#### Scenario: The API refuses without the confirmation
<!-- @e2e exclude API refusal; proven by PublicationStateControllerTest::testPublishWithNoDocumentsIsRefusedWithoutConfirmation and ::testAnUnreadableDocumentCountCountsAsZero. -->

- **GIVEN** an approved publication with no documents
- **WHEN** a caller posts to `/api/publications/{id}/publish` without `confirmNoDocuments`
- **THEN** the answer is 409 `no-documents` and the publication is not public

## MODIFIED Requirements

### Requirement: An editor publishes or withdraws a publication in one action (REQ-PPW-002)

The publication page SHALL offer Publish now for a draft or approved publication, and for a scheduled publication only to a user with the `liftEmbargo` right, through Lift embargo (REQ-PSG-002). It SHALL offer Withdraw for a public or scheduled one, each only when it applies. Publish now SHALL make the publication public at once, after the document check of REQ-PSG-004. Withdraw SHALL require a reason, take the publication down at once, record who did it and why, and send a withdrawal to every channel the publication reached. Only a user who may update the publication SHALL be able to do either.

#### Scenario: An editor withdraws a publication published by mistake

- **GIVEN** a public publication that reached the national Woo-index
- **WHEN** the editor chooses Withdraw, enters the reason "Wrong annex attached" and confirms
- **THEN** readers of the public API no longer get the publication
- **AND** a depublication with the editor's name, the reason and a withdrawal for the Woo-index channel is stored
- **AND** the message names any channel that did not confirm

#### Scenario: A reader without update rights

- **GIVEN** a user who can read but not update a public publication
- **WHEN** they call `POST /api/publications/{id}/withdraw`
- **THEN** the call is refused and the publication stays public

#### Scenario: A scheduled publication without the lift right

- **GIVEN** an editor without the `liftEmbargo` right and a scheduled publication
- **WHEN** the editor opens the publication page
- **THEN** Publish now is not offered and the page shows the embargo moment
