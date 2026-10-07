---
status: proposed
---

# Harvest conflict policies

## Purpose

Each harvest feed says what happens when a harvested dataset collides with local work: review it, overlay it, keep the local record, or reject the harvested copy. People who own the local record decide the reviews in a queue, one by one or in bulk, and every decision is recorded. Builds on `harvest-feed-intake`. Design: `../../design.md`. Screen: no board yet (design D8).

## ADDED Requirements

### Requirement: Each feed has a conflict policy (REQ-HCP-001)

A `harvest-feed` SHALL carry `conflictPolicy`, one of `manual-review`, `overlay`, `shadow-local` and `reject-on-conflict`, with default `manual-review`. The feed form SHALL offer the four as a choice with one line explaining each, and SHALL say next to `overlay` that it changes the content of a published publication. The harvest node SHALL apply the policy to every collision that `harvest-feed-intake` REQ-HFI-008 detects.

#### Scenario: An existing feed keeps its behaviour after the upgrade
<!-- @e2e exclude Default value; proven by HarvestRegisterFragmentTest::testConflictPolicyDefaultsToManualReview. -->
- **GIVEN** a feed saved before this change
- **WHEN** it is read after the upgrade
- **THEN** its `conflictPolicy` is `manual-review`

#### Scenario: An administrator picks a policy
- **GIVEN** the feed modal of a feed
- **WHEN** the administrator chooses "Keep the local record" and saves
- **THEN** the feed's `conflictPolicy` is `shadow-local`

### Requirement: Policies decide a collision (REQ-HCP-002)

On a collision the harvest node SHALL act on the policy that applies:
- `manual-review`: set the item to `conflict`, keep the mapped payload as `harvestedPayload`, leave the local object untouched.
- `overlay`: save the harvested payload over the local object through `ObjectService::saveObject()`, keep `dct:source` and `prov:wasDerivedFrom`, and set the item to `updated`. The previous state SHALL stay restorable as a version.
- `shadow-local`: set the item to `shadowed`, keep `harvestedPayload`, and never apply or link it.
- `reject-on-conflict`: set the item to `rejected`, drop the payload, and never link it.

Under every policy the harvest MUST NOT set or change `publicatiedatum`, `status` or `unlisted`. A collision with reason `deleted-locally` SHALL be handled as `manual-review` under every policy, so a harvest never recreates a deleted object.

#### Scenario: Overlay updates the local record and keeps the old one
<!-- @e2e exclude Server-side policy; proven by ConflictPolicyTest::testOverlayUpdatesAndKeepsTheVersion. -->
- **GIVEN** a feed with policy `overlay` and a harvested draft an editor changed
- **WHEN** the dataset changes upstream and the feed runs
- **THEN** the publication carries the harvested content and its source
- **AND** the editor's version is in the publication's history
- **AND** the item is `updated`

#### Scenario: Shadow-local keeps the harvested copy aside
<!-- @e2e exclude Server-side policy; proven by ConflictPolicyTest::testShadowLocalKeepsThePayloadAndTouchesNothing. -->
- **GIVEN** a feed with policy `shadow-local` and an edited harvested draft
- **WHEN** the feed runs with a changed dataset
- **THEN** the publication keeps the editor's content
- **AND** the item is `shadowed` and holds the harvested payload

#### Scenario: Reject-on-conflict drops the harvested copy
<!-- @e2e exclude Server-side policy; proven by ConflictPolicyTest::testRejectDropsThePayloadAndLinksNothing. -->
- **GIVEN** a feed with policy `reject-on-conflict` and a local publication that already names the dataset as its source
- **WHEN** the feed runs
- **THEN** the item is `rejected`, has no payload and no `localObjectId`

#### Scenario: Overlay never recreates a deleted publication
<!-- @e2e exclude Fail-safe path; proven by ConflictPolicyTest::testDeletedLocallyFallsBackToManualReview. -->
- **GIVEN** a feed with policy `overlay` and a harvested publication an editor deleted
- **WHEN** the feed runs
- **THEN** no publication is created and the item is `conflict` with reason `deleted-locally`

### Requirement: Rules can pick the policy per item (REQ-HCP-003)

A feed MAY carry `conflictRules`, an inline decision table in the shape OpenRegister's `flow-decision-tables` defines, with output `policy`. The system SHALL check the table with OpenRegister's `DecisionTableValidator` when the feed is saved and refuse a table it cannot execute. The harvest node SHALL evaluate the table with OpenRegister's `DecisionTableEvaluator` for each collision. When no row matches, the feed's `conflictPolicy` SHALL apply. The app MUST NOT ship its own rule matcher.

#### Scenario: A rule sends local deletions and local claims different ways
<!-- @e2e exclude Server-side evaluation; proven by ConflictRulesTest::testTheMatchingRowPicksThePolicy. -->
- **GIVEN** a feed with default `manual-review` and a rule "reason `claimed-locally` gives `reject-on-conflict`"
- **WHEN** the run meets one `claimed-locally` and one `edited-locally` collision
- **THEN** the first item is `rejected` and the second is `conflict`

#### Scenario: A broken table cannot be saved
<!-- @e2e exclude Validation path; proven by ConflictRulesTest::testAnUnexecutableTableIsRefusedAtSave. -->
- **GIVEN** a feed form whose rule table writes an output other than `policy`
- **WHEN** the administrator saves
- **THEN** the save is refused with the validator's message

### Requirement: Every item transition is defined (REQ-HCP-004)

