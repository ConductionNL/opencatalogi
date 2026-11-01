# Tasks: operations-public-api-cache-headers

## 1. The helper

- [x] 1.1 Add `AnswersCacheably::cacheableForCaller()` with the signed-in, anonymous, 304 and error paths (REQ-PAC-001, REQ-PAC-002). Verify: `tests/Unit/Controller/AnswersCacheablyTest.php`, one case per path. Done: `lib/Controller/AnswersCacheably.php`, 8/8.

## 2. Apply it

- [x] 2.1 Wrap the answers of `PublicationsController::index()`, `show()` and `attachments()` (REQ-PAC-001). Verify: controller tests assert the headers for an anonymous and a signed-in call.
- [x] 2.2 Wrap `SearchController::index()` and `show()` (REQ-PAC-001). Verify: same.
- [x] 2.3 Wrap `FederationController::publication()` and `publicationAttachments()` (REQ-PAC-001): the portal (portaliq) reads a publication through these routes on every page view, with `_extend=@self.schema`, so an unchanged answer should come back as a 304 instead of carrying the schema again. Verify: `tests/Unit/Controller/FederationControllerTest.php` asserts the public headers, the 304 and the private answer. Done: 4 tests.

## 3. The setting

- [x] 3.1 Add `public_api_cache_seconds` to `SettingsService` and the admin settings screen (REQ-PAC-003). Verify: `tests/Unit/Service/SettingsServiceTest.php`. Done: `SettingsService` default 60 + allowlist + clamp to an integer from 0 to 3600 (`testThePublicCacheTimeDefaultsToSixtySeconds`, `testTheAdministratorSetsThePublicCacheTime`), `src/views/settings/Settings.vue` publishing options number field.
- [ ] 3.2 Run `tests/e2e/public-api-cache.spec.ts` against a live instance: it sets `public_api_cache_seconds` to 0 and sees no public header (REQ-PAC-003). The spec is written; it has not run, because it needs a live instance.

## 4. Docs and strings

- [x] 4.1 Document the CDN setup, the `Vary` header and the withdrawal delay in `docs/`, and add the strings to `l10n/` in English and Dutch. Verify: `npm run lint` and the l10n check. Done: `docs/features/public-api-cache.md` (CDN setup, `Vary`, withdrawal delay), en/nl strings, check:l10n clean.
