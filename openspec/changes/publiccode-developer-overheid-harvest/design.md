# Design: publiccode-developer-overheid-harvest

## Context

- The GitHub harvest (`openspec/changes/publiccode-github-harvest`) ships a flow on the `publiccode` schema under `x-openregister-flows` in `lib/Settings/register.d/publiccode-github-harvest.json`, the mapping `publiccode-github-hit`, and the seeded catalogue `componenten`. `PubliccodeHarvestService` writes 24 integriq synchronizations on set-up from `lib/Settings/publiccode-github-shards.json` and switches the flow. This change follows that pattern and reuses the catalogue, the schema and the admin service.
- Schema `publiccode` in `lib/Settings/register.d/publiccode-software.json` is at version 0.3.0 (0.2.0 came with the GitHub harvest, 0.3.0 with the catalog detail page fix). It already carries `harvestedFrom`, `harvestedAt`, `rawPubliccode` and `authorization.read: ["public"]`.
- The OSS-register API (`https://api.developer.overheid.nl/oss-register/v1`, test environment `https://api.don.apps.digilab.network/oss-register/v1`):
  - `GET /repositories?publiccode=true&perPage=100&page=N` answers a JSON array of `RepositorySummary` (`id`, `url`, `name`, `shortDescription`, `organisation{uri,label}`, `publicCodeUrl`, `forkType`, `archived`, `createdAt`, `lastCrawledAt`, `lastActivityAt`) with the headers `Link` (single-quoted `rel` values in the published example), `Total-Count`, `Total-Pages`, `Current-Page` and `Per-Page`. `perPage` is at most 100. `archived` defaults to false, which hides archived repositories.
  - `GET /repositories/{id}` adds `publicCode`, the register crawler's parse of the file. It lacks `categories`, `softwareVersion`, `releaseDate`, `logo`, `applicationSuite`, `it` and `legal.repoOwner`.
  - Every request needs `X-Api-Key` or an OAuth2 bearer token; anonymous calls answer 401.
- OpenRegister's `openregister.object-write` with `operation: upsert` patches the matched object (`ObjectService::patchObject()`): a field the payload does not name keeps its value.
- OpenRegister's flow engine broadcasts a step's items to every outgoing edge unless a routing step tagged them, and appends (never assigns) items on a place that several steps feed. A node that fires with no items still passes its token on, so a branch that receives nothing still reaches a join.

## Goals / Non-Goals

**Goals**
- Every repository in the OSS-register with a `publiccode.yml` becomes a searchable `publiccode` component, from the full file where the forge allows it.
- One component per repository across both harvests.
- The register listing is visible and facetable on the component.
- An administrator can run it by hand on an instance without cron.

**Non-Goals**
- The API-register, tombstoning, OAuth2 client credentials, any OpenRegister change, deployment.

## Architecture Overview

```
nightly (15 4 * * *) ─┐
manual ───────────────┴─> list ─> repos ─> locate ─┬─> to-github ───> fetch-github ─> decoded-github ───> map-github ───> write-github ───┐
                                                    ├─> to-gitlab ───> fetch-gitlab ─> decoded-gitlab ───> map-gitlab ───> write-gitlab ───┼─> end (join)
                                                    └─> to-register ─> detail ─> lift ─> decoded-register ─> map-register ─> write-register ┘
```

