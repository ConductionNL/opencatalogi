# Tasks: harvest-feed-intake

Authoring order: the delta spec (`specs/harvest-feed-intake/spec.md`) is
task 0 — write it against the OR flow-engine surface current at pickup
(`TriggerScheduleNode` contract, contributed-node registration, `runAs`
scoping per OR `flow-engine-consumer-seams`), then implement.

- [ ] 0.1 Author the delta spec: feed registration, scheduled flow execution,
      mapping, checksum skip, provenance, binary conflict parking, tombstone
- [ ] 1.1 Register fragment with the three schemas (ADR-037), no app tables
- [ ] 1.2 Feed CRUD settings surface + validation (URL guard, JSON-path
      syntax, schedule)
- [ ] 2.1 Flow materialisation per enabled feed: `TriggerScheduleNode` with
      explicit `runAs`; create/update/retire the flow with the feed
- [ ] 2.2 Harvest handler (contributed node): fetch → parse DCAT JSON-LD →
      JSON-path map → checksum compare → save via ObjectService with
      provenance; park collisions as `conflict`; tombstone disappeared items
- [ ] 2.3 HarvestRun accounting per execution
- [ ] 3.1 Unit tests: mapping, checksum skip, conflict parking, tombstone,
      flow-materialisation idempotency
- [ ] 3.2 e2e: register a feed against a served fixture, run it, assert the
      local object + provenance + run counts
- [ ] 4.1 Quality: linters individually, hydra gates `--scope-to-diff`
