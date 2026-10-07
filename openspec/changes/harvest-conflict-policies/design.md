# Design: harvest-conflict-policies

`harvest-feed-intake` saves every OpenCatalogi source with `conflictStrategy: manual`. This change lets each feed say what a collision does, and gives the people who own the local publication a queue for the ones left to them. Since spec round part 2 (7 October 2026, decision 80) every rule about collisions lives in OpenRegister (`openregister/app-harvest-fetchers-and-flow-node`); OpenCatalogi only chooses and shows.

## D1. What OpenRegister owns, and what stays here

| Part | Owner | Where |
|---|---|---|
| Collision detection and reasons `pre-existing-claim`, `local-edited`, `local-deleted` | OpenRegister | D-7, REQ-HAF-006 |
| Strategies `manual`, `source-wins`, `local-wins`, `reject`; `local-deleted` always `conflict` | OpenRegister | D-8, REQ-HAF-007 |
| Item states and every transition (`conflict`, `shadowed`, `rejected`, re-evaluation on an upstream change) | OpenRegister `SyncRecordStatus` | D-8 |
| `conflictRules` decision table, validated at save, evaluated per collision, falling back to `conflictStrategy` | OpenRegister with the shared DMN evaluator | D-8, REQ-HAF-007 |
| Queue listing, filtered to records the caller may update | `GET /api/sources/{id}/sync-records?status=conflict` | D-9, REQ-HAF-009 |
| Resolution: keep local, use harvested, merge per field, discard; single and bulk; under the caller's rights; append-only `resolutions` | `POST /api/sources/{id}/sync-records/{recordId}/resolve` and `POST /api/sources/{id}/sync-records/resolve` | D-9, REQ-HAF-009 |
| Protected fields and provenance kept on every resolution, merge included | OpenRegister | REQ-HAF-008 |
| **Policy choice and per-reason override in the feed modal** | **OpenCatalogi** | D2, D3 |
| **Review queue page, resolution modal, bulk actions** | **OpenCatalogi** | D4, D5 |

OpenCatalogi adds no schema, no field, no state machine and no resolution service.

## D2. Policies

The feed modal offers four choices with a one-line explanation each and stores the OpenRegister strategy:

| Choice | Stored `conflictStrategy` |
|---|---|
| Ask a person (default) | `manual` |
| Use the harvested version | `source-wins` |
| Keep ours, store theirs aside | `local-wins` |
| Drop the harvested version | `reject` |

`newest-wins` is not offered: a DCAT `dct:modified` and a local `updated` come from two clocks.

Next to "Use the harvested version" the modal says that this changes the public text of a publication that is already published. Draft-only still holds: `protectedFields` from `harvest-feed-intake` keep `publicationDate`, `depublicationDate` and `status` out of every harvest write and every resolution.

## D3. Per-reason override

Below the default the modal has "Different per situation", with one select per collision reason: "Someone edited the publication here" (`local-edited`) and "A publication here already claims this dataset" (`pre-existing-claim`), each "Same as the default" or one of the four choices. `local-deleted` is not offered: OpenRegister always queues it.

The app compiles the chosen overrides into `conflictRules`: a decision table in the `flow-decision-tables` shape, hit policy `FIRST`, one input `conflictReason`, one output `strategy`, one row per overridden reason. No row is written for "Same as the default", and no table at all when nothing is overridden, so the fallback to `conflictStrategy` applies. The modal reads an existing table back into the selects; a table the modal did not write (more inputs than `conflictReason`) is shown as "Set in OpenRegister" and left untouched. OpenRegister validates the table at save; a refusal is shown on the override section.

## D4. Review queue

Page `HarvestReview` at `/harvest/review`, in the navigation for every user who may update publications. It reads `GET /apps/openregister/api/sources/{id}/sync-records?status=conflict` for each OpenCatalogi source (the feed list of `harvest-feed-intake`) and shows one table: feed, dataset title (from the harvested payload), reason, since when, and the local publication with a link. OpenRegister limits the list to records the caller may update, so a user who may not update a publication does not see its conflict. Empty state: "Nothing waits for a decision." The list follows the list-page shape of board `OcWooBatches` (a table with a selection bar for bulk actions) until a board for this page exists.

## D5. Resolution modal

`src/modals/HarvestConflictModal.vue` (ADR-004). It lists only the properties where the local and the harvested value differ, side by side, each with an `NcSelect` (`inputLabel` "Keep for <field>") choosing local or harvested. Protected fields and the provenance properties are not offered. Actions: "Keep ours", "Use the harvested version", "Save the merge" and "Drop the harvested version", mapped to `keep-local`, `use-harvested`, `merge` with `fields`, and `discard`. A 403 from OpenRegister is shown as "You may not change this publication" and the item stays in the queue.

Bulk: the selection bar offers keep ours, use harvested and drop, through the bulk route. The result lists each item that failed with OpenRegister's reason; the others leave the queue. Merge is not offered in bulk.

## D6. Open point for OpenRegister

The modal compares the local publication with the harvested payload after mapping. OpenRegister's D-9 says the queue carries `rawData`, the payload as fetched. If that is the raw DCAT node, the modal cannot line it up with publication fields. The preferred fix is one field in OpenRegister's queue response, the mapped payload (`mappedData`), which the pipeline already computes. Until OpenRegister confirms the shape, task 2.2 is blocked; the queue and the whole-record actions (2.1, 2.3) are not.

## D7. Screen

No board on the Zuiddrecht canvas (5NkFW28vZUUij43xzxHg5a) draws the review queue or the resolution modal; the row `od-harvest-conflict` records `screen.board: null`. The policy choice sits in the feed modal of `harvest-feed-intake`, which follows board `OcInstellingen`. A board for the queue and the modal is needed before tasks 2.1 and 2.2 start.

## D8. Tests

- Unit: `ConflictPolicyFormTest` (each choice stores its strategy, the overrides compile to the expected table, no table when nothing is overridden, a foreign table is left untouched).
- vitest: the modal shows only differing fields and never a protected one; a 403 keeps the item.
- e2e: `tests/e2e/harvest-conflict-review.spec.ts`: a local edit, a re-run, the item in the queue, a per-field merge, the merged publication; then a bulk "Keep ours" over two items.
