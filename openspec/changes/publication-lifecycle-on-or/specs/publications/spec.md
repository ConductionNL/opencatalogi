---
status: proposed
---

# Publication lifecycle on OpenRegister

## ADDED Requirements

### Requirement: The publication declares one lifecycle with named transitions (REQ-PLC-001)

The publication schema in `lib/Settings/publication_register.json` SHALL declare its `x-openregister-lifecycle` on the field `status` with the stored states `draft`, `in_review`, `approved`, `published` and `archived`, initial `draft` and final `archived`. It SHALL declare these named transitions and no other:

| action | from | to | note |
|---|---|---|---|
| `submit` | draft | in_review | |
| `approve` | in_review | approved | |
| `reject` | in_review | draft | declares a required input `reason` |
| `retract` | approved | draft | |
| `publish` | approved | published | sets `firstReleasedAt` to `@now` when empty |
| `publishWithoutReview` | draft | published | allowed only while the app config `publication_review_required` is `false`; sets `firstReleasedAt` when empty |
| `archive` | published | archived | the existing transition, unchanged |

The `status` enum SHALL list the five stored states. `firstReleasedAt` SHALL be a `date-time` property marked `immutable` (OpenRegister REQ-OAS-005), so it is set once and never changed. A list-form edit of `status` that no transition allows SHALL be refused by OpenRegister's `LifecycleValidationListener` with code `lifecycle-invalid-transition`. A named transition not allowed from the current state SHALL be refused by `TransitionEngine::transition()` with a message that names the action and the current state. `GET /apps/openregister/api/objects/{id}/available-actions` SHALL answer the moves allowed from the publication's current state, and `GET /api/publications/lifecycle` SHALL answer the whole transition table above as JSON for a caller that wants it without an object.

#### Scenario: An illegal move is refused by name
<!-- @e2e exclude API contract on OpenRegister's transition route; proven by PublicationLifecycleTest::testAnIllegalMoveIsRefusedByName, which fails on today's code because the schema declares only archive. -->

- **GIVEN** a publication in state `draft`
- **WHEN** a caller posts `{"action": "approve"}` to `/apps/openregister/api/objects/{id}/transition`
- **THEN** the answer is 422 and its message names `approve` and the state `draft`
- **AND** the publication is still `draft`

#### Scenario: A list-form edit that skips review is refused
<!-- @e2e exclude Pre-save refusal by OpenRegister's lifecycle listener; proven by PublicationLifecycleTest::testAListFormEditThatSkipsReviewIsRefused. -->

- **GIVEN** a publication in state `in_review`
- **WHEN** a caller saves it with `status` `published`
- **THEN** the save is refused with code `lifecycle-invalid-transition` naming `status`, `in_review` and `published`

#### Scenario: The allowed moves are published
<!-- @e2e exclude API contract; proven by PublicationLifecycleControllerTest::testTheTransitionTableIsPublished and PublicationLifecycleTest::testAvailableActionsFollowTheState. -->

- **GIVEN** a publication in state `in_review`
- **WHEN** a caller asks its available actions
- **THEN** the answer lists exactly `approve` and `reject`
- **AND** `GET /api/publications/lifecycle` lists all seven transitions with their from and to states

#### Scenario: Publishing without review is closed when review is required
<!-- @e2e exclude Guard on the transition; proven by PublicationLifecycleTest::testPublishWithoutReviewIsRefusedWhenReviewIsRequired. -->

- **GIVEN** `publication_review_required` is `true` and a publication in state `draft`
- **WHEN** a caller runs `publishWithoutReview`
- **THEN** the transition is refused and the publication stays `draft`

### Requirement: The state of a publication is one value the server computes (REQ-PLC-002)

`PublicationStateService::stateOf()` SHALL answer one of `draft`, `in_review`, `approved`, `scheduled`, `public`, `withdrawn` and `archived`. The stored states `draft`, `in_review`, `approved` and `archived` SHALL be answered as stored. For the stored state `published` it SHALL derive `scheduled`, `public` or `withdrawn` from `publicationDate` and `depublicationDate` exactly as it does today. An empty stored `status` SHALL answer `draft`. The publications list (`src/views/publications/`) SHALL show this value as a column and as a facet, and the publication detail page SHALL show it in its header. Both SHALL take it from the server (`GET /api/publications/{id}/visibility` and a `state` key the list endpoint adds per row), never compute it in the browser.

#### Scenario: One state on the list and the page

- **GIVEN** five publications: one `draft`, one `in_review`, one `published` with a publication date next week, one `published` with a publication date last week, and one `published` whose depublication date has passed
- **WHEN** an editor opens the publications list and then each detail page
- **THEN** the list shows Draft, In review, Scheduled, Public and Withdrawn
- **AND** each detail page shows the same state as its row

#### Scenario: A stored draft with a past date is still a draft
<!-- @e2e exclude Server-side derivation; proven by PublicationStateServiceTest::testAStoredDraftWithAPastPublicationDateIsADraft, which fails on today's code because stateOf reads only the dates. -->

- **GIVEN** a publication with stored state `draft` and a publication date last week
- **WHEN** its state is computed
- **THEN** the state is `draft`

