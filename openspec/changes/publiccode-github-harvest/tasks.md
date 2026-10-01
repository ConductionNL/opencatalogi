# publiccode-github-harvest tasks

## 1. Configuration

- [ ] 1.1 Write `lib/Settings/publiccode-github-shards.json` with 24 contiguous size ranges.
- [ ] 1.2 Write `lib/Settings/register.d/publiccode-github-harvest.json`: the flow under `x-openregister-flows`, mapping `publiccode-github-hit`, catalogue `componenten`.
- [ ] 1.3 Give `publiccode` a public read rule and version 0.2.0 in `publiccode-software.json`.
- [ ] 1.4 Resolve seeded catalogue scopes by slug in `SettingsService` before the backfill.

## 2. Admin section

- [ ] 2.1 `lib/Service/PubliccodeHarvestService.php`: status, set up, switch on and off, run now.
- [ ] 2.2 `lib/Controller/PubliccodeHarvestController.php` and four admin-only routes.
- [ ] 2.3 `src/views/settings/PubliccodeHarvest.vue` under `#section-publiccode-harvest` in `Settings.vue`.
- [ ] 2.4 Strings in `l10n/en` and `l10n/nl`.

## 3. Tests

- [ ] 3.1 `tests/Unit/Settings/PubliccodeHarvestFlowTest.php`.
- [ ] 3.2 `tests/Unit/Settings/PubliccodeMappingTest.php` with this repository's `publiccode.yml`.
- [ ] 3.3 `tests/Unit/Service/PubliccodeHarvestServiceTest.php`.

## 4. Documentation

- [ ] 4.1 Rewrite `docs/handleidingen/Publiccode.md`.
- [ ] 4.2 Update the parity row `svc-software` in `openspec/parity/capabilities.json`.

## 5. Live on :8095

- [ ] 5.1 The flow imports and passes OpenRegister's preflight.
- [ ] 5.2 A run against the GitHub code search mock creates components; a second run updates them.
- [ ] 5.3 A broken file in a page is skipped and the rest is written.
- [ ] 5.4 A rate-limited search suspends the run and resumes the refused shard only.
- [ ] 5.5 Components are found in OpenCatalogi search and anonymously through the public API.

## 6. Waiting on others

- [ ] 6.1 Authenticated full crawl against github.com with Ruben's token (coordinator).
- [ ] 6.2 OpenRegister's `github` credential provider allows `GET /search/code` (coordinator, see lane iq).