| Node | Type | Config |
|---|---|---|
| `nightly` | `openregister.trigger-schedule` | `cron: "15 4 * * *"`, `runAs: "admin"` (one hour after the GitHub harvest) |
| `on-demand` | `openregister.trigger-manual` | — |
| `list` | `openconnector.source-paginate` | `synchronization: opencatalogi-publiccode-developer-overheid`, `output: page` |
| `repos` | `openregister.explode` | `path: page.results`, `as: repo`, `keepRecord: false` |
| `locate` | `openregister.map` | mapping `publiccode-developer-overheid-locate` (D2) |
| `to-github`, `to-gitlab`, `to-register` | `openregister.filter` | `{"==": [{"var": "json.locate.forge"}, "github" \| "gitlab" \| "other"]}` |
| `fetch-github` | `openconnector.source-call` | `source: github-raw`, `endpoint: "{{ locate.rawPath }}"`, `method: GET`, `decode: yaml`, `output: publiccode`, `onError: continue` |
| `fetch-gitlab` | `openconnector.source-call` | as `fetch-github` with `source: gitlab-raw` |
| `detail` | `openconnector.source-call` | `source: developer-overheid-oss`, `endpoint: "/repositories/{{ repo.id }}"`, `decode: json`, `output: detail`, `onError: continue` |
| `lift` | `openregister.map` | mapping `publiccode-developer-overheid-detail` (passThrough): `publiccode.body` ← `detail.body.publicCode` |
| `decoded-*` | `openregister.filter` | D4 |
| `map-*` | `openregister.map` | mapping `publiccode-developer-overheid-repository`, `onError: continue` |
| `write-*` | `openregister.object-write` | `operation: upsert`, `register: publication`, `schema: publiccode`, `payloadFrom: component`, `match: {"@self.slug": "{{ component.slug }}"}`, `maxWrites: 5000`, `onError: continue` |
| `end` | `openregister.end` | `join: true` |

Flow-level: `name: "developer.overheid.nl publiccode harvest"`, `app: opencatalogi`, `trigger: schedule`, `enabled: false`, `executionMode: async`, `limits.maxRuntimeMinutes: 60`.

## Decisions

### D1. Fetch the full file from the forge (product decision Q7)

The register's parsed `publicCode` lacks seven fields the schema maps and that the GitHub harvest fills. Writing it over a component would leave those empty for components only the register finds, and would make the two harvests disagree on the same component. So the flow rewrites `publicCodeUrl` into a path on an integriq raw-file source and fetches the file itself: `github-raw` (exists, `https://raw.githubusercontent.com`) for github.com and `gitlab-raw` (new in integriq, `https://gitlab.com`) for gitlab.com. Raw file fetches cost no API quota and need no credential. A repository on any other host takes `GET /repositories/{id}` on `developer-overheid-oss` and maps the register's parsed `publicCode`, so it is still listed, with the fields the register has.

- **Why rewrite into a path and not fetch `publicCodeUrl` directly.** `openconnector.source-call` refuses an absolute URL as endpoint (`FlowConfigGuard::endpointEscapeCode()`): an endpoint stays inside its source, so the host is always one an administrator configured. The rewrite keeps that guarantee.
- **Alternative rejected: a generic "any URL" source.** It would undo the containment guard for every flow.

### D2. Rewrite rules: `publicCodeUrl` → forge and raw path

The `locate` step is an OpenRegister mapping with `passThrough: true` that adds `locate.forge`, `locate.host`, `locate.rawPath`, `locate.filePath` and `locate.repoKey` to the item. The rules, applied in order after the query string and fragment are cut off (`?ref_type=heads`, `#L1`):

| `publicCodeUrl` shape | forge | `rawPath` on the source | `filePath` |
|---|---|---|---|
| `https://github.com/{owner}/{repo}/blob/{ref}/{path}` | github | `/{owner}/{repo}/{ref}/{path}` | `{path}` |
| `https://github.com/{owner}/{repo}/raw/{ref}/{path}` | github | `/{owner}/{repo}/{ref}/{path}` | `{path}` |
| `https://raw.githubusercontent.com/{owner}/{repo}/{ref}/{path}` | github | the URL path unchanged | `{path}`, after a leading `refs/heads/{ref}/` or `refs/tags/{ref}/` |
| `https://gitlab.com/{namespace…}/{project}/-/blob/{ref}/{path}` | gitlab | `/{namespace…}/{project}/-/raw/{ref}/{path}` | `{path}` |
| `https://gitlab.com/{namespace…}/{project}/-/raw/{ref}/{path}` | gitlab | the URL path unchanged | `{path}` |
| anything else, or empty | other | — | — |

