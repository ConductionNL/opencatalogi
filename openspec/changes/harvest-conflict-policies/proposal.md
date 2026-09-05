---
kind: mixed
depends_on: [harvest-feed-intake]
---

# Proposal: harvest-conflict-policies

Third slice of the re-scoped `dcat-oai-pmh-harvesting` umbrella. Delta spec
authored at pickup.

## Summary

Replace `harvest-feed-intake`'s binary "park as conflict" behaviour with the
umbrella's four per-feed conflict policies and give officers a review
surface:

- `shadow-local`: harvested copy is stored but never linked or applied
- `overlay`: harvested copy updates the linked local object (provenance kept)
- `reject-on-conflict`: item marked `rejected`, never linked
- `manual-review`: item queued; an officer resolves it in a side-by-side diff
  UI (keep local / use harvested / per-field merge / discard)

Policy evaluation SHOULD ride the shared OR decision-table evaluator where a
feed's policy is expressed as rules (mirror
`retention-defaults-on-shared-decision-tables` — no app-local rule matcher),
and the item state machine (new → updated → unchanged, conflict/rejected)
must be spec'd with every transition, including bulk resolution.

## Scope

- Policy execution in the harvest handler, per feed
- Manual-review queue page + resolution modal (modals in `src/modals/`,
  NcSelect with `inputLabel`, per ADR-004)
- Resolution audit: who resolved what, when, into which object
- Migration: items parked `conflict` by the previous slice re-enter the state
  machine idempotently

## Non-Goals

- New protocols, SHACL, dashboards (other slices)

## Capabilities

### New Capabilities

- `harvest-conflict-policies`: per-feed conflict policy enforcement and the
  manual-review resolution surface.
