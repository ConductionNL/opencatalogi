---
kind: mixed
depends_on:
  - sources-developer-overheid # integriq: sources developer-overheid-oss and gitlab-raw, mock server
---

# Proposal: publiccode-developer-overheid-harvest

## Summary

OpenCatalogi gets a second publiccode harvest. It reads the OSS-register of developer.overheid.nl (`https://api.developer.overheid.nl/oss-register/v1`), the national list of open-source repositories that Dutch government organisations publish, takes every repository that has a `publiccode.yml`, fetches the full file from the forge it lives on and writes it as a `publiccode` component. A repository that the GitHub harvest also finds becomes the same component, not a second one: both harvests derive the slug from the `url` in the file, and the write patches. The register's own facts (its id, the publishing organisation, when it last crawled the repository, the fork type) are kept on the component in a new field, so a reader can see and filter which components the government register lists. An administrator sets the harvest up, switches it on and runs it from a new section in OpenCatalogi's admin settings, next to the GitHub harvest.

## Motivation

Jira: WOO-592.

- The GitHub harvest (`publiccode-github-harvest`) finds every `publiccode.yml` that GitHub's code search indexes. It does not know which of those belong to a government organisation, and it misses everything outside GitHub. The OSS-register answers both: it is curated per organisation and it lists repositories on gitlab.com as well.
- On 2026-10-16 the register listed 92 repositories with a `publiccode.yml`. That is one page of the API, so a nightly read costs one list request plus one file fetch per repository.
- The target instance has no cron. The harvest therefore has to be startable by hand from the admin settings, as the GitHub harvest is; the nightly schedule is there for instances that do run cron.
- The register requires an API key on every request. Each administrator requests their own read-only key at https://apis.developer.overheid.nl/apis/key-aanvragen. The key belongs in the credential broker, never in OpenCatalogi.

## Affected Projects

- [ ] Project: `opencatalogi` — the harvest flow and its mappings (register fragment), schema `publiccode` 0.4.0 with the register listing fields, the synchronization definition, a generalised `PubliccodeHarvestService`, a thin controller with five admin routes, the admin section, demo data, the manual.
- [ ] Project: `integriq` — sources `developer-overheid-oss` and `gitlab-raw`, a page count for a one-page listing, a mock of the OSS-register (change `sources-developer-overheid`, a dependency of this one).
- [ ] Project: `openregister` — no change. The credential is an ordinary `generic-apikey` credential; the flow nodes and the upsert-as-patch already exist.

## Scope

### In Scope

- A flow "developer.overheid.nl publiccode harvest" on the `publiccode` schema, shipped disabled, with a nightly and a manual trigger.
- Reading the register's `GET /repositories?publiccode=true` page by page through one integriq synchronization that OpenCatalogi writes on set-up.
- Fetching each repository's full `publiccode.yml` from `github-raw` (github.com) or `gitlab-raw` (gitlab.com), with the register's `publicCodeUrl` rewritten into a path on that source; a repository on any other host falls back to the register's detail endpoint and its parsed `publicCode`.
- One component per repository, shared with the GitHub harvest: same slug derivation, upsert as patch, `harvestedFrom` the forge host of the file.
- Schema `publiccode` 0.3.0 → 0.4.0, additive: one object `developerOverheid` with the register listing. The GitHub harvest has no source-specific field (only `harvestedFrom`, `harvestedAt`, `rawPubliccode`), so this harvest adds one field and no separate flag.
- An admin section "developer.overheid.nl harvest" with status, Set up, Switch on, Switch off and Run now, and the states "needs integriq", "needs the source" and "source switched off", checked the same way the GitHub harvest section checks its source; the key itself is entered on the integriq source, to which the section links.
- The manual `docs/handleidingen/Publiccode.md` describes the second source and where an administrator requests the key.

### Out of Scope

- The API-register of developer.overheid.nl (322 APIs). It describes APIs, not software components, and maps onto another schema.
- Removing or marking components that leave the register. `developerOverheid` stays on a component the register no longer lists; tombstoning waits for the harvest's move onto an OpenRegister `Source` with sync records, as in the GitHub harvest.
- OAuth2 client credentials against `auth.developer.overheid.nl`. The read-only API key is enough for the endpoints this harvest calls (see Open Questions).
- Any OpenRegister change, and deployment to any instance.

## Approach

