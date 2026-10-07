# Design: harvest-conflict-policies

`harvest-feed-intake` parks every collision as `conflict` and stops there. This change lets each feed say what a collision does, and gives the people who own the local record a queue to decide the ones left to them.

## D1. What already exists, and what this change uses from it

| Mechanism | Where | What we take |
|---|---|---|
| Conflict strategies `source-wins`, `local-wins`, `newest-wins`, `manual` and outcomes `apply_source`, `keep_local`, `defer` | openregister `data-sync-harvesting` ("Sync MUST support conflict resolution"), `lib/Service/Sync/SyncConflictResolver.php` | The model. Our four policies map onto it (D2). We do not add `newest-wins`: a DCAT `dct:modified` and a local `@self.updated` come from two clocks, and the proposal did not ask for it. |
| Decision-table step, the shared DMN evaluator, five hit policies, refusal at save, explicit no-match default | openregister `flow-decision-tables`, `lib/Service/Dmn/DecisionTableEvaluator.php`, `DecisionTableValidator.php` | Rule-shaped policies. A feed MAY carry a decision table that picks a policy per item. No app-local rule matcher, same as `retention-defaults-on-shared-decision-tables`. |
| Per-attribute conflict modal: only differing attributes listed, one choice per row, `inputLabel`, modal in its own file | openregister `mdm-conflict-resolution-ui` | The interaction pattern for the review modal. That modal lives inside OpenRegister's golden-record view and is not a shared component, so OpenCatalogi builds its own modal to the same rules. |
| Object writes with audit trail and versions | openregister `ObjectService::saveObject()`, audit trail, content versioning | Every resolution write. The audit entry carries the resolving user; the previous object state stays restorable. |
| Item model, tombstones, run accounting | `harvest-feed-intake` (REQ-HFI-005 to REQ-HFI-010) | Everything. This change adds fields, not schemas. |

## D2. Policies

| Policy | On a collision | OpenRegister equivalent |
|---|---|---|
| `manual-review` (default) | Item `conflict`, harvested payload kept on the item, local object untouched, item in the review queue | `manual` → `defer` |
| `overlay` | Local object updated with the harvested payload, provenance kept, previous state kept as a version, item `updated` | `source-wins` → `apply_source` |
| `shadow-local` | Harvested payload kept on the item, never applied or linked, item `shadowed` | `local-wins` → `keep_local` |
| `reject-on-conflict` | Item `rejected`, payload not kept, never linked | none (new) |

`manual-review` is the default because it is what `harvest-feed-intake` already does, so an upgrade changes nothing until an administrator picks another policy.

`overlay` never deletes a local object and never recreates a deleted one: a `deleted-locally` collision is handled as `manual-review` whatever the policy. Recreating something an editor removed is a decision for a person.

Draft-only still holds under `overlay`. The harvest writes the content fields; it never sets `publicatiedatum`, `status` or `unlisted`. Overlaying a published publication changes its public content, so the feed form says so next to the `overlay` choice.

## D3. Rule-shaped policies

A feed MAY carry `conflictRules`: an inline decision table in the shape `flow-decision-tables` defines. Inputs are `conflictReason`, `externalUri`, `sourceRevision` and the mapped payload's top-level fields. The single output is `policy`, one of the four. Hit policy is `FIRST` or `UNIQUE`. When no row matches, the feed's `conflictPolicy` applies, which is the explicit default that `flow-decision-tables` requires. The table is checked with OpenRegister's `DecisionTableValidator` when the feed is saved, and evaluated with `DecisionTableEvaluator` in the harvest node.

## D4. Item state machine

States: `new`, `updated`, `unchanged`, `conflict`, `shadowed`, `rejected`. `tombstoned` is a flag on any state, set and cleared as `harvest-feed-intake` REQ-HFI-009 says. Every transition:

| From | Event | To |
|---|---|---|
| (none) | first seen, no collision | `new` |
| (none) or `new`, `updated`, `unchanged` | collision, policy `manual-review` | `conflict` |
| (none) or `new`, `updated`, `unchanged` | collision, policy `overlay` | `updated` |
| (none) or `new`, `updated`, `unchanged` | collision, policy `shadow-local` | `shadowed` |
| (none) or `new`, `updated`, `unchanged` | collision, policy `reject-on-conflict` | `rejected` |
| `new`, `updated`, `unchanged` | checksum changed, no collision | `updated` |
| `new`, `updated`, `unchanged` | checksum equal | `unchanged` |
| `conflict` | checksum changed upstream | `conflict` (payload refreshed, resolver sees the newest) |
| `conflict` | checksum equal | `conflict` |
| `conflict` | resolved: keep local | `shadowed` |
| `conflict` | resolved: use harvested | `updated` |
| `conflict` | resolved: merge per field | `updated` |
| `conflict` | resolved: discard | `rejected` |
| `shadowed`, `rejected` | checksum equal | unchanged state |
| `shadowed`, `rejected` | checksum changed upstream | the feed's policy is evaluated again, as a fresh collision |

A bulk resolution is one resolution per item, applied in order, each with its own outcome; one failing item does not roll back the others.

## D5. Fields added

On `harvest-feed`: `conflictPolicy` (enum of the four, default `manual-review`) and `conflictRules` (optional decision table).

On `harvested-item`: `harvestedPayload` (the mapped payload, kept for `conflict` and `shadowed`, cleared on `rejected` and on apply), `policyApplied`, and `resolutions` (list, append only): `resolvedBy`, `resolvedAt`, `action` (`keep-local`, `use-harvested`, `merge`, `discard`), `fields` (for a merge: field name and chosen side), `objectId`, `bulk` (boolean).

On `harvest-run`: counts for `shadowed` and `rejected`.

## D6. Who resolves

The review queue lists `conflict` items whose local object the signed-in user may update under OpenRegister RBAC. Administrators see all. A resolution writes under the resolving user's own rights, not the feed's `runAs`, so the audit trail names the person who decided. A user who loses update rights between opening the modal and saving gets a refusal and the item stays `conflict`.

## D7. Migration

Items parked `conflict` by `harvest-feed-intake` have no `harvestedPayload`. On the first run after upgrade they keep state `conflict`, and the run fills their payload from the fetch. Until then the review modal says the harvested copy arrives with the next run and offers only "Keep local" and "Discard". Re-running the migration is a no-op: an item that already has a payload is not touched.

## D8. Screen

No board on the Zuiddrecht canvas (5NkFW28vZUUij43xzxHg5a) draws the review queue or the resolution modal. `capabilities-opencatalogi.md` lists no harvest review screen, and the row `od-harvest-conflict` records `screen.board: null` ("not designed yet: no harvest review board"). The feed's policy choice sits in the feed modal of `harvest-feed-intake`, which follows board `OcInstellingen`. Until a board exists, the queue follows the list-page shape of `OcWooBatches` (a table with a selection bar for bulk actions) and the modal follows OpenRegister's `mdm-conflict-resolution-ui` rules. A board for the queue and modal is needed before the frontend tasks start.

## D9. Tests

- Unit: one test per policy, one per row of the D4 table, decision-table selection with and without a match, the `deleted-locally` override, migration idempotency, resolution audit fields, bulk with one failing item.
- e2e: `tests/e2e/harvest-conflict-review.spec.ts`: a local edit, a re-run, the item in the queue, a per-field merge, the merged publication; then a bulk "Keep local" over two items.