`www.` before `github.com` is accepted. `locate.host` is the URL's host (`github.com`, `gitlab.com`, or the other host), lower case, without `www.`. `locate.repoKey` is the register's own `repo.url`, normalised as the slug is (lower case, no scheme, no `www.`, no `.git`, no trailing slash): `github.com/conductionnl/opencatalogi`.

Examples:

| `publicCodeUrl` | forge | `rawPath` | `filePath` |
|---|---|---|---|
| `https://github.com/ConductionNL/opencatalogi/blob/main/publiccode.yml` | github | `/ConductionNL/opencatalogi/main/publiccode.yml` | `publiccode.yml` |
| `https://github.com/example-org/monorepo/blob/main/apps/portal/publiccode.yml` | github | `/example-org/monorepo/main/apps/portal/publiccode.yml` | `apps/portal/publiccode.yml` |
| `https://raw.githubusercontent.com/example-org/app/refs/heads/main/publiccode.yaml` | github | `/example-org/app/refs/heads/main/publiccode.yaml` | `publiccode.yaml` |
| `https://gitlab.com/example-group/sub/project/-/blob/main/publiccode.yml?ref_type=heads` | gitlab | `/example-group/sub/project/-/raw/main/publiccode.yml` | `publiccode.yml` |
| `https://codeberg.org/example-org/app/src/branch/main/publiccode.yml` | other | — | — |
| `https://github.com/example-org/app` (no file path) | other | — | — |

A ref that contains a slash (`feature/x`) is copied into `rawPath` unchanged, which the raw hosts accept; only `filePath`, used for the monorepo part of the slug, may then start with the ref's tail. That affects at most the slug of a monorepo component on a branch with a slash in its name and is accepted.

### D3. Branch with filters side by side, not with a router

`locate` fans out to three filters that each keep one forge. Each filter's branch has its own fetch, map and write steps, and only `end` is shared.

- **Why not `openregister.route`.** A route node tags each item for one output, which is what is wanted, but a route node that declares `exits` sends its token down one exit only (`FlowTokenRouter::takenExits()`), and the join at `end` would wait for the branches that got no token. Filters behind an untagged fan-out get a token each, every time, including when they keep nothing.
- **Why separate tails.** The GitHub harvest measured that separate tails cost nothing and keep a refused write inside its own branch. The engine now appends items on a shared place, but a shared write step would still stop the other branches' page at the first refused item.
- **Why `join: true` on `end`.** An end node stops the whole run; without the join the fastest branch would stop the others.

### D4. What the filters drop

`decoded-github`, `decoded-gitlab` and `decoded-register` keep an item only when all hold, using the GitHub harvest's JSON Logic conditions with `locate.repoKey` in place of the GitHub repository name:

- `publiccode.body.name` and `publiccode.body.url` are set; `name` is text (`lower(name) != "array"`); `url` is text that starts with `http`, contains a dot and no space.
- The file names the repository the register lists, or a repository on another host: keep when `lower(url ~ "/")` contains `repoKey ~ "/"`, or `repoKey ~ ".git"`, `repoKey ~ "#"`, `repoKey ~ "?"`, or when `lower(url)` does not contain `locate.host ~ "/"`. A fork in the register whose file still names the original is dropped, so it never overwrites the original's `developerOverheid` block (product decision Q2 as applied to forks; see Open Questions).

A file that did not decode carries `__error` and no `publiccode` key, and a 404 does the same through `onError: continue`, so both drop here and the run goes on.

### D5. One component per repository (product decision Q2)

Mapping `publiccode-developer-overheid-repository` writes `component.*` with the same field rules as `publiccode-github-hit` (scalars only when scalar, lists of strings, language-keyed descriptions, a web-address `landingURL`, a calendar `releaseDate`, `unsetIfValue==` on every optional field). Differences:

