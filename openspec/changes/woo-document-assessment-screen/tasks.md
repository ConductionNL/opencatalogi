# Tasks: woo-document-assessment-screen

Read `design.md` first (D1 lists what each board shows) and `openspec/woo-build-rules.md`. Build on `woo-redaction-pipeline` as it is on development: `WooService::updateAssessment()`, `DocumentRedactor`, `BatchPublicationWriter`. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`.

## 1. Navigation

- [ ] 1.1 Add `rowRoute: "WooBatchDetail"` to the `WooBatches` page in `src/manifest.json` (REQ-WDA-001). Verify: `npm run check:manifest`; e2e step in 5.1.

## 2. Queue

- [ ] 2.1 Load the batch's assessments in `src/views/woo/WooBatchDetail.vue` through `useObjectStore` on the `wooAssessment` schema, filtered on `caseReference`; render the four columns with counts and cards (title, type, size, ground chip) as board `OcWooBatch`; remove the Deck note and its warning card (REQ-WDA-002).
- [ ] 2.2 Add `src/dialogs/WooAssessmentDialog.vue` (ADR-004): grounds from `GET /api/woo/weigeringsgronden` in an `NcSelect` with `inputLabel`, at least one required (REQ-WDA-002).
- [ ] 2.3 Save a move with `PUT /api/woo/batches/{batchId}/documents/{docId}`; on refusal put the card back and show the message; show `redactionStatus` and `redactionMessage` on partly public cards; card action menu with Move to, Preview and Redact; a live region announcing moves (REQ-WDA-002). Verify: 5.1.

## 3. Human verification

- [ ] 3.1 Register fragment `lib/Settings/register.d/woo-redaction-verification.json` adding `redactionVerifiedBy` and `redactionVerifiedAt` to `wooAssessment`, version bumped (REQ-WDA-004). Verify: `tests/Unit/Settings/WooRedactionVerificationFragmentTest.php` loads the fragment; clean `occ app:enable` imports it.
- [ ] 3.2 `WooController::verifyRedaction()` and route `POST /api/woo/batches/{batchId}/documents/{docId}/verify-redaction`, through `WooService::verifyRedaction()`: requires `redactionStatus: verified` and a matching hash, else 409; `updateAssessment()` clears both fields when the redaction changes (REQ-WDA-004). Verify: `tests/Unit/Controller/WooControllerTest.php::testVerifyRedactionNeedsAVerifiedFile`, `tests/Unit/Service/WooServiceTest.php::testVerifyRedactionIsClearedByANewAssessment`; hydra route-auth and route-reachability gates.
- [ ] 3.3 `DocumentRedactor::assertPublishable()` and `unredacted()` refuse and list a `deels_openbaar` document without `redactionVerifiedBy` with the reason "redaction not checked by a person" (REQ-WDA-004). Verify: `tests/Unit/Service/Woo/DocumentRedactorTest.php::testAnUncheckedRedactionBlocksThePublish` (fails today).

## 4. Redaction page

- [ ] 4.1 Page `WooRedaction` at `/woo/:id/documents/:docId` in `src/manifest.json`, component `src/views/woo/WooRedaction.vue` as board `OcLakken`: header chips, meta line, three steps, Redacted version / Original in the Nextcloud viewer, redactions list from OpenRegister's entity relations with kept and rejected counts and links to OpenRegister, the not-verified notice, Mark redaction verified (REQ-WDA-003, REQ-WDA-004).

## 5. Tests, strings, verification

- [ ] 5.1 e2e `tests/e2e/woo-document-assessment.spec.ts` on a seeded batch (three documents, one fixture PDF holding a name): open the batch from `/woo`, move a card to Partly public with a ground, move one with the keyboard, refuse a move without a ground, open the redaction page, switch versions, mark verified, see the document leave the blocked list. Carries `@e2e` for every scenario of REQ-WDA-001 to REQ-WDA-004 that has no exclusion.
- [ ] 5.2 nl and en strings for the board labels in `l10n/`; `npm run check:l10n`.
- [ ] 5.3 Live: on the dev instance assess one PDF as partly public, verify it, publish the batch, and paste the publish response in the PR body.
- [ ] 5.4 Diff check while building; once before push `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:manifest`; hydra gates `--scope-to-diff` (modal-isolation, nc-input-labels, route-auth). One PR `--base development`, closes opencatalogi#1604.
