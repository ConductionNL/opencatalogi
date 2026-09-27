# Tasks: search-cors-on-every-public-endpoint

## 1. Search and federation

- [ ] 1.1 Use `AnswersCrossOriginRequests` in `SearchController`, add the headers and `preflightedCors()`, and the six OPTIONS routes (REQ-SCO-001). Verify: `tests/Unit/Controller/SearchControllerTest.php` asserts the headers for an allowed and a refused origin; a Newman request sends an OPTIONS preflight.
- [ ] 1.2 Same for `FederationController` and its six routes (REQ-SCO-001). Verify: `tests/Unit/Controller/FederationControllerTest.php` and the Newman collection.

## 2. One rule

- [ ] 2.1 Move `CatalogiController`, `OoapiController` and `ApiDocumentationController` onto the trait (REQ-SCO-002). Verify: their existing CORS tests pass unchanged; `grep -rn cors_allowed_origins lib/Controller` finds only the trait.

## 3. The settings screen

- [ ] 3.1 Add `cors_allowed_origins` to `SettingsService` `allowedKeys` with origin validation (REQ-SCO-003). Verify: `tests/Unit/Service/SettingsServiceTest.php` with a valid list and a line with a path.
- [ ] 3.2 Add the field to the publishing section of the admin settings (REQ-SCO-003). Verify: `tests/e2e/cors-allowlist.spec.ts` saves two origins and reads them back.

## 4. Docs and strings

- [ ] 4.1 Document the allowlist and the search call from another website in `docs/`, and add the strings to `l10n/` in English and Dutch. Verify: `npm run lint` and the l10n check.
