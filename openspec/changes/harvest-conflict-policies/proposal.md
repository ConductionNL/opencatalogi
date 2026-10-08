---
kind: mixed
depends_on: [harvest-feed-intake, openregister/app-harvest-fetchers-and-flow-node]
---

# Proposal: harvest-conflict-policies

Third slice of the re-scoped `dcat-oai-pmh-harvesting` umbrella. Revised in spec round part 2 (7 October 2026, decision 80): OpenRegister now carries the collision detection, the strategies, the item states, the resolution API and the resolution log in `openregister/app-harvest-fetchers-and-flow-node`. This change keeps what is OpenCatalogi's: the policy choice in the feed modal and the review queue screen.

## Summary

`harvest-feed-intake` saves every OpenCatalogi source with strategy `manual`, so every collision waits for a person. This change lets the administrator pick, per feed, what a collision does, in OpenCatalogi's words, and gives the people who own the local publication a queue to decide the ones left to them.

| Policy in the feed modal | OpenRegister strategy | What happens on a collision |
|---|---|---|
| Ask a person (`manual-review`, default) | `manual` | Local publication untouched, item waits in the review queue |
| Use the harvested version (`overlay`) | `source-wins` | Local publication updated, previous state kept as a version |
| Keep ours, store theirs aside (`shadow-local`) | `local-wins` | Local publication untouched, harvested copy kept, not applied |
| Drop the harvested version (`reject-on-conflict`) | `reject` | Local publication untouched, harvested copy dropped |

A locally deleted publication is never brought back by a policy: OpenRegister always queues that case.

## What OpenCatalogi builds

- The policy choice and a per-reason override in `HarvestFeedModal.vue`, saved as the source's `conflictStrategy` and `conflictRules`.
- A "Harvest review" page listing the waiting conflicts of OpenCatalogi's sources, a resolution modal with a field-by-field comparison, and bulk keep local, use harvested and discard. All of it calls OpenRegister's sync-record routes; OpenCatalogi stores nothing.

## What OpenRegister does (not built here)

From `openregister/app-harvest-fetchers-and-flow-node` design D-7 to D-9 and D-12: collision reasons (`pre-existing-claim`, `local-edited`, `local-deleted`), strategies and states (`conflict`, `shadowed`, `rejected`), the decision-table evaluation of `conflictRules`, `GET /api/sources/{id}/sync-records?status=conflict` filtered to records the caller may update, the single and bulk resolve routes writing under the caller's own rights, and the append-only `resolutions` log. The previous version of this change specified all of that as app fields on `harvest-feed`, `harvested-item` and `harvest-run`; those are gone, and so is its migration step, since the previous slice was never built.

## Rows

- `od-harvest-conflict`: "Decide what happens when a harvested record conflicts with a local edit."

## Non-goals

- New protocols, SHACL and dashboards (other slices).
- A conflict screen in OpenRegister (OpenRegister D-14: the queue is an app screen).

## Capabilities

### New capabilities

- `harvest-conflict-policies`: the per-feed policy choice and the review queue for harvested publications that collide with local work.
