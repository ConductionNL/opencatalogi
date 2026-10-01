---
kind: mixed
depends_on: []
---

# Proposal: publiccode-github-harvest

## Why

Rotterdam set OpenCatalogi up to answer one question: which public code exists, anywhere on GitHub, that we could reuse? The old Common Gateway version answered it by reading every `publiccode.yml` on GitHub each night. The Nextcloud version does not.

- `lib/Settings/register.d/publiccode-software.json` ships the `publiccode` schema and says it is "harvested from forge repositories by the publiccode-harvest flow". No repository ships that flow.
- The parity row `svc-software` in `openspec/parity/capabilities.json` says the same: "Only the schema exists. Nobody has shown a harvest that reads publiccode.yml files into it."
- `docs/handleidingen/Publiccode.md` tells readers "OpenCatalogi scant GitHub elke nacht". That describes the Common Gateway version.
- A harvest was built by hand on 2026-08-13 for a benchmark and never shipped. It taught two things this change keeps: shard nodes chained one after another multiplied 12 runs into 3,472, and a shard refused by the rate limit used to end the run, so the next run repeated the completed shards forever.

## What changes

1. **The flow.** OpenCatalogi ships a flow on the `publiccode` schema (`x-openregister-flows`). A nightly schedule trigger and a manual trigger each fan out to 24 shard nodes, side by side, never chained. Each shard runs one GitHub code search for `filename:publiccode.yml` within a file size range, so no query passes GitHub's cap of 1,000 results. Every hit is fetched from `raw.githubusercontent.com`, decoded from YAML, mapped onto `publiccode` and written with a slug derived from the repository, so a second harvest updates the object instead of adding a copy.
2. **The shard definitions.** One fetch definition per shard (an integriq synchronization) ships as a data file. OpenCatalogi writes them into integriq when the administrator sets the harvest up, and never before.
3. **The mapping.** An OpenRegister mapping, shipped in the register fragment, turns a decoded `publiccode.yml` into a `publiccode` object. It keeps the decoded file in `rawPubliccode` and stamps `harvestedFrom` and `harvestedAt`.
4. **The admin section.** A "GitHub harvest" section in OpenCatalogi's admin settings shows whether integriq, its GitHub source and the flow are ready, sets the harvest up, switches it on, runs it now and shows the last run. It links to integriq's source page for the token. OpenCatalogi never sees or stores the token.
5. **The Componenten catalogue.** A seeded catalogue over the `publiccode` schema, so harvested components appear in OpenCatalogi's search and public API with no further configuration. The `publiccode` schema gets an explicit public read rule, because a schema without read rules is dropped from every anonymous search.
6. **The manual.** `docs/handleidingen/Publiccode.md` describes this harvest, its set-up and its limits.

## What integriq provides (lane iq, `feature/sources-github-publiccode`)

- Sources `github-api` (code search, token through the credential broker under the name `github-publiccode`) and `github-raw` (file fetch, no token).
- `decode: "yaml"` on `openconnector.source-call`.
- A rate-limited page suspends the run until `X-RateLimit-Reset` instead of ending it.

OpenCatalogi names these by slug and never ships a copy of them.

## Related changes

- `harvest-feed-intake`, `harvest-protocol-plugins`, `harvest-conflict-policies`, `harvest-observability` (the re-scoped `dcat-oai-pmh-harvesting` umbrella) cover DCAT, OAI-PMH, CKAN and schema.org feeds that an administrator registers one by one. This change harvests one fixed corpus into one fixed schema and registers no feed. It follows the same rules those slices set: the OpenRegister flow engine owns the schedule, no app cron, no app scheduler, and the fetch runs through integriq when it is installed. When `harvest-feed-intake` lands, its feed model can adopt this flow as a feed of protocol `publiccode-github` without changing the flow.
- `the-public-and-community-surface` and `published-service-and-case-type-catalogue` seed catalogues the same way.

## Out of scope

- GitLab, Codeberg and other forges. `harvestedFrom` is ready for them.
- Removing components that disappear from GitHub. A component whose `harvestedAt` stops moving is one the harvest no longer finds; nothing is deleted.
- The authenticated full crawl against github.com. It needs Ruben's token and is left as a named open task.

## Impact

- New: `lib/Settings/register.d/publiccode-github-harvest.json`, `lib/Settings/publiccode-github-shards.json`, `lib/Service/PubliccodeHarvestService.php`, `lib/Controller/PubliccodeHarvestController.php`, `src/views/settings/PubliccodeHarvest.vue`.
- Changed: `publiccode-software.json` (public read rule, version 0.2.0), `SettingsService` (seeded catalogue scope by slug), `Settings.vue`, `routes.php`, `l10n`, the manual.
- No migration. Without integriq nothing runs and nothing is written outside OpenCatalogi.

## Rollback

Revert the change. The flow stays in OpenRegister's flow store, disabled. The shard synchronizations stay in integriq and do nothing without the flow. Harvested `publiccode` objects stay and can be deleted from the schema.
