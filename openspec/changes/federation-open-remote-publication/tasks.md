# Tasks: federation-open-remote-publication

## 1. Server

- [ ] 1.1 Add `DirectoryService::getRemoteAttachments()` and move `getPublication()` to the peer's federation endpoint with `_aggregate=false` (REQ-FOR-002). Verify: `tests/Unit/Service/DirectoryServiceTest.php` with a fake client, asserting the URL and that an unsafe URL is refused.
- [ ] 1.2 Fall back to the peer in `FederationController::publicationAttachments()` (REQ-FOR-002). Verify: `tests/Unit/Controller/FederationControllerTest.php`, a local and a remote id.

## 2. Page

- [ ] 2.1 Add the `FederatedPublication` manifest page, its `ui#federatedPublication` route and `FederatedPublicationView` (REQ-FOR-001, REQ-FOR-003). Verify: `tests/e2e/federation-open-remote.spec.ts` starts two instances, opens a remote result, lists its attachments and reloads the page.
- [ ] 2.2 Route federated results in `FederationSearch.vue` to the page and fix the source link to history mode (REQ-FOR-001, REQ-FOR-003). Verify: same e2e spec asserts no new tab opens and the source link has no `#/`.

## 3. Docs and strings

- [ ] 3.1 Document reading a remote publication in `docs/` and add the strings to `l10n/` in English and Dutch. Verify: `npm run lint` and the l10n check.
