# Tasks: citizen-collections

## 1. Schema

- [ ] 1.1 Add `collection` in `lib/Settings/register.d/citizen-collections.json`, bump the publication register version (REQ-CCOL-001). Verify: `tests/Unit/Settings/CitizenCollectionsFragmentTest.php` validates a real item payload against the fragment.

## 2. Portal contribution

- [ ] 2.1 Add `lib/Portal/PortalContributionProvider.php` for `citizen` and `client` (REQ-CCOL-006). Verify: `tests/Unit/Portal/PortalContributionProviderTest.php`.
- [ ] 2.2 Add `lib/Portal/PortalAssertionVerifier.php` (REQ-CCOL-001). Verify: `tests/Unit/Portal/PortalAssertionVerifierTest.php` with a token minted the way portaliq mints it, plus expired, wrong issuer, session token and `alg: none`.

## 3. Endpoints

- [ ] 3.1 `CitizenCollectionService` + `PortalCollectionController`: add, remove, note, view, delete (REQ-CCOL-001..004). Verify: `tests/Unit/Service/CitizenCollectionServiceTest.php`.
- [ ] 3.2 Share, revoke and `GET /api/collections/shared/{token}` (REQ-CCOL-005). Verify: same test file plus `tests/Unit/Controller/SharedCollectionControllerTest.php`.

## 4. Account removal

- [ ] 4.1 `PortalAccountRemovedListener` (REQ-CCOL-007). Verify: `tests/Unit/Listener/PortalAccountRemovedListenerTest.php` on the real `ObjectUpdatedEvent` class.

## 5. Docs

- [ ] 5.1 Dutch and English strings, `openspec validate citizen-collections --strict`.