An item's state SHALL be one of `new`, `updated`, `unchanged`, `conflict`, `shadowed` and `rejected`, and SHALL only move along the transitions in design D4. A `conflict` item whose dataset changes upstream SHALL stay `conflict` with its `harvestedPayload` refreshed. A `shadowed` or `rejected` item whose checksum is unchanged SHALL keep its state; when the checksum changes, the policy SHALL be evaluated again as for a new collision. Any other transition SHALL be refused and logged with the item's uuid.

#### Scenario: A conflict follows the source while it waits
<!-- @e2e exclude State machine; proven by HarvestItemStateMachineTest::testAWaitingConflictGetsTheNewestPayload. -->
- **GIVEN** an item in `conflict`
- **WHEN** its dataset changes upstream and the feed runs
- **THEN** the item is still `conflict` and its payload is the new version

#### Scenario: A rejected item comes back only when the source changes
<!-- @e2e exclude State machine; proven by HarvestItemStateMachineTest::testARejectedItemIsReconsideredOnlyOnChange. -->
- **GIVEN** a `rejected` item on a feed now set to `manual-review`
- **WHEN** the feed runs with the dataset unchanged, and later with it changed
- **THEN** after the first run the item is `rejected`, after the second it is `conflict`

#### Scenario: An undefined transition is refused
<!-- @e2e exclude Guard; proven by HarvestItemStateMachineTest::testEveryTransitionOutsideTheTableIsRefused. -->
- **GIVEN** an item in `rejected`
- **WHEN** code asks to move it to `unchanged`
- **THEN** the move is refused and the item stays `rejected`

### Requirement: People resolve conflicts in a review queue (REQ-HCP-005)

The app SHALL offer a "Harvest review" page listing `conflict` items whose local object the signed-in user may update under OpenRegister RBAC; administrators see all. The list SHALL show feed, dataset title, reason and the date the conflict arose, SHALL page on the server at 50 rows per page, and SHALL show an empty state when nothing waits. Opening an item SHALL open `src/modals/HarvestConflictModal.vue`, which lists only the fields where the local and harvested values differ, side by side, and offers: keep local, use harvested, merge per field, and discard. Per-field choices SHALL use NcSelect with `inputLabel` (ADR-004).

#### Scenario: An editor merges one field
- **GIVEN** a `conflict` item where title and summary differ between the local and harvested copy
- **WHEN** the editor opens it, takes the harvested title and keeps the local summary, and saves
- **THEN** the publication has the harvested title and the local summary
- **AND** the item is `updated` and leaves the queue

#### Scenario: The queue pages past one hundred items
<!-- @e2e exclude Paging; proven by HarvestReviewControllerTest::testTheQueuePagesOnTheServer. -->
- **GIVEN** 120 conflict items
- **WHEN** the queue is requested page by page
- **THEN** the pages hold 50, 50 and 20 items with no duplicates

#### Scenario: A user without update rights does not see the item
<!-- @e2e exclude Authorization path; proven by HarvestReviewControllerTest::testItemsOutsideTheUsersRightsAreHidden. -->
- **GIVEN** a conflict on a publication the user may read but not update
- **WHEN** the user opens the queue
- **THEN** that item is not listed

### Requirement: Several conflicts are resolved in one action (REQ-HCP-006)

The queue SHALL let the user select several items and apply keep local, use harvested, or discard to all of them. Each item SHALL be resolved on its own: one item that fails SHALL NOT undo the others, and the result SHALL name each item that failed with its reason. Merge per field SHALL NOT be offered in bulk.

#### Scenario: A bulk keep-local over two items
- **GIVEN** two `conflict` items selected in the queue
- **WHEN** the user chooses "Keep local" for the selection
- **THEN** both items are `shadowed` and leave the queue
- **AND** each item's last resolution has `bulk: true`

#### Scenario: One failing item does not stop the rest
<!-- @e2e exclude Partial failure; proven by HarvestResolutionServiceTest::testOneFailingItemLeavesTheOthersResolved. -->
- **GIVEN** three selected items, one of whose publications was locked by another user
- **WHEN** the user applies "Use harvested"
- **THEN** two items are `updated`, the third stays `conflict`, and the result names it with the lock as reason

### Requirement: Every resolution is recorded (REQ-HCP-007)

Each resolution SHALL append to the item's `resolutions` an entry with `resolvedBy`, `resolvedAt`, `action`, the chosen side per field for a merge, the resulting `objectId`, and `bulk`. The write to the publication SHALL run under the resolving user's own rights, so OpenRegister's audit trail names that user. Entries SHALL NOT be edited or removed through the app.

#### Scenario: A merge says who chose what
<!-- @e2e exclude Audit record; proven by HarvestResolutionServiceTest::testAMergeRecordsUserFieldsAndObject. -->
- **GIVEN** a user resolving a conflict by taking the harvested title only
- **WHEN** the resolution is saved
- **THEN** the item's last resolution names the user, the time, action `merge`, `title: harvested`, and the publication's id
- **AND** the publication's audit trail shows the update by that user

### Requirement: Conflicts parked by the previous slice re-enter cleanly (REQ-HCP-008)

Items left in `conflict` by `harvest-feed-intake`, which have no `harvestedPayload`, SHALL keep state `conflict` and SHALL get their payload on the feed's next run. Until then the modal SHALL say the harvested copy arrives with the next run and offer only keep local and discard. Running the upgrade step twice SHALL change nothing the first run did not.

#### Scenario: An old conflict gets its payload on the next run
<!-- @e2e exclude Migration; proven by HarvestConflictMigrationTest::testAnOldConflictIsFilledOnTheNextRunAndOnlyOnce. -->
- **GIVEN** a `conflict` item without a payload from before the upgrade
- **WHEN** the feed runs, and the upgrade step runs again
- **THEN** the item is `conflict` with the harvested payload, and nothing else changed
