# Design: harvest-feed-intake

An administrator registers a DCAT JSON-LD feed. The OpenRegister flow engine runs it on a schedule. Each dataset in the feed becomes a draft publication with its source on it. This document fixes where every part of that runs, and which existing mechanism it reuses.

## D1. What already exists, and what this change uses from it

Three repos already carry a piece of harvesting. We checked each before deciding what OpenCatalogi builds.

| Mechanism | Where | What we take | What we do not take, and why |
|---|---|---|---|
| Flow engine: `TriggerScheduleNode` with required `cron` and `runAs`, manual trigger, contributed nodes through `RegisterFlowNodesEvent`, contributed nodes run inside `ObjectService::runAs()` | openregister `openspec/specs/flow-engine`, `flow-scheduled-trigger` (REQ-SCH-003, REQ-SCH-004), `flow-engine-consumer-seams` | The schedule, the manual run, the acting identity, the no-overlap guarantee. The harvest step is one contributed node. | Nothing. No app cron, no `TimedJob`, no app scheduler (ADR-065, One Engine wave 5). |
| Mappings: the `Mapping` entity with dot paths, Twig, `cast` and `unset`, applied by `MappingService` | openregister `data-sync-harvesting` ("field mapping and transformation via the existing Mapping entity") | The item mapping. A feed names an OpenRegister mapping; the node runs it through `MappingService`. | An app-local JSON-path evaluator. The proposal's "JSON-path only" is met by the mapping's dot paths. ADR-022 lists mappings as an OR abstraction. |
| Object writes with validation, audit and versions | openregister `ObjectService::saveObject()` | Every write. The audit trail records the run's `runAs` identity; content versioning keeps the previous state. | A second writer. |
| Sync pipeline: `Source`, `SyncRecord`, `HarvestPipelineService`, `SyncConflictResolver`, `SyncDataJob` | openregister `data-sync-harvesting`, `lib/Service/Sync/` | The vocabulary. Item states and the later conflict policies line up with `SyncRecordStatus` and `SyncConflictResolver` (D6). | The runner. `SourceFetcherRegistry` is filled in OpenRegister's own `Application.php` with `RestApiSourceFetcher` only, so no app can add a DCAT fetcher today. `SyncDataJob` is a `TimedJob`, outside the flow engine. See D7 for the open question. |
| Fetch through a source, outbound call log, rate-limit pacing | integriq `source-management`, `http-call-engine`, `openconnector.source-call` node | When integriq is installed, an administrator MAY point a feed at an integriq source slug instead of a bare URL. The fetch then runs through `openconnector.source-call`. | A hard dependency. A bare URL must work without integriq. |
| Record ownership by a source | integriq `source-owned-records` (REQ-SOR-001 to REQ-SOR-006, archived 2026-09-28) | The disappearance rule: no tombstone after an incomplete fetch (REQ-SOR-004), and a soft flag, never a delete (REQ-SOR-003). | The ownership contract itself. OpenCatalogi records provenance on the object (D5); adopting REQ-SOR-006 is a follow-up once integriq is required. |
| Outbound URL guard | opencatalogi `DirectoryService::assertSafeOutboundUrl()` and its per-hop redirect check | Every bare-URL fetch. The guard moves to a small shared service so directory sync and harvest call the same code. | A second guard. |
| GitHub harvest | opencatalogi `openspec/changes/publiccode-github-harvest` | The pattern: a flow in OpenRegister's store, inert until an administrator switches it on, an admin card with Run now and Switch off. | Its fixed shard topology. A feed is one flow with one harvest node. |

## D2. One flow per enabled feed

- Saving an enabled feed writes one flow into OpenRegister's flow store. Its key is the feed's uuid, so a second save updates the same flow instead of adding one.
- The flow is `schedule trigger` and `manual trigger`, both into `opencatalogi.harvest-feed`, into `end`. The node's config carries only `feedId`; it reads the rest from the feed at run time, so editing a feed's mapping needs no flow rewrite.
- The schedule trigger carries the feed's `schedule` as `cron` and the feed's `runAs` as `runAs`. `runAs` is a required feed field. The form offers the saving administrator as the default value, visibly, and never fills it in silently. OpenRegister refuses a trigger without one, and so does the feed form, before the flow is written.
- Disabling a feed disables its flow. Deleting a feed deletes its flow. Its items and runs stay, as history.
- "Run now" starts the flow's manual trigger. There is no second execution path.

## D3. What the node does in one run

1. Read the feed. Stop with run status `failed` when it is disabled or its mapping is gone.
2. Fetch `sourceUrl` (or call the integriq source). Guard: scheme `http` or `https`, no private, loopback, link-local or metadata address on any hop, 30 s timeout, at most 3 attempts with backoff 2 s, 4 s, 8 s on a 5xx or a timeout, response body at most 50 MB.
3. Parse JSON-LD. Datasets are the nodes typed `dcat:Dataset`, found under the catalog's `dcat:dataset` or in `@graph`. A dataset's `@id` is its `externalUri`. A dataset without `@id` is skipped and counted as an error.
4. Map each dataset through the feed's mapping into the target schema.
5. Hash the mapped payload: SHA-256 over JSON with keys sorted at every level and no insignificant whitespace.
6. Decide per item (D4) and write through `ObjectService::saveObject()`.
7. Write the `HarvestRun`.