- `slug`: the GitHub rule over `publiccode.body.url` (lower case; no scheme, leading `www.`, `.git`, `#fragment`, `?query`, trailing slash; every character outside `a-z0-9` becomes `-`), plus `-{directory of locate.filePath}` when the normalised url equals `locate.repoKey` and the file is not at the repository root. For a github.com file this is exactly the slug `publiccode-github-hit` gives, so both harvests upsert one object.
- `harvestedFrom`: `locate.host` (`github.com`, `gitlab.com`, or the other host for the fallback). Its meaning stays "the forge host of the file".
- `rawPubliccode`: the decoded file, only on the github and gitlab branches. The fallback branch does not write it: the register's parse is not the file, and patching it over a full file from the GitHub harvest would lose fields.
- `developerOverheid`: `{id, organisation: {label, uri}, registerUrl: "https://oss.developer.overheid.nl/repositories/{id}", lastCrawledAt, forkType, archived}` from `repo`.
- `harvestedAt`: the time of the write.

Because the write patches, `categories`, `softwareVersion` and the other fields the GitHub harvest wrote survive a fallback write, and `developerOverheid` survives a GitHub write, which does not name it.

### D6. Schema `publiccode` 0.4.0, additive

| Property | Type | Notes |
|---|---|---|
| `developerOverheid` | object | `id` (string, uuid), `organisation` (object: `label` string, `uri` string uri), `registerUrl` (string, uri), `lastCrawledAt` (string, date-time), `forkType` (string, enum `TECHNICAL_FORK`, `VARIANT_FORK`, `GIT_FORK`, `URL_MISTAKE`), `archived` (boolean). Not required. |

One field, not two: the GitHub harvest has no source-specific field at all (`harvestedFrom` is its only facet), so the register listing is one object and needs no separate boolean; "is this component in the register" is `developerOverheid` being present. No property is removed, renamed or tightened, and `required` stays `["name", "url"]`, so 0.3.0 objects stay valid.

### D7. The credential (product decision Q8)

The integriq source `developer-overheid-oss` holds no key. Its `configuration.authentication.apikey` is a credential placeholder `{"credentialRef": {"credentialName": "developer-overheid-oss"}}` that integriq resolves through OpenRegister's broker from a `generic-apikey` credential, and its `configuration.headers` send it as `X-Api-Key`. OpenCatalogi only reads whether the source exists and is enabled, exactly as the GitHub section does for `github-api`; it does not look up the credential at all, and links to the integriq source where the administrator enters the key. See integriq `sources-developer-overheid` design for why the header is a template and not the source's `authorizationHeader` field.

### D8. The admin service, generalised (product decision Q9)

`PubliccodeHarvestService` takes a harvest key (`github` or `developer-overheid`, default `github`) and reads a per-harvest definition:

| | `github` | `developer-overheid` |
|---|---|---|
| Flow name | GitHub publiccode harvest | developer.overheid.nl publiccode harvest |
| Definition file | `lib/Settings/publiccode-github-shards.json` (unchanged) | `lib/Settings/publiccode-developer-overheid-sync.json` |
| Source checked | `github-api` | `developer-overheid-oss` |
| Credential checked | — | `developer-overheid-oss` |
| Other sources checked | — | `github-raw`, `gitlab-raw` |
| Synchronizations written | 24, one per size shard | 1: `opencatalogi-publiccode-developer-overheid` |

The developer.overheid.nl definition file holds the synchronization verbatim:

```json
{
  "source": "developer-overheid-oss",
  "credential": "developer-overheid-oss",
  "sources": ["github-raw", "gitlab-raw"],
  "synchronizations": [
    {
      "node": "list",
      "synchronization": "opencatalogi-publiccode-developer-overheid",
      "name": "developer.overheid.nl OSS-register, repositories with a publiccode.yml",
      "sourceConfig": {
        "endpoint": "/repositories",
        "query": {"publiccode": "true", "perPage": 100},
        "resultsPosition": "_root",
        "paginationQuery": "page",
        "maxPages": 10,
        "prefetchConcurrency": 1
      }
    }
  ]
}
```

`maxPages: 10` covers 1,000 repositories, ten times today's count; past that the node raises rather than silently truncating, and the number goes up here. The GitHub harvest's status payload, endpoints and behaviour do not change: its definition is built from the shard file as today.

