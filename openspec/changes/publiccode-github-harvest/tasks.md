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

## 7. After the first real crawl (WOO-585, 2026-10-01)

- [x] 7.1 `releaseDate` is taken only when it is a date; `${RELEASE_DATE}` no longer fails the run.
- [x] 7.2 The filter keeps only files named `publiccode.yml`; structured fields are taken only when structured; every `write-NN` has `onError: continue`.
- [x] 7.3 The slug derives from the `url` in the file; a copy in another GitHub repository is dropped by the filter.
- [x] 7.4 Tests for 7.1–7.3 in `PubliccodeMappingTest` and `PubliccodeHarvestFlowTest`; spec scenarios under REQ-PGH-003 and REQ-PGH-004.
- [x] 7.5 Live on the WOO-585 rig against real GitHub: 24 of 24 shards, 698 components, a second run updates them.

## 8. Review leftovers of opencatalogi#1711 (WOO-585, 2026-10-02)

- [x] 8.1 `map-NN` has `onError: continue`; the shard filter drops a `url` that is not a web address and a `name` that is not text.
- [x] 8.2 The copy filter normalises like the slug: `.git`, `#fragment` and `?query` on the repository's own url keep the original.
- [x] 8.3 The mapping shapes every value for its column (scalars, lists of strings, `description`, `landingURL`, a calendar `releaseDate`); a leading `www.` is stripped only at the start of the url.
- [x] 8.4 `PubliccodeHarvestService` reports the failed steps of the last run; the admin section shows the count.
- [x] 8.5 design.md describes the url-based slug and the shard-granular write; spec scenarios and tests cover 8.1–8.3.
