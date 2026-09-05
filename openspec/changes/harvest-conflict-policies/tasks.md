# Tasks: harvest-conflict-policies

- [ ] 0.1 Author the delta spec: the four policies, the full item state
      machine (every transition), resolution actions incl. per-field merge
      and bulk, resolution audit
- [ ] 1.1 Policy execution in the harvest handler; evaluate rule-shaped
      policies via the shared OR decision-table evaluator, never an app-local
      matcher
- [ ] 1.2 Idempotent re-entry for items parked `conflict` by
      harvest-feed-intake
- [ ] 2.1 Manual-review queue page (pagination >100) + resolution modal with
      side-by-side and per-field diff
- [ ] 2.2 Resolution handler: link/update/discard + audit record; bulk apply
- [ ] 3.1 Unit tests per policy and per state transition
- [ ] 3.2 e2e: conflict created → reviewed → merged, and a bulk resolution
- [ ] 4.1 i18n (nl/en) for queue + modal; quality gates as usual