Status for the new harvest: `integriq`, `source` (`slug`, `exists`, `enabled`, `uuid`), `sources` (`github-raw`, `gitlab-raw`: exists, enabled), `synchronizations` (`expected`, `present`), `flow`, `lastRun` (with `failedSteps`). The section derives one state from it, in this order: needs integriq → needs the source → source switched off → not set up → off → on.

## API Design

All five routes live in `appinfo/routes.php` and map to `DeveloperOverheidHarvestController`, a thin controller over `PubliccodeHarvestService` with harvest key `developer-overheid`.

### `GET /api/settings/developer-overheid-harvest`
**Response 200:** the status of D8, flat.
```json
{
  "integriq": true,
  "source": {"slug": "developer-overheid-oss", "exists": true, "enabled": true, "uuid": "00000000-0000-0000-0000-000000000000"},
  "sources": {"github-raw": {"exists": true, "enabled": true}, "gitlab-raw": {"exists": true, "enabled": true}},
  "synchronizations": {"expected": 1, "present": 1},
  "flow": {"uuid": "00000000-0000-0000-0000-000000000000", "imported": true, "enabled": false, "owner": null, "runAs": "admin", "cron": "15 4 * * *"},
  "lastRun": null
}
```

### `POST /api/settings/developer-overheid-harvest/setup` · `…/enable` · `…/disable` · `…/run`
**Response 200:** `{"created": 1, "updated": 0}` for setup; the flow status for enable and disable; the queued run for run.
**Response 409:** `{"message": "<localised, static>", "error": "needs-integriq" | "needs-openregister" | "flow-not-imported" | "not-set-up" | "switched-off"}`.
**Response 500:** `{"message": "<localised, static>", "error": "harvest-failed"}`; the exception is logged, never returned.

The five invariants of `writing-controllers.md`:

1. **Auth:** every method carries `#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]`. No `#[NoAdminRequired]`, no `#[PublicPage]`, no `#[NoCSRFRequired]` (the frontend calls through `@nextcloud/axios`, which sends the request token).
2. **Envelope:** success returns the flat payload; failure returns `{message, error}` only.
3. **Exception translation:** the service throws `HarvestNotReadyException` (new, `lib/Exception/`, carrying the error slug) for every precondition; the controller maps it to 409. `DoesNotExistException` cannot reach the controller (the service resolves by slug and returns null). A final `catch (\Throwable)` logs and returns the static 500.
4. **Config fail-mode:** the controller and service read no `IAppConfig` key. The one configuration they read, the definition file, fails closed: a missing or invalid file raises, no write happens, and the status says so.
5. **Hardening:** admin-only, so no rate limit is required; no CSRF opt-out on the four POST routes.

## Nextcloud Integration

- Controllers: `DeveloperOverheidHarvestController` (new). `PubliccodeHarvestController` unchanged.
- Services: `PubliccodeHarvestService` (generalised). OpenRegister services are resolved from the container by class name, so OpenCatalogi stays installable without OpenRegister or integriq (ADR-083).
- Mappers/Entities: none.
- Events/Hooks: none. The flow engine owns the schedule; no app cron, no background job.

## Security Considerations

- The API key never enters OpenCatalogi: not in a file it ships, not in a request it makes, not in a response it returns. The status reads only the credential's existence by name.
- All endpoints admin-only; see the five invariants above.
- Outbound calls go only to the locations of three integriq sources an administrator controls; the endpoint guard refuses an absolute URL, so a `publicCodeUrl` cannot steer a call to another host.
- YAML is decoded by integriq's `ResponseDecoder` without object, constant or custom tags.
- Harvested components are public (`authorization.read: ["public"]`). They describe public repositories listed in a public register.

## NL Design System

The section reuses `PubliccodeHarvest.vue`, made prop-driven (endpoint base, title strings, which checks to show, the credential hint), so the GitHub section renders exactly as before. Nextcloud Vue components (`NcSettingsSection`, `NcNoteCard`, `NcButton`, `NcLoadingIcon`) as in the GitHub section; status icons carry a text label next to them (WCAG 1.4.1); every action is a real button reachable by keyboard; errors are announced through `NcNoteCard` with `type="error"`.

