# Tasks: federation-connection-last-success

## 1. Record it

- [x] 1.1 Add `lastSuccessAt` and `lastError` to the `listing` schema (REQ-FLS-001). Verify: clean `occ app:enable` imports them; a listing saved with both reads them back.
- [x] 1.2 Write `lastSuccessAt` on success and `lastError` on both failure paths, stripping credentials and query strings (REQ-FLS-001). Verify: `tests/Unit/Service/DirectoryServiceTest.php`, a success, a failure after a success (the success time survives), and an error text with a token in a URL.

## 2. Show it

- [ ] 2.1 Show the last success, the last attempt and the error per listing in `FederationDirectory.vue` (REQ-FLS-002). Verify: `tests/e2e/federation-directory.spec.ts` with a failed and a healthy listing.
- [ ] 2.2 Add Sync now per row for administrators (REQ-FLS-003). Verify: same e2e spec as an administrator and as a normal user.

## 3. Docs and strings

- [ ] 3.1 Document the fields in `docs/` and add the strings to `l10n/` in English and Dutch. Verify: `npm run lint` and the l10n check.