The pattern is the GitHub harvest's: OpenCatalogi ships a disabled flow plus OpenRegister mappings in a register fragment, integriq does every outbound call, and a small admin service writes the synchronization and switches the flow. The flow lists the register, explodes the page into one item per repository, classifies each by forge in a mapping step, and fans out into three branches side by side (github.com, gitlab.com, other host), each with its own fetch, filter, map and write steps, joined at the end. Details, the rewrite rules and the decisions are in design.md.

## Capabilities

- New: `publiccode-developer-overheid-harvest` (`specs/publiccode-developer-overheid-harvest/spec.md`).

## New Dependencies

- External service: the developer.overheid.nl OSS-register API, read-only, with an API key per installation. Reached only through integriq.
- No new PHP or npm packages.

## Impact

- New: `lib/Settings/register.d/publiccode-developer-overheid-harvest.json` (flow, mappings), `lib/Settings/publiccode-developer-overheid-sync.json` (the synchronization definition), `lib/Controller/DeveloperOverheidHarvestController.php`, `lib/Exception/HarvestNotReadyException.php`, tests.
- Changed: `lib/Settings/register.d/publiccode-software.json` (0.4.0), `lib/Service/PubliccodeHarvestService.php` (per-harvest definitions; the GitHub harvest keeps its behaviour and its endpoints), `appinfo/routes.php`, `src/views/settings/PubliccodeHarvest.vue` (driven by props), `src/views/settings/Settings.vue`, `lib/Settings/opencatalogi_mock_register.json`, `l10n/`, `docs/handleidingen/Publiccode.md`.
- New API: `GET /api/settings/developer-overheid-harvest` and `POST …/setup`, `…/enable`, `…/disable`, `…/run`, admin only.
- No database migration. Without integriq nothing runs and nothing is written.

## Cross-Project Dependencies

- Depends on integriq change `sources-developer-overheid`: the sources `developer-overheid-oss` and `gitlab-raw` must exist before the flow can run. `github-raw` already exists (integriq `sources-github-publiccode`).
- Shares the `publiccode` schema, the slug derivation and the Componenten catalogue with `publiccode-github-harvest`. A change to the slug rule in one mapping MUST be made in the other.

## Risks

### Risk 1: The list endpoint may refuse an API key
**Severity:** High — **Mitigation:** the published OpenAPI declares only OAuth2 client credentials on `GET /repositories`, while the detail endpoint explicitly allows the API key and the 401 answer names both `Authorization` and `x-api-key`. The first task with a real key verifies the list endpoint. If it refuses the key, the integriq source switches to a `generic-oauth2` credential (token URL and scope are public); OpenCatalogi does not change.

### Risk 2: Two harvests write one object
**Severity:** Medium — **Mitigation:** both mappings derive the slug the same way and the write patches, so a field one harvest does not write survives the other. `rawPubliccode` and the publiccode fields are written by whichever harvest ran last; both read the same file from the same forge, so they agree. A shared slug test fixture keeps the two mappings in step.

### Risk 3: A `publicCodeUrl` shape the rewrite does not know
**Severity:** Medium — **Mitigation:** an unknown shape is classified "other" and takes the detail-endpoint fallback, so the repository is still written, from the register's parsed copy. The rewrite rules are tested against the URL shapes of the live register.

### Risk 4: A fork maps onto the original's slug
**Severity:** Low — **Mitigation:** the filter drops a repository whose file names another repository than the register's own `url` on the same forge, the rule the GitHub harvest uses for copies.

## Rollback Strategy

Revert the change. The flow stays in OpenRegister's flow store, disabled; the synchronization stays in integriq and does nothing without the flow. Components keep their `developerOverheid` data, which schema 0.3.0 ignores as an unknown property (`hardValidation: false`); they can be deleted from the schema or the field cleared.

## Open Questions

- Does `GET /repositories` accept `X-Api-Key`, given that the OpenAPI document lists only OAuth2 for it? To be checked with the first real key (Risk 1).
- Does the register answer a page past the last one with an empty list or with an error? integriq's change makes a one-page listing stop after one request, so today's 92 repositories never reach that question.
- Self-hosted and other forges: the rewrite knows github.com and gitlab.com only; every other host falls back to the register's parsed `publicCode` (no `rawPubliccode`, fewer fields). To ask or decide later whether these need a raw source of their own: self-hosted GitLab instances (`gitlab.<organisation>.nl`), Gitea/Forgejo (including codeberg.org), Bitbucket, Azure DevOps, and the government code platform code.overheid.nl, which the register's own site starts to show (an icon for repositories hosted there). Count them in the first live run's host distribution.