## File Structure

```
lib/
  Controller/DeveloperOverheidHarvestController.php      new
  Exception/HarvestNotReadyException.php                 new
  Service/PubliccodeHarvestService.php                   changed
  Settings/
    publiccode-developer-overheid-sync.json              new
    register.d/publiccode-developer-overheid-harvest.json  new (flow, 3 mappings)
    register.d/publiccode-software.json                  changed (0.4.0)
    opencatalogi_mock_register.json                      changed (demo data)
appinfo/routes.php                                       changed
src/views/settings/PubliccodeHarvest.vue                 changed (props)
src/views/settings/Settings.vue                          changed (second section)
l10n/en.*, l10n/nl.*                                     changed
docs/handleidingen/Publiccode.md                         changed
tests/Unit/Settings/DeveloperOverheidHarvestFlowTest.php new
tests/Unit/Settings/DeveloperOverheidMappingTest.php     new
tests/Unit/Service/PubliccodeHarvestServiceTest.php      changed
tests/Unit/Controller/DeveloperOverheidHarvestControllerTest.php new
tests/fixtures/developer-overheid/                       new (RepositorySummary, RepositoryDetail)
```

## Seed Data

Per ADR-001 the change touches schema `publiccode`, so its demo objects carry the new fields. The demo objects live in `lib/Settings/opencatalogi_mock_register.json`, which is imported on demand only (`x-openregister.type: mock`), so no example component reaches a production catalogue. Two of the three existing `publiccode` demo objects get `developerOverheid`, and the register's version goes up so the import updates them.

### Schema: `publiccode`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| `@self.slug` | `publiccode-voorbeeld-name-1-1` (grandfathered) | `publiccode-voorbeeld-name-2-2` (grandfathered) | `publiccode-voorbeeld-name-3-3` (grandfathered) |
| `harvestedFrom` | `github.com` | `gitlab.com` | `github.com` |
| `developerOverheid.id` | `00000000-0000-0000-0000-000000000001` | `00000000-0000-0000-0000-000000000002` | absent |
| `developerOverheid.organisation` | `{label: "Example: Gemeente Voorbeeldstad", uri: "https://identifier.example.invalid/organisation/1"}` | `{label: "Example: Waterschap Voorbeeld", uri: "https://identifier.example.invalid/organisation/2"}` | absent |
| `developerOverheid.registerUrl` | `https://oss.developer.overheid.nl/repositories/00000000-0000-0000-0000-000000000001` | `…/00000000-0000-0000-0000-000000000002` | absent |
| `developerOverheid.lastCrawledAt` | `2026-10-15T02:00:00Z` | `2026-10-14T02:00:00Z` | absent |
| `developerOverheid.forkType` | absent | `VARIANT_FORK` | absent |
| `developerOverheid.archived` | `false` | `false` | absent |

The slugs and names predate the ADR-001 marker rule and keep their `voorbeeld` form: renaming them would make the importer create duplicates. The organisation labels carry the `Example:` prefix.

**Related items per object:** none. A component has no files, notes, tasks or contacts in OpenCatalogi; its maintainers are part of the object.

Integriq's sources are seed data of the integriq change, not of this one.

## Declarative-vs-imperative decision

Per ADR-031, behaviour that OpenRegister can express as configuration is declared, not coded:

- **Declarative (register fragment):** the flow (nodes, edges, triggers, schedule, error policy), the three mappings (`-locate`, `-detail`, `-repository`), the filter conditions, the schema fields and their facet. These land as a schema-register patch in `lib/Settings/register.d/`.
- **Declarative (data file):** the synchronization definition `publiccode-developer-overheid-sync.json`.
- **Imperative:** `PubliccodeHarvestService` and the controller, like the precedent. The synchronization cannot be seeded through `components.objects`, because OpenRegister skips an object whose register (integriq) is absent at import and never creates it later; it is written on the administrator's Set up. Adopting and switching a flow, queueing a run and reading the status across integriq and OpenRegister have no declarative form.