### Requirement: A publication is public only in the published state, and every publish path moves the lifecycle (REQ-PLC-003)

The public read rule of the publication schema SHALL require the stored `status` to be `published` in addition to the date conditions (RET-001 as modified). Every path that makes a publication public SHALL run a named transition through `TransitionEngine` instead of writing `publicationDate` alone: `PublicationStateController::publish()`, `EventService::publishObject()`, `BatchPublicationWriter` and `src/modals/object/MassPublishObjects.vue`. Each SHALL run `publish` from `approved`, or `publishWithoutReview` from `draft` while review is not required, and SHALL set `publicationDate` in the same call through the transition's `data`. When the transition is refused, the path SHALL report the refusal and SHALL NOT write `publicationDate`. A batch publish from a Woo batch (`BatchPublicationWriter`) SHALL create the publication in state `approved` and run `publish`, because the batch review is its approval.

#### Scenario: A draft with a past date is not public
<!-- @e2e exclude Read rule on a public endpoint; proven by PublicationLifecycleReadRuleTest::testADraftWithAPastPublicationDateIsNotPublic, which reads the shipped read rule and fails on today's code because the rule matches dates only. -->

- **GIVEN** a publication with stored state `draft` and a publication date last week
- **WHEN** an anonymous reader fetches it on the public API, in search or in a sitemap
- **THEN** it is absent and the direct fetch answers 404

#### Scenario: Publish now moves the lifecycle

- **GIVEN** a publication in state `approved`
- **WHEN** an editor chooses Publish now on its page
- **THEN** its state is `published`, its publication date is now and `firstReleasedAt` is set
- **AND** the page shows Public

#### Scenario: Publish now on an unreviewed draft when review is required
<!-- @e2e exclude Controller refusal; proven by PublicationStateControllerTest::testPublishRefusesAnUnreviewedDraftWhenReviewIsRequired. -->

- **GIVEN** `publication_review_required` is `true` and a publication in state `draft`
- **WHEN** `POST /api/publications/{id}/publish` is called
- **THEN** the answer is 422 naming `publish` and `draft`
- **AND** no `publicationDate` is written

### Requirement: A draft that was never released is deleted permanently with everything on it (REQ-PLC-004)

`DELETE /api/publications/{id}/draft` SHALL delete permanently a publication whose stored state is `draft` or `in_review` and that was never released, together with its attached files, its document references, its `depublication`, `publicationProcess` and `wooAssessment` records that point at it, and any suggestion records that point at it. "Never released" SHALL mean: `firstReleasedAt` empty, no `depublication` record names it, and the audit trail of the object holds no entry in which `status` was `published`. Any one of those that holds, or an audit trail that cannot be read, SHALL refuse the delete with 409 and the reason. The caller SHALL need the update right on the publication. Before removing anything the service SHALL list every object and file it will remove. It SHALL then remove them, with OpenRegister's `ObjectService::deleteObject(..., permanent: true)` for objects, and write one audit entry through `AuditTrailMapper::createAuditTrail()` with action `publication.draft.purged`, the publication's title and id, and the list. When a removal fails part way it SHALL stop, keep the publication, and answer 500 naming what was already removed.

#### Scenario: A draft and everything on it goes

- **GIVEN** a draft publication that was never released, with two files and one document reference
- **WHEN** an editor chooses Delete permanently on its page and confirms
- **THEN** the publication, both files and the reference are gone and cannot be restored
- **AND** one audit entry `publication.draft.purged` lists the publication and the three removed items

#### Scenario: Anything ever released is refused
<!-- @e2e exclude Fail-closed refusal; proven by DraftPurgeServiceTest::testAPublicationThatWasEverPublishedIsRefused, DraftPurgeServiceTest::testAnUnreadableAuditTrailRefuses. -->

- **GIVEN** a publication in state `draft` that was published last month and retracted
- **WHEN** `DELETE /api/publications/{id}/draft` is called
- **THEN** the answer is 409 naming the earlier release
- **AND** nothing is removed

#### Scenario: A failure part way keeps the publication
<!-- @e2e exclude Failure path; proven by DraftPurgeServiceTest::testAFailurePartWayKeepsThePublicationAndNamesWhatWent. -->

- **GIVEN** a draft with two files, where deleting the second file fails
- **WHEN** the delete runs
- **THEN** the publication still exists and the answer names the first file as removed

### Requirement: A caller lists exactly the publications ready to publish (REQ-PLC-005)

`GET /api/publications/ready` SHALL answer, for an authenticated caller, the publications whose stored state is `approved`, that the caller may read, as `{results: [{id, title, catalog, caseReference, approvedAt, updated}], total, since}`. It SHALL accept `since` (an ISO 8601 moment; only publications that entered `approved` at or after it) and `caseReference` (exact match, where the schema carries it). It SHALL answer nothing else: no draft, no publication in review, no published one. An anonymous caller SHALL get 401. The route SHALL be declared before any `/api/publications/{id}` route in `appinfo/routes.php`.

