# Design: publiccode-github-harvest

## D1. The flow's shape

```
schedule (03:15 nightly) ─┬─> shard-01 ─> hits-01 ─> fetch-01 ─> decoded-01 ─> map-01 ─> write-01 ─┐
manual ───────────────────┤   ...                                                                   ├─> end
                          └─> shard-24 ─> hits-24 ─> fetch-24 ─> decoded-24 ─> map-24 ─> write-24 ─┘
```

- **Both triggers fan out to every shard directly.** A shard node is `openconnector.source-paginate`, which runs its search once per input item. A chain hands shard 2 every page of shard 1 as input and multiplies (12 runs became 3,472 on 2026-08-13). Side by side, each shard receives the trigger's single item and runs once. `PubliccodeHarvestFlowTest` asserts that no edge runs from one shard to another.
- **Every shard has its own tail.** The first build converged all 24 shards on one shared tail. On :8095 that tail processed the pages of 3 of the 24 shards: OpenRegister's `FlowItemPlacement::advanceItems()` ASSIGNS a step's items to its output place, so when several shards fire before the shared consumer does, each one overwrites the last. The tokens add up and the items do not. Separate tails have no shared place before `end`, so nothing is overwritten. The test asserts that only `end` has more than one way in. The engine defect is reported to the coordinator; the per-shard tails are right either way.
- **Why `source-paginate` and not `synchronization-run`.** `synchronization-run` writes each hit to a target through its own mapping, and a code search hit is not a component: the component is in the file the hit points at. `source-paginate` only fetches, and emits one item per page with the hits under `page.results`.
- **Each tail:**
  - `hits-NN`: `openregister.explode` on `page.results` as `hit`, not keeping the page record.
  - `fetch-NN`: `openconnector.source-call` on source `github-raw`, endpoint `/{{ hit.repository.full_name }}/HEAD/{{ hit.path }}`, `decode: "yaml"`, output `publiccode`, `onError: "continue"`. Raw files cost no API quota.
  - `decoded-NN`: `openregister.filter` keeping items whose `publiccode.body.name` and `publiccode.body.url` are set. An undecodable file carries `_error` and no `publiccode` key, so it drops here. The two fields are the schema's `required` list.
  - `map-NN`: `openregister.map` with mapping `publiccode-github-hit` (D3), writing the component under `component`.
  - `write-NN`: `openregister.object-write`, operation `upsert`, register `publication`, schema `publiccode`, `payloadFrom: component`, match `@self.slug` on `{{ component.slug }}`, `maxWrites` 25000.
- **`limits.maxRuntimeMinutes: 240`.** The runtime guard times one walk of the run, not the time it spends suspended, so this bounds one stretch of fetching and writing between two rate-limit pauses.

## D2. Shards

GitHub code search returns at most 1,000 results per query, and indexes files under 384 KB. The 24 shards split `size:0..393216` into contiguous ranges, finer where `publiccode.yml` files cluster (1 KB to 8 KB). The ranges live in `lib/Settings/publiccode-github-shards.json`, one entry per shard: its slug, its range and the synchronization it becomes. The flow's shard nodes name those slugs. `PubliccodeHarvestFlowTest` asserts that the two agree, that the ranges start at 0, end at 393216 and neither overlap nor leave a gap.

Each synchronization: source `github-api`, endpoint `/search/code`, query `q=filename:publiccode.yml size:A..B`, `per_page=100`, `resultsPosition: items`, `maxPages: 10`, `prefetchConcurrency: 1`. Code search allows 10 requests a minute; integriq fetches page one, reads `Link: rel="last"` and then fetches the rest. With the prefetch pool at 1 the pages go one by one, and a refused page suspends the run (lane iq) instead of ending it. A full crawl of 24 shards at up to 10 pages is at most 240 searches, about 24 minutes of quota. It may take hours of wall time and that is fine.

A shard that reaches 1,000 results loses the rest silently, because GitHub stops there. The shard file is the place to split it. The admin section shows each shard's last `total_count` once integriq records it; until then the manual names the check.

## D3. The mapping

An OpenRegister mapping (`components.mappings`, slug `publiccode-github-hit`), because `openregister.map` resolves OpenRegister mappings and keeps this step inside OpenRegister when integriq is absent at import time.

