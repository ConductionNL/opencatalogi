---
status: proposed
---

# Harvest conflict policies

## Purpose

An administrator chooses, per harvest feed, what happens when a harvested dataset collides with local work, and the people who own the local publication decide the collisions left to them in a review queue. Collision detection, strategies, states, resolution and its log are OpenRegister's (`openregister/app-harvest-fetchers-and-flow-node`); this spec covers the choice and the screens. Design: `../../design.md`.

## ADDED Requirements

### Requirement: Each feed has a conflict policy (REQ-HCP-001)

The feed modal SHALL offer four policies, each with a one-line explanation, and SHALL store the matching OpenRegister strategy as the source's `conflictStrategy`: "Ask a person" `manual` (the default), "Use the harvested version" `source-wins`, "Keep ours, store theirs aside" `local-wins`, "Drop the harvested version" `reject`. Next to "Use the harvested version" the modal SHALL say that it changes the public text of a publication that is already published. A feed saved before this change SHALL show "Ask a person", which is what it already does.

#### Scenario: An administrator picks a policy
- **GIVEN** the feed modal of an existing feed
- **WHEN** the administrator picks "Keep ours, store theirs aside" and saves
- **THEN** the source's `conflictStrategy` is `local-wins`
- **AND** reopening the modal shows "Keep ours, store theirs aside"

#### Scenario: An existing feed keeps its behaviour
<!-- @e2e exclude Default value; proven by ConflictPolicyFormTest::testAFeedWithoutAChoiceReadsAskAPerson. -->
- **GIVEN** a feed saved by `harvest-feed-intake` with `conflictStrategy` `manual`
- **WHEN** the administrator opens its modal
- **THEN** the policy reads "Ask a person"

### Requirement: The policy can differ per situation (REQ-HCP-002)

The feed modal SHALL offer, per collision reason `local-edited` and `pre-existing-claim`, "Same as the default" or one of the four policies. The system SHALL save the overrides as the source's `conflictRules`: a decision table with hit policy `FIRST`, input `conflictReason`, output `strategy` and one row per overridden reason, and no table when nothing is overridden. `local-deleted` SHALL NOT be offered, because OpenRegister always queues it. A table with inputs other than `conflictReason` SHALL be shown as "Set in OpenRegister" and SHALL NOT be changed by the modal. A table OpenRegister refuses SHALL be reported on the override section.

#### Scenario: Local edits wait, local claims are dropped
<!-- @e2e exclude Table compilation; proven by ConflictPolicyFormTest::testOverridesCompileToOneRowPerReason. -->
- **GIVEN** a feed with default "Use the harvested version"
- **WHEN** the administrator sets "Someone edited the publication here" to "Ask a person" and "A publication here already claims this dataset" to "Drop the harvested version", and saves
- **THEN** the source's `conflictRules` holds two rows: `local-edited` to `manual` and `pre-existing-claim` to `reject`

#### Scenario: No override, no table
<!-- @e2e exclude Table compilation; proven by ConflictPolicyFormTest::testNoOverrideWritesNoTable. -->
- **GIVEN** both reasons set to "Same as the default"
- **WHEN** the administrator saves
- **THEN** the source has no `conflictRules`

### Requirement: People see the conflicts waiting for them (REQ-HCP-003)

The app SHALL offer a "Harvest review" page at `/harvest/review` that lists, for every OpenCatalogi source, the sync records OpenRegister returns for `status=conflict`, with feed, dataset title, reason, since when and a link to the local publication. The page SHALL rely on OpenRegister's filter, so a user sees only conflicts on publications they may update. The page SHALL show "Nothing waits for a decision." when the list is empty. The app SHALL NOT store queue items itself.

#### Scenario: A user without update rights does not see the item
<!-- @e2e exclude The filter is OpenRegister's REQ-HAF-009; the page adds none, proven by a vitest that renders exactly the records the route returns. -->
- **GIVEN** a conflict on a publication the user may read but not update
- **WHEN** the user opens the review page
- **THEN** that conflict is not listed

### Requirement: A conflict is decided field by field (REQ-HCP-004)

Opening a conflict SHALL open `src/modals/HarvestConflictModal.vue`, which lists only the properties where the local and the harvested value differ, side by side, with an `NcSelect` with `inputLabel` per property choosing local or harvested. Protected fields and provenance properties SHALL NOT be offered. The actions "Keep ours", "Use the harvested version", "Save the merge" and "Drop the harvested version" SHALL call OpenRegister's resolve route with `keep-local`, `use-harvested`, `merge` with the per-property choices, and `discard`. A 403 SHALL be shown as "You may not change this publication" and the conflict SHALL stay listed.

#### Scenario: An editor merges one field
- **GIVEN** a conflict where the title and the summary differ between the local and the harvested copy
- **WHEN** the editor takes the harvested title, keeps the local summary and saves the merge
- **THEN** the publication has the harvested title and the local summary
- **AND** the conflict leaves the queue

#### Scenario: A refused resolution keeps the conflict
<!-- @e2e exclude Error path; proven by a vitest on HarvestConflictModal with a 403 response. -->
- **GIVEN** a conflict whose publication the editor lost the right to update after opening it
- **WHEN** the editor saves "Use the harvested version"
- **THEN** the modal says "You may not change this publication" and the conflict is still listed

### Requirement: Several conflicts are decided in one action (REQ-HCP-005)

The review page SHALL let the user select several conflicts and apply "Keep ours", "Use the harvested version" or "Drop the harvested version" to all of them through OpenRegister's bulk resolve route. The page SHALL list each conflict that failed with OpenRegister's reason and SHALL remove the others from the list. Merge SHALL NOT be offered in bulk.

#### Scenario: A bulk keep-ours over two items
- **GIVEN** two conflicts selected on the review page
- **WHEN** the user chooses "Keep ours" for the selection
- **THEN** both leave the queue and both publications are unchanged

#### Scenario: One failing item does not stop the rest
<!-- @e2e exclude Partial failure is OpenRegister's bulk contract; the page's display is proven by a vitest with a mixed response. -->
- **GIVEN** three selected conflicts, one on a publication the user may not update
- **WHEN** the user applies "Use the harvested version"
- **THEN** two leave the queue and the third stays, named with its reason
