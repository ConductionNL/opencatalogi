# Tasks: publication-tells-its-source

Read `/home/rubenlinde/memcap-work/woo-build/LANE-RULES-BUILD.md` first. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`. Real signatures, checked in openregister `development`: `ObjectCreatingEvent::getObject(): ObjectEntity` and `setModifiedData(array)`, `ObjectUpdatingEvent::getNewObject()` and `getOldObject()`, `FileMapper::getFilesForObject(ObjectEntity): array` (each row has `share_token`), and the private `WebhookService::passesFilters()` (equality with dot notation; reach it through `WebhookService`'s public preview method rather than reflection where one exists). `ObjectEntity` getters are magic.

## 1. Links

- [ ] 1.1 Add `publicUrl`, `officerUrl`, `completeness`, `completeAt` and `expectedDocuments` to `#publication` in a `lib/Settings/register.d/publication-tells-its-source.json` fragment with `slug` and a bumped `version` (REQ-PTS-001, REQ-PTS-002). Verify: `tests/Unit/Settings/PublicationTellsItsSourceSchemaTest.php::testTheFragmentDeclaresTheFiveProperties`, which asserts the slug.
- [ ] 1.2 Add `PublicationLinksListener` on `ObjectCreatingEvent`, registered in `Application::register()` (REQ-PTS-001). Verify: `tests/Unit/Listener/PublicationLinksListenerTest.php::testACreateThroughOpenRegisterReturnsBothPages` (fails today: the properties do not exist), `::testNoPublicLinkLeavesPublicUrlEmpty`, `::testACallerSuppliedValueIsIgnored`, and `tests/Unit/AppInfo/ApplicationRegisterInvariantTest.php::testThePublicationLinksListenerIsRegistered`. Assert in the first test that the entity has a uuid at `ObjectCreatingEvent` time; if it does not on the real `MagicMapper::insertObjectEntity()`, move the write to `ObjectCreatedEvent` with a second save guarded against recursion and say so in the PR body.
- [ ] 1.3 `PublicationsController::show()` returns both values computed fresh (REQ-PTS-001). Verify: `tests/Unit/Controller/PublicationsControllerTest.php::testShowCarriesThePublicAndOfficerPages`.
- [ ] 1.4 A queued job rewrites both properties when `publication_url_template` or `publication_url_catalog` is saved through `SettingsController` (REQ-PTS-001). Verify: `tests/Unit/Controller/SettingsControllerTest.php::testChangingTheLinkTemplateQueuesTheRewrite`.

## 2. Completeness

- [ ] 2.1 Add `CompletenessService::evaluate()` (REQ-PTS-002). Verify: `tests/Unit/Service/Publication/CompletenessServiceTest.php::testFewerDocumentsThanExpectedIsIncomplete`, `::testAFileWithoutAShareIsIncomplete`, `::testANonPublicPublicationIsIncomplete`, `::testEveryFileSharedAndPublicIsComplete`, `::testALookupFailureThrows`.
- [ ] 2.2 Add the `ObjectUpdatingEvent` listener writing `completeness` and `completeAt`, and re-evaluation after `publishObjectAttachments()` and `withdrawFile()` (REQ-PTS-002). Verify: `tests/Unit/Listener/CompletenessListenerTest.php` on the REAL event class, with `::testALookupFailureLeavesTheStoredValueAsItWas` and `::testWithdrawingAFileMovesBackToIncomplete`.
- [ ] 2.3 Add `CompletenessSweep` as a `TimedJob` registered in `appinfo/info.xml` `<background-jobs>` (REQ-PTS-002). Verify: `tests/Unit/BackgroundJob/CompletenessSweepTest.php::testAPublicationWhoseDatePassedIsMarkedComplete` (fails today: no job) and `::testTheJobIsRegistered`, which reads `appinfo/info.xml`.

## 3. Webhook contract

- [ ] 3.1 Prove the recipe's filters match only the completing update (REQ-PTS-003). Verify: `tests/Unit/Service/Publication/CompletenessWebhookContractTest.php::testTheFilterMatchesOnlyTheCompletingUpdate`, which builds the three `ObjectUpdatedEvent` payloads (incomplete to incomplete, incomplete to complete, complete to complete) the way OpenRegister's `WebhookEventListener::extractPayload()` does and runs OpenRegister's filter over them when the class exists.
- [ ] 3.2 Write the recipe in `docs/` (integrations), following the writing skill. Verify: `npm run lint`, and a grep for U+2014 on the doc returns nothing.

## 4. Live

- [ ] 4.1 On the dev instance, create a publication through OpenRegister's object API and paste the response's `publicUrl` and `officerUrl` in the PR body; open both (REQ-PTS-001). Verify: the pasted response.
- [ ] 4.2 Subscribe a webhook with the recipe's filters to a local catcher, complete a publication, edit it once more, and paste the delivery log showing one delivery (REQ-PTS-003). Verify: the pasted log.

## 5. Verification

- [ ] 5.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`.
- [ ] 5.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] 5.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 5.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Rows 1.17 and 1.20 become `production` only once a store release ships it.