- Every optional field is a Twig expression with `|default('')` and a cast `unsetIfValue==`. A bare dot path whose key is missing renders the path itself as a literal string (integriq documents the same trap in `endoflife-date-source-cycles.json`), so a missing `applicationSuite` would be written as the text `publiccode.body.applicationSuite`.
- Lists (`platforms`, `categories`, `localisationAvailableLanguages`, `maintainers`, `itConformsTo`) go through `json_encode` and the cast `jsonToArray`.
- `maintainers` concatenates `maintenance.contractors` and `maintenance.contacts`, as the schema describes.
- `itConformsTo` lists the keys of `it.conforme` whose value is true. That is the only country extension the schema models today.
- `rawPubliccode` copies the decoded document whole.
- `harvestedFrom` is `github.com`. `harvestedAt` is the time of the write.
- `slug`: the repository full name, lower case, every character outside `a-z0-9` replaced by `-`. A `publiccode.yml` below the root adds its directory, so the components of a monorepo do not overwrite each other.

## D4. Inert until an administrator acts

| Situation | What happens |
|---|---|
| integriq not installed | The flow imports disabled and unowned (OpenRegister's adoption contract). No synchronization exists. The admin section says integriq is needed and offers nothing else. |
| integriq installed, not set up | The shard synchronizations do not exist. Set up writes them. |
| set up, source `github-api` disabled or without credential | The admin section shows the source as not ready and links to it. A run would stop at the first shard with the source's own refusal. |
| set up and switched on | The flow is adopted by the administrator who switched it on and runs nightly. |

**Why the synchronizations are not seeded through `components.objects`.** OpenRegister skips an object whose register is missing, so an install without integriq would skip them and never create them later. Writing them on set-up makes the moment explicit and repeatable.

**Who the run acts as.** The schedule trigger declares `runAs: "admin"`, as every shipped schedule flow in the fleet does (pipelinq's competitor watch). The admin section shows the account. An administrator who wants a service account edits the trigger in the flow editor.

**OpenCatalogi never holds the token.** The `github-api` source authenticates through the credential broker (ADR-064). The admin section links to integriq's source page and reads only whether the source exists and is enabled.

## D5. Componenten catalogue and anonymous visibility

- Seeded catalogue `componenten`, title "Componenten", listed, `status: stable`, `published` set, with `registers: ["publication"]` and `schemas: ["publiccode"]`.
- Catalogue scopes hold numeric ids: `PublicationService::isObjectInCatalogScope()` runs them through `intval`. The seed cannot know the ids, so `SettingsService::resolveSeededCatalogScopes()` replaces slugs with ids after the import, before `backfillCatalogScopes()`. Without it the backfill would point the empty-looking catalogue at the publication schema.
- `publiccode` gets `authorization.read: ["public"]`, the same rule `theme` and `glossary` carry. `PublicationQueryService::applySchemaScopeReadRuleGuard()` drops every schema without read rules from anonymous searches, so without it the harvested components would be visible to the administrator and to nobody else. Unlike publications there is no `publicationDate` gate: a harvested component is already public on GitHub, and hiding it again serves no one.

## D6. Admin section

`GET /api/settings/publiccode-harvest` answers with `integriq` (installed), `source` (exists, enabled, link), `shards` (how many of the 24 exist), `flow` (uuid, enabled, owner, runAs, cron) and `lastRun` (status, started, finished, error). `POST .../setup` writes the synchronizations, `POST .../enable` adopts and switches the flow on or off, `POST .../run` queues a manual run. All four are admin only. The section lives in `src/views/settings/PubliccodeHarvest.vue`, under `#section-publiccode-harvest`.

## D7. Tests

- `tests/Unit/Settings/PubliccodeHarvestFlowTest.php`: topology (both triggers to every shard, no shard to shard edge, one tail per shard, only `end` shared), shard file and flow agree, ranges contiguous, the flow ships disabled, every node type is one OpenRegister or integriq registers.
- `tests/Unit/Settings/PubliccodeMappingTest.php`: a real `publiccode.yml` (this repository's own) decoded with symfony/yaml, run through OpenRegister's real `MappingService` with the shipped mapping, and validated with opis/json-schema against the `publiccode` properties. Skipped with a named reason where OpenRegister's source is not next to the app.
- `tests/Unit/Service/PubliccodeHarvestServiceTest.php`: inert without integriq, set-up is idempotent by slug, status reads the flow.
- Live on :8095: the flow imports and passes OpenRegister's preflight, a run against a GitHub code search mock creates components, a second run updates them, they are found in search and anonymously through the public API.
