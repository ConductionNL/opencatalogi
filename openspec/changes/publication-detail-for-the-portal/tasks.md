# Tasks: publication-detail-for-the-portal

Read `/home/rubenlinde/memcap-work/woo-build/LANE-RULES-BUILD.md` first. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`; OpenRegister's `FileMapper::getFilesForObject()` returns rows with `id`, `share_token` and the file fields, and `ObjectEntity` getters are magic. Every new public route reads inside the anonymous scope used by `PublicationQueryService`.

## 1. Order

- [ ] 1.1 Add `attachmentOrder` to `#publication` in a fragment with `slug` and version, and apply it in `PublicationService::attachments()` and `FederationController::publicationAttachments()` (REQ-PDP-001). Verify: `tests/Unit/Service/AttachmentOrderTest.php::testTheListFollowsTheStoredOrder` (fails today), `::testAFileNotInTheOrderComesLast`, `::testADetachedIdIsSkipped`; controller tests through both routes.
- [ ] 1.2 Add Move up and Move down to the attachments on the publication page, keyboard operable with live-region announcements (REQ-PDP-001). Verify: `tests/e2e/publication-detail-for-the-portal.spec.ts` "an officer orders the documents by keyboard and the portal gets that order", carrying `@e2e` REQ-PDP-001, which then reads the public attachments endpoint.

## 2. Documents

- [ ] 2.1 Add `documentId` and the metadata keys to both attachment lists, and the routes `GET /api/{catalogSlug}/{id}/documents/{documentId}` and `/diwoo` with CORS preflights (REQ-PDP-002). Verify: `tests/Unit/Controller/PublicDocumentControllerTest.php::testADocumentAnswersItsMetadataAndItsPublication` (fails today), `::testAWithdrawnFileIs404`, `::testADocumentOfADraftIs404`, `::testTheDiwooRecordIsOneDocument`; route-table and `OpenApiParityTest` cases.

## 3. Comment period

- [ ] 3.1 Add `commentPeriod` and `commentPeriodError` to the public and federation publication responses (REQ-PDP-003). Verify: `tests/Unit/Controller/FederationControllerTest.php::testThePublicationCarriesItsOpenCommentPeriod` (fails today) and `::testUnreadableDatesGiveAnErrorNotAState`.

## 4. Withheld

- [ ] 4.1 Add `showWithheld` to `#catalog` and `titlePublic` to `#wooAssessment`, with versions bumped, and the toggles in the catalogue form and the assessment form (REQ-PDP-004). Verify: `tests/Unit/Settings/PortalDetailSchemaTest.php::testTheDefaultsAreOff`.
- [ ] 4.2 Add `withheld` to the public publication response (REQ-PDP-004). Verify: `tests/Unit/Service/WithheldDocumentsTest.php::testAnOptedInCatalogueListsWithheldDocumentsWithGroundsOnly`, `::testWithoutOptInThereIsNoWithheldKey`, `::testAnUnresolvableCatalogueGivesNoKey`, `::testNoFileOrHashIsEverReturned`.

## 5. Error reports

- [ ] 5.1 Add `acceptErrorReports` and `errorReportModerators` to `#catalog`, the `errorReport` schema with its notification rule, and `POST /api/publications/{id}/error-reports` (REQ-PDP-005). Verify: `tests/Unit/Controller/ErrorReportControllerTest.php::testAReportIsStoredAndTheModeratorsAreNotified` (fails today), `::testWithoutOptInTheRouteIs404`, `::testWithoutAModeratorTheRouteIs404`, `::testALongMessageIsRefused`, `::testNoAddressIsStored`; the rate limit attribute asserted by reflection (`::testTheRouteIsRateLimited`); gate-18 green.
- [ ] 5.2 Add the error reports list per catalogue on the officer side (REQ-PDP-005). Verify: `tests/e2e/publication-detail-for-the-portal.spec.ts` "a moderator sees a report".

## 6. Cross-app contract

- [ ] 6.1 Document every response shape of REQ-PDP-002 to REQ-PDP-005 in `openapi.json` and `docs/`, and link that page from the issues of `portaliq/publication-detail-page-complete` and `portaliq/publication-error-reports-and-withheld-notices`, stating they must test against these keys. Verify: `OpenApiParityTest` and the links in the PR body; a grep for U+2014 on the docs.

## 7. Verification

- [ ] 7.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`.
- [ ] 7.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran. gate-19 wants the `@e2e` references of 1.2 and 5.2.
- [ ] 7.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 7.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Row 6.25 becomes `production` only once a store release ships it. Rows 6.15, 6.16, 6.19, 6.36 and 7.20 close in portaliq.
