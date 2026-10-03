# Tasks: operations-public-api-cache-headers

## 1. The helper

- [ ] 1.1 Add `AnswersCacheably::cacheable()` with the signed-in, anonymous, 304 and error paths (REQ-PAC-001, REQ-PAC-002). Verify: `tests/Unit/Controller/AnswersCacheablyTest.php`, one case per path.

## 2. Apply it

- [ ] 2.1 Wrap the answers of `PublicationsController::index()`, `show()` and `attachments()` (REQ-PAC-001). Verify: controller tests assert the headers for an anonymous and a signed-in call.
- [ ] 2.2 Wrap `SearchController::index()` and `show()` (REQ-PAC-001). Verify: same.

## 3. The setting

- [ ] 3.1 Add `public_api_cache_seconds` to `SettingsService` and the admin settings screen (REQ-PAC-003). Verify: `tests/Unit/Service/SettingsServiceTest.php` and `tests/e2e/public-api-cache.spec.ts` sets 0 and sees no public header.

## 4. Docs and strings

- [ ] 4.1 Document the CDN setup, the `Vary` header and the withdrawal delay in `docs/`, and add the strings to `l10n/` in English and Dutch. Verify: `npm run lint` and the l10n check.