## D4. Item decision table

| Situation | Item state | Local object |
|---|---|---|
| No item for this `externalUri`, no local object claims it | `new` | Created as a draft, linked |
| Item exists, checksum equal | `unchanged` | Untouched |
| Item exists, checksum differs, local object not edited since the last apply | `updated` | Updated, still not published by the harvest |
| No item yet, but a local object in the target schema already carries this `externalUri` as `dct:source` | `conflict` | Untouched |
| Item exists, local object edited since `lastAppliedAt` | `conflict` | Untouched |
| Item exists, linked object deleted locally | `conflict` | Not recreated |

"Edited since" means the object's `@self.updated` is later than the item's `lastAppliedAt`. The harvest's own write sets both, so a harvest never conflicts with itself.

Draft-only holds for every protocol: a harvest never sets `publicatiedatum`, never moves it, and never changes `status` or `unlisted`. That generalises REQ-HPP-002 of `harvest-protocol-plugins` to the DCAT protocol.

## D5. Schemas and provenance

Register fragment `lib/Settings/register.d/harvest.json` (ADR-037), in the `publication` register, three schemas, read and write for admins only:

- `harvest-feed`: `name`, `sourceUrl` or `sourceSlug` (exactly one), `protocol` (enum, `dcat-jsonld` only in this slice; `harvest-protocol-plugins` opens it), `schedule`, `runAs`, `enabled`, `targetCatalog`, `targetSchema`, `mapping` (OpenRegister mapping slug), `maxItemsPerRun` (default 1000, maximum 10000), `flowId` (read only).
- `harvested-item`: `feedId`, `externalUri`, `localObjectId`, `checksum`, `state`, `conflictReason`, `tombstoned`, `tombstonedAt`, `firstSeenAt`, `lastSeenAt`, `lastAppliedAt`, `sourceRevision` (the dataset's `dct:modified` when present).
- `harvest-run`: `feedId`, `trigger` (`schedule` or `manual`), `startedAt`, `finishedAt`, `status` (`success`, `partial`, `failed`), counts per item state, `tombstoned`, `errors` (at most 100 entries, each `externalUri` and message).

The local object gets `dct:source` (the `externalUri`) and `prov:wasDerivedFrom` (the feed uuid) as properties the mapping cannot override.

## D6. Line-up with OpenRegister's sync vocabulary

| This change | OpenRegister `SyncRecordStatus` / `SyncConflictResolver` |
|---|---|
| `new`, `updated` | `imported` |
| `unchanged` | `unchanged` |
| `conflict` | `conflict` (strategy `manual`) |
| run `partial` | sync status `partial` |

The names stay as the proposal has them because `harvest-protocol-plugins` already spec'd against them.

## D7. Open question for Ruben

OpenRegister's `data-sync-harvesting` is a second harvest runner with its own scheduler (`SyncDataJob`) and its own per-record table. This change does not use it, for the two reasons in D1. The alternative is an OpenRegister change first: open `SourceFetcherRegistry` to app contributions and expose the pipeline as a flow node. Then `harvest-feed` becomes an OpenRegister `Source` and `harvested-item` becomes a `SyncRecord`. That is the ADR-022 answer, at the cost of a cross-repo dependency on an unplanned OpenRegister change. This design picks the app-schema route so the slice is buildable now. It keeps the D6 vocabulary so a later move is a data migration, not a redesign.

## D8. Screen

Board `OcInstellingen` on the Zuiddrecht canvas (5NkFW28vZUUij43xzxHg5a) has no harvest-feed section yet. The closest drawn pattern on that board is the "GitHub harvest" card: a source line, "Last run" with time and count, "Run now", "Switch off", and a link out. The "Harvest feeds" section follows that card, one card per feed, plus "New" as on the board's "Open data feeds" section. The feed form is a modal in `src/modals/HarvestFeedModal.vue` (ADR-004). The capability row `od-harvest` already names `OcInstellingen`. A board for the section itself is still to be drawn.

## D9. Tests

- Unit: mapping through the real `MappingService` (skipped with a named reason when OpenRegister's source is not next to the app), checksum stability under key reordering, the D4 table row by row, tombstone only after a complete fetch, flow materialisation idempotent by feed uuid, the URL guard on a redirect to `169.254.169.254`.
- Fixture: `tests/fixtures/harvest/dcat-catalog.jsonld` with three datasets, and a second version with one changed and one removed.
- e2e: `tests/e2e/harvest-feed.spec.ts` registers a feed against the fixture served by the test web server, runs it, and checks the draft publication, its source and the run counts.
