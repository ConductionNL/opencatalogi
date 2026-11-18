# Tasks: search

## 1. Backend — Dead Code Removal (REQ-SCH-011)

> Not built, superseded. The premise ("no registered routes") no longer holds:
> `appinfo/routes.php` now routes `/api/search/{id}`, `/attachments`, `/download`,
> `/uses` and `/used` to these methods, and later changes (federation spec FED-002..006,
> `search-cors-on-every-public-endpoint`) specify and harden them. Removing them would
> delete live, specified endpoints. 1.7 additionally needs a live Nextcloud instance.

- [ ] 1.1 Remove `show(string $id)` method from `lib/Controller/SearchController.php`
- [ ] 1.2 Remove `attachments(string $id)` method from `lib/Controller/SearchController.php`
- [ ] 1.3 Remove `download(string $id)` method from `lib/Controller/SearchController.php`
- [ ] 1.4 Remove `uses(string $id)` method from `lib/Controller/SearchController.php`
- [ ] 1.5 Remove `used(string $id)` method from `lib/Controller/SearchController.php`
- [ ] 1.6 Verify `SearchController` now contains only `__construct()` and `index()` as public methods
- [ ] 1.7 Run `GET /api/search` against a local Nextcloud instance and confirm HTTP 200 with results

## 2. Backend — Spec Traceability (ADR-003)

- [x] 2.1 Add file-level `@spec openspec/changes/search/tasks.md` PHPDoc tag to `lib/Controller/SearchController.php`
- [x] 2.2 Add `@spec openspec/changes/search/tasks.md#task-2.2` tag to `SearchController::index()`
- [x] 2.3 Add `@spec openspec/changes/search/tasks.md#task-2.3` tag to `PublicationService::index()` in `lib/Service/PublicationService.php`
- [x] 2.4 Add `@spec openspec/changes/search/tasks.md#task-2.4` tag to `PublicationService::getAggregatedPublications()` in `lib/Service/PublicationService.php`

## 3. Backend — Auth Annotation Verification (ADR-005)

> 3.1 and 3.2 are superseded: `add-public-fulltext-search` (WOO-506) made `index()`
> `#[PublicPage]` + `#[NoCSRFRequired]` with an anonymous rate limit, and
> `search-cors-on-every-public-endpoint` (REQ-SCO-001) added the OPTIONS preflight
> routes. Left unticked because the stated conditions are deliberately false now.

- [ ] 3.1 Confirm `SearchController::index()` does NOT carry `#[PublicPage]` or `#[NoCSRFRequired]` (authenticated, CSRF-protected)
- [ ] 3.2 Confirm there is no OPTIONS route for `/api/search` in `appinfo/routes.php`
- [x] 3.3 Confirm `SearchController` has the correct Nextcloud default (admin-only) or explicit `#[NoAdminRequired]` annotation — whichever matches the actual access requirement; update if mismatched

## 4. Frontend — i18n Audit (ADR-007 / CLAUDE.md l10n rules)

- [x] 4.1 Run `npm run find:unwrapped -- src/views/SearchIndex.vue` and list all bare prose candidates
- [x] 4.2 Wrap each identified bare string in `SearchIndex.vue` with `t('opencatalogi', '...')`
  - The view is now `src/views/search/FederationSearch.vue`; `find:unwrapped` reports 0 template candidates (the `--include-script` hits are comments), so nothing needed wrapping.
- [x] 4.3 Run `npm run find:unwrapped -- src/components/SearchResults.vue` and list all bare prose candidates
- [x] 4.4 Wrap each identified bare string in `SearchResults.vue` with `t('opencatalogi', '...')`
- [x] 4.5 Run `npm run find:unwrapped -- src/components/SearchSideBar.vue` and list all bare prose candidates
- [x] 4.6 Wrap each identified bare string in `SearchSideBar.vue` with `t('opencatalogi', '...')`
- [x] 4.7 Run `npm run find:unwrapped -- src/components/FacetComponent.vue` and list all bare prose candidates
- [x] 4.8 Wrap each identified bare string in `FacetComponent.vue` with `t('opencatalogi', '...')`

## 5. Frontend — Translation Key Registration (CLAUDE.md l10n rules)

- [x] 5.1 Run `node scripts/l10n-ai.js list-locales` to confirm available locales (at minimum `en` and `nl`)
- [x] 5.2 For each string wrapped in tasks 4.2, 4.4, 4.6, 4.8: run `node scripts/l10n-ai.js has "<key>"` to check if the key already exists
- [x] 5.3 For each missing key: run `node scripts/l10n-ai.js add "<key>" --value en="<English text>" --value nl="<Nederlandse tekst>"` to register it in all locales
- [x] 5.4 Run `npm run check:l10n` and confirm zero MISSING and zero UNWRAPPED entries for the four search components

## 6. Quality Gate Verification (ADR-005, ADR-009, ADR-014, ADR-015)

- [x] 6.1 Run `grep -rL 'SPDX-License-Identifier' lib/Controller/SearchController.php` — add SPDX header if missing
- [x] 6.2 Run `grep -rL 'SPDX-License-Identifier' lib/Service/PublicationService.php` — add SPDX header if missing
- [x] 6.3 Run `grep -rL 'SPDX-License-Identifier' src/views/SearchIndex.vue src/components/SearchResults.vue src/components/SearchSideBar.vue src/components/FacetComponent.vue` — add headers to any missing
- [x] 6.4 Verify no `var_dump`, `die`, `error_log`, or `print_r` calls remain in edited PHP files
- [x] 6.5 Run `npm run check:l10n` — must exit 0 with no MISSING or UNWRAPPED
  - Exits 0. Two scanner false positives fixed in `scripts/check-l10n.js`: icon-name attributes (`actionIconName="Delete"`) are identifiers, not prose; and a server-side key split as `'a' . 'b'` is joined back before it is matched.

## 7. Unit Tests (ADR-008)

> Superseded with section 1: the five methods stay routed, so their tests stay (7.1),
> and a "only `index()` is public" test (7.2) would assert the opposite of the routes.
> `index()` now delegates to `PublicationQueryService::assemblePublicSearchResults()`,
> not `PublicationService::index()`; that delegation with the request parameters is
> already asserted by `testIndexReturnsAssembledSearchResultsForAnonymousCallers` (7.3).

- [ ] 7.1 Update `tests/Unit/Controller/SearchControllerTest.php` to remove test cases for `show()`, `attachments()`, `download()`, `uses()`, and `used()` (if present)
- [ ] 7.2 Add a test asserting `SearchController` only exposes `index()` as a public API method
- [ ] 7.3 Add a test for `SearchController::index()` verifying it delegates to `PublicationService::index()` with the request parameters