## Mixed-spec rationale

This change is `kind: mixed`, like its precedent `publiccode-github-harvest`, and not under the thin-glue exception (the service, controller and section are well over 20 lines). It follows the precedent for the reason the precedent was mixed: the declarative half cannot be exercised on its own. The flow names a synchronization that only the imperative half writes, so a config-only change would ship a flow nobody can set up or run, and its acceptance (a run writes components, a second run updates them) could not be verified until the code change lands. The schema fields, the mappings and the flow are consumed only by that flow.

The split chain, if review prefers it per ADR-032:

1. `publiccode-developer-overheid-harvest-schema-declaration` (`kind: config`): schema 0.4.0, the flow and mappings, the synchronization definition, demo data. Verified with a hand-made synchronization on a dev instance.
2. `publiccode-developer-overheid-harvest` (`kind: code`, `depends_on` 1 and integriq `sources-developer-overheid`): the generalised service, controller, routes, section, l10n, manual.

## Tests

- `DeveloperOverheidHarvestFlowTest`: the flow ships disabled under the name the service finds; both triggers lead to `list` only; `locate` has exactly three outgoing edges, to the three forge filters; each branch's steps form one chain with no edge between branches; only `end` has more than one way in and it joins; every node type is one OpenRegister or integriq registers; the `list` node names the synchronization in the definition file; the forge and `decoded-*` filters, run through OpenRegister's JSON Logic, keep and drop the cases of D4.
- `DeveloperOverheidMappingTest`: the locate mapping over every example of D2; the repository mapping over a fixture RepositorySummary plus this repository's own `publiccode.yml`, run through OpenRegister's real `MappingService` and validated against the 0.4.0 properties; the slug equals what `publiccode-github-hit` gives for the same file; the fallback mapping over a fixture RepositoryDetail writes no `rawPubliccode`. Skipped with a named reason where OpenRegister's source is not next to the app, as the precedent does.
- `PubliccodeHarvestServiceTest`: per harvest key, inert without integriq, set-up idempotent by slug, the credential state, the GitHub status unchanged.
- `DeveloperOverheidHarvestControllerTest`: the 409 slugs, the static 500, no exception text in a response.
- Live on a dev instance against integriq's mock of the register: set up, switch on, run now; components written; a second run updates; a 404 and a broken file in the page are skipped; a component found by both harvests is one object with both blocks.

## Risks / Trade-offs

- [The list endpoint refuses the API key] → integriq switches the source to a `generic-oauth2` credential; nothing in OpenCatalogi changes. Checked in the first live task.
- [Two harvests patch one object] → identical slug rule, enforced by a shared expectation in both mapping tests; fields only one harvest writes survive the other.
- [A fork's file names the original] → dropped by the filter; the fork is not listed as its own component until its file names itself.
- [`developerOverheid` stays on a component the register drops] → accepted; tombstoning is out of scope (proposal).
- [Archived repositories are hidden by the API's default] → accepted: the harvest lists what the register shows by default; `developerOverheid.archived` records the flag for when the register shows one.

## Migration Plan

No database migration. Deploy: update OpenCatalogi and integriq, reimport OpenCatalogi's configuration (the flow arrives disabled), create the `generic-apikey` credential `developer-overheid-oss` with `openconnector` in its allowed apps, enable the integriq source, then Set up, Switch on and Run now in the admin section. Rollback: switch the flow off; revert the change.

## Open Questions

- Whether `GET /repositories` accepts `X-Api-Key` (proposal Risk 1).
- Whether a fork listed in the register should become its own component even when its file names the original (D4 drops it today).
- Self-hosted and other forges (self-hosted GitLab, Gitea/Forgejo incl. codeberg.org, Bitbucket, Azure DevOps, code.overheid.nl): today they take the detail fallback; whether any needs its own raw source is to be asked or decided after the first live run shows the host distribution (proposal Open Questions).
