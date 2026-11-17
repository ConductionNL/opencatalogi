# Tasks: federation-open-remote-publication

## 1. Server

- [x] 1.1 Add `DirectoryService::getRemoteAttachments()` and move `getPublication()` to the peer's federation endpoint with `_aggregate=false` (REQ-FOR-002). Verify: `tests/Unit/Service/DirectoryServiceTest.php` with a fake client, asserting the URL and that an unsafe URL is refused.
- [x] 1.2 Fall back to the peer in `FederationController::publicationAttachments()` (REQ-FOR-002). Verify: `tests/Unit/Controller/FederationControllerTest.php`, a local and a remote id.

## 2. Page

- [x] 2.1 Add the `FederatedPublication` manifest page, its `ui#federatedPublication` route and `FederatedPublicationView` (REQ-FOR-001, REQ-FOR-003). Verify: `tests/e2e/federation-open-remote.spec.ts` starts two instances, opens a remote result, lists its attachments and reloads the page.
      (Built: manifest page `FederatedPublication` at `/federation/publications/:id`, `ui#federatedPublication` with `UiController::federatedPublication()` so a reload works, and `src/views/federation/FederatedPublicationView.vue` (metadata, attachments with the source's download URLs, Open at the source), registered in `src/registry.js`. Unit-tested: `FederatedPublicationView.spec.js`, `UiControllerTest`. Left: the two-instance e2e spec, which needs two live instances.)
- [x] 2.2 Route federated results in `FederationSearch.vue` to the page and fix the source link to history mode (REQ-FOR-001, REQ-FOR-003). Verify: same e2e spec asserts no new tab opens and the source link has no `#/`.
      (Built: `resultClickTarget()` routes a federated result to `FederatedPublication` (with the catalogue slug as `catalog` when known) and never opens a tab; `sourceUrl()` builds the history-mode source link. `tests/vitest/federationResultTarget.spec.js`: the golden federated cases now push the route, a test asserts no case opens a tab, and `sourceUrl` has no `#`. The e2e assertion is left with 2.1's spec.)

## 3. Docs and strings

- [x] 3.1 Document reading a remote publication in `docs/` and add the strings to `l10n/` in English and Dutch. Verify: `npm run lint` and the l10n check.
      (Built: `docs/features/directory.md`, section "Reading a publication of another instance". The page reuses existing strings; the one new string, "Open at the source", is in every shipped locale (`l10n/*.json`, `.js` rebuilt). `check:l10n` reports nothing missing or unused.)