#### Scenario: A source system reconciles
<!-- @e2e exclude API contract for a calling system; proven by ReadyPublicationsControllerTest::testOnlyApprovedPublicationsAreListed, which fails on today's code because the route does not exist. -->

- **GIVEN** four publications: one `draft`, one `in_review`, two `approved` of which one entered `approved` yesterday, and one `published`
- **WHEN** an authenticated caller asks `GET /api/publications/ready?since=<today 00:00>`
- **THEN** the answer lists exactly the one approved today, with `total` 1

#### Scenario: An anonymous caller
<!-- @e2e exclude Auth posture; proven by ReadyPublicationsControllerTest::testAnAnonymousCallerIsRefused. -->

- **WHEN** an anonymous caller asks `GET /api/publications/ready`
- **THEN** the answer is 401

### Requirement: A public publication can be unlisted and put back (REQ-PLC-006)

The publication schema SHALL carry a boolean `unlisted`, default `false`. While `unlisted` is `true` the publication SHALL stay readable at its own public link (the public API by id, its attachments and downloads) and SHALL be absent from: `POST /api/publications/search` and every listing endpoint of `PublicationsController`, the DiWoo sitemaps (`SitemapService`), the DCAT feed (`DcatService`), the federation list (`GET /api/federation/publications`) and the OAI-PMH list verbs when that endpoint ships. `PlooiDeliveryService::deliver()` SHALL refuse an unlisted publication and record the reason `unlisted`. The filter SHALL sit in the one place each surface builds its query, not as a post-filter in a view. Unlisting SHALL NOT write a depublication and SHALL NOT send a withdrawal. Clearing `unlisted` SHALL put the publication back on every surface at the next request, and the detail page SHALL offer Unlist and List again to a user with the update right.

#### Scenario: Unlisted, still reachable

- **GIVEN** a public publication that an editor has unlisted
- **WHEN** an anonymous reader searches for its title, and opens its public link
- **THEN** search does not find it
- **AND** its public link still shows it with its files

#### Scenario: Unlisted publications stay off the machine surfaces
<!-- @e2e exclude Public XML and JSON feeds; proven by UnlistedPublicationTest::testAnUnlistedPublicationIsAbsentFromSitemapDcatAndFederation and PlooiDeliveryServiceTest::testAnUnlistedPublicationIsNotDelivered, which fail on today's code because no surface reads the flag. -->

- **GIVEN** a public, unlisted Woo publication
- **WHEN** its category sitemap page, the DCAT feed and the federation list are requested, and PLOOI delivery runs for it
- **THEN** none of them carries it and PLOOI delivery records `unlisted`

#### Scenario: Put back

- **GIVEN** an unlisted public publication
- **WHEN** an editor chooses List again
- **THEN** search finds it and its category sitemap page carries it

### Requirement: Existing publications get a stored state on upgrade (REQ-PLC-007)

A repair step `OCA\OpenCatalogi\Repair\BackfillPublicationLifecycleState`, registered post-migration in `appinfo/info.xml` after `InitializeSettings`, SHALL set the stored state of every publication whose `status` is empty: `published` with `firstReleasedAt` set to its `publicationDate` when a `publicationDate` is set, else `draft`. It SHALL leave `archived` as it is, and it SHALL report the counts. It SHALL be idempotent. It SHALL also rewrite the public read rule on an install where the schema import is skipped because the version did not move, as `WOO536RepairReadRules` does.

#### Scenario: A legacy public publication stays public
<!-- @e2e exclude Repair step; proven by BackfillPublicationLifecycleStateTest::testALegacyPublicationWithADateBecomesPublished. -->

- **GIVEN** a publication with an empty `status` and a publication date last year
- **WHEN** the repair step runs
- **THEN** its stored state is `published`, `firstReleasedAt` is that date, and it is still public

#### Scenario: A legacy publication without a date becomes a draft
<!-- @e2e exclude Repair step; proven by BackfillPublicationLifecycleStateTest::testALegacyPublicationWithoutADateBecomesADraft. -->

- **GIVEN** a publication with an empty `status` and no publication date
- **WHEN** the repair step runs
- **THEN** its stored state is `draft`

## MODIFIED Requirements

### Requirement: The server tells the page whether a publication is public (REQ-PPW-001)

`GET /api/publications/{id}/visibility` SHALL answer the publication's state as one of draft, in_review, approved, scheduled, public, withdrawn or archived, computed by `PublicationStateService::stateOf()` from its stored lifecycle state and, while that state is `published`, its publication date and depublication date (REQ-PLC-002). It SHALL refuse a user who cannot read the publication.

#### Scenario: A publication with a past publication date

- **GIVEN** a publication in stored state `published` with a publication date last week and no depublication date
- **WHEN** an editor's page calls `GET /api/publications/{id}/visibility`
- **THEN** the answer is public

#### Scenario: A withdrawn publication

- **GIVEN** a publication in stored state `published` whose depublication date has passed
- **WHEN** the page asks for its visibility
- **THEN** the answer is withdrawn

#### Scenario: A publication in review

- **GIVEN** a publication in stored state `in_review` with a publication date last week
- **WHEN** the page asks for its visibility
- **THEN** the answer is in_review
