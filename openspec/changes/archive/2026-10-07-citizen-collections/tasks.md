# Tasks: citizen-collections

## 1. Schema

- [x] 1.1 Add `collection` in `lib/Settings/register.d/citizen-collections.json`, bump the publication register version (REQ-CCOL-001). Verify: `tests/Unit/Settings/WooJourneyRegisterTest.php` validates a real dossier payload against the merged register.

## 2. Portal contribution

- [x] 2.1 Add `lib/Portal/PortalContributionProvider.php` for `citizen` and `client` (REQ-CCOL-006). Verify: `tests/Unit/Portal/PortalContributionProviderTest.php`.
- [x] 2.2 Add `lib/Portal/PortalAssertionVerifier.php` (REQ-CCOL-001). Verify: `tests/Unit/Portal/PortalAssertionVerifierTest.php` with a token minted the way portaliq mints it, plus expired, wrong issuer, session token and `alg: none`.

## 3. Endpoints

- [x] 3.1 `CitizenCollectionService` + `PortalCollectionController`: add, remove, note, view, delete (REQ-CCOL-001..004). Verify: `tests/Unit/Service/Portal/CitizenCollectionServiceTest.php`.
- [x] 3.2 Share, revoke and `GET /api/collections/shared/{token}` (REQ-CCOL-005). Verify: same test file plus `tests/Unit/Controller/PortalCollectionControllerTest.php`.

## 4. Account removal

- [x] 4.1 `PortalAccountRemovedListener` (REQ-CCOL-007). Verify: `tests/Unit/Listener/PortalAccountRemovedListenerTest.php` constructing `ObjectUpdatedEvent` and `ObjectEntity` (the real OpenRegister classes where OpenRegister is installed, the stubs otherwise; the accessors used were checked against OpenRegister `development`).

## 5. Docs

- [x] 5.1 Dutch and English strings, `openspec validate citizen-collections --strict`.

## 6. The dossier screen and the shared page (Woo screens programme)

- [x] 6.1 The `dossiers` page carries a `detail` block for `myDossiers`; the card lists `title` and `description`; `viewDossier` and `noteOnDossier` leave the row actions (REQ-CCOL-008). Verify: `tests/Unit/Portal/PortalContributionProviderTest.php` `testTheDossierPageShowsTheSelectedDossier`, `testNoTableButtonOpensOrEmptiesADossier`.
- [x] 6.2 `shareDossier` answers `link` as the shared-dossier page of portaliq's site (REQ-CCOL-009). Verify: `tests/Unit/Service/Portal/CitizenCollectionServiceTest.php` `testTheShareLinkOpensTheSharedDossierPageOfTheSite`. The page itself is portaliq's (`feat/site-shared-dossier`).
