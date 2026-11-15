# Tasks: dcat-oai-pmh-harvesting (superseded)

The original 177-task list is removed with the 2026-09-02 re-scope (see
proposal.md for the full disposition; the list survives in git history).
Work items live in the successor changes:

- `oai-pmh-endpoint` (a changed-since route; OAI-PMH struck by D10)
- `harvest-feed-intake`
- `harvest-conflict-policies`
- `harvest-protocol-plugins`
- `harvest-observability`

The one task of this umbrella guards the rule its delta spec holds.

- [x] 1.1 Add `tests/Unit/Architecture/HarvestArchitectureTest.php::testNoHarvestSchemaShips` (REQ-DOH-001), in the PR of whichever slice lands first. `HarvestFeedServiceTest::testAFeedIsSavedThroughOpenRegistersSourceService` belongs to `harvest-feed-intake`.
