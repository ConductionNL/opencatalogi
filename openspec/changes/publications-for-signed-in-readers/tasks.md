# Tasks: publications-for-signed-in-readers

Start after `integration-feed-consumer-credentials` is merged: task 2.2 adds a flag to its consumer key. Run the migration (task 1.2) before the rule change (task 1.3) lands on any instance; ship them in one release with the migration first in the repair step order.

## 1. The schema and the rule

- [ ] 1.1 Add `audience` and `minimumAssurance` to the `publication` schema in `lib/Settings/publication_register.json` with titles, descriptions and a bumped schema version (REQ-PSR-001). Verify: `tests/Unit/Settings/PublicationAudienceSchemaTest.php`.
- [ ] 1.2 Add `lib/Migration/SetPublicationAudiencePublic.php` (repair step) that sets `audience: public` on every publication without one (REQ-PSR-001). Verify: `tests/Unit/Migration/AudienceMigrationTest.php::testEveryExistingPublicationGetsAudiencePublic`.
- [ ] 1.3 Add `audience: "public"` to the `match` of the `group: public` read rule and the same check to `PublicationQueryService::isObjectPublic()` (REQ-PSR-002). Verify: `tests/Unit/Service/SignedInPublicationExclusionTest.php` with one case each for the public API, search, sitemap, DCAT, OOAPI and federation.
- [ ] 1.4 Refuse `signedIn` with a `wooCategory` in `PublicationValidationService` (REQ-PSR-004). Verify: `tests/Unit/Service/PublicationValidationServiceTest.php::testASignedInPublicationWithAWooCategoryIsRefused`.

## 2. The trusted read

- [ ] 2.1 In `PublicationsController::show()`, on a miss for a request carrying a consumer key, check the key, its catalogue, `actForSignedInReaders` and `X-Reader-Assurance` against `minimumAssurance`, then read the one object with RBAC off; answer `Cache-Control: private, no-store` (REQ-PSR-003). Verify: `tests/Unit/Controller/SignedInReadControllerTest.php::testAKeyThatMayActForReadersAtTheRightLevelIsServed`, `::testALowerLevelAnswers404`, `::testAKeyWithoutActForSignedInReadersAnswers404`, `::testNoKeyAnswers404`.
- [ ] 2.2 Add `actForSignedInReaders` to the consumer key and a switch for it in the consumer credentials section of the admin settings (REQ-PSR-003). Verify: a settings controller test.
- [ ] 2.3 Write an audit entry for every trusted read through OpenRegister's audit trail (REQ-PSR-003). Verify: `SignedInReadControllerTest::testATrustedReadIsAudited`.

## 3. The editor

- [ ] 3.1 Add "Who can read it" to the Visibility card on the publication page and in the editor, as design D5 (boards `OcPublicatie`, `OcPublicatieBewerken`) (REQ-PSR-001). Verify: `tests/e2e/publications-for-signed-in-readers.spec.ts` "an editor limits a publication to signed-in readers", carrying `@e2e` REQ-PSR-001.
- [ ] 3.2 Add the strings to `l10n/` (en, nl). Verify: `npm run check:l10n`, `npm run check:schema-l10n`.

## 4. portaliq (cross-repo, ConductionNL/portaliq)

- [ ] 4.1 Send the portal's consumer key and `X-Reader-Assurance` from the session's mapped claims when a logged-in reader opens a publication (REQ-PSR-003).
- [ ] 4.2 Show a login prompt for a publication link the portal marks as sign-in when the anonymous read answers 404.

## 5. Verification

- [ ] 5.1 `openspec validate publications-for-signed-in-readers --strict`, `composer check:strict`, `npm run lint`.
- [ ] 5.2 Live: create a `signedIn` publication, show the anonymous 404 and the trusted read with a key, and paste both in the PR body.
- [ ] 5.3 Set `pub-restricted` in `openspec/parity/capabilities.json` to `built` once the portaliq tasks land.
