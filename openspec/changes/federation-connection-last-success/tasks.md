# Tasks: federation-connection-last-success

## 1. Record it

- [x] 1.1 Add `lastSuccessAt` and `lastError` to the `listing` schema (REQ-FLS-001). Verify: clean `occ app:enable` imports them; a listing saved with both reads them back.
- [x] 1.2 Write `lastSuccessAt` on success and `lastError` on both failure paths, stripping credentials and query strings (REQ-FLS-001). Verify: `tests/Unit/Service/DirectoryServiceTest.php`, a success, a failure after a success (the success time survives), and an error text with a token in a URL.

## 2. Show it

- [ ] 2.1 Show the last success, the last attempt and the error per listing in `FederationDirectory.vue` (REQ-FLS-002). Verify: `tests/e2e/federation-directory.spec.ts` with a failed and a healthy listing. Built: `src/services/listingSyncStatus.js` + `src/views/directory/FederationDirectory.vue` (`syncStatusFor`, `statusTextFor`), offline `tests/vitest/listingSyncStatus.spec.js` 6/6 (healthy and failed rows). (not run: the e2e spec is written but needs a live instance)
- [ ] 2.2 Add Sync now per row for administrators (REQ-FLS-003). Verify: same e2e spec as an administrator and as a normal user. Built: `FederationDirectory.vue` `syncNow()` posts `{id}` to `/api/listings/sync` (AuthorizedAdminSetting route), action shown only when `useIsAdmin()` is true, disabled while a sync runs, list reloads after. (not run: the e2e spec needs a live instance)

## 3. Docs and strings

- [x] 3.1 Document the fields in `docs/` and add the strings to `l10n/` in English and Dutch. Verify: `npm run lint` and the l10n check. Done: `docs/tutorials/admin/02-manage-federation-sources.md` step 5 + verification, `docs/schema/Listing.json`, l10n en/nl (check:l10n clean, check:l10n-js up to date).
