# Tasks: publication-lifecycle-on-or-draft-purge

Read `openspec/woo-build-rules.md` first. Start once `publication-lifecycle-on-or` is merged on `development`: the never-released proof reads its stored `status` and its immutable `firstReleasedAt`. For OpenRegister doubles copy the `environmentAwareDouble()` pattern from `tests/Unit/Service/SitemapServiceTest.php`. Real signatures to mock against, on `ConductionNL/openregister` branch `development`: `ObjectService::deleteObject(..., bool $permanent = false)` and `AuditTrailMapper::createAuditTrail(?ObjectEntity $old, ?ObjectEntity $new, ?string $action, ?array $cascadeContext)`; mappers throw `DoesNotExistException` instead of returning null.

## 1. Permanent delete of a draft

- [x] 1.1 Add `DraftPurgeService` and `DELETE /api/publications/{id}/draft` with the update-right check, the never-released proof, list-then-remove, and the one audit entry (REQ-PLC-004). Verify: `tests/Unit/Service/Publication/DraftPurgeServiceTest.php::testADraftAndEverythingOnItGoes`, `::testAPublicationThatWasEverPublishedIsRefused`, `::testAnUnreadableAuditTrailRefuses`, `::testAFailurePartWayKeepsThePublicationAndNamesWhatWent`, and a controller test through the route.
  - Built: `lib/Service/Publication/DraftPurgeService.php` behind `DraftPurgeServiceInterface`, reached by `DraftPurgeController::destroy()` on `DELETE /api/publications/{id}/draft` (`draftPurge#destroy`). Order: stored state `draft` or `in_review`, empty `firstReleasedAt`, no `depublication` naming it, an audit trail (`AuditTrailMapper::findByObjectUntil()`) with no `status` `published` on either side of a change; then the list (document references, publication processes, metadata suggestions, the `wooAssessment` of each file, the files, the publication last); then `deleteObject(new ObjectDeletion(..., permanent: true))` and `FileService::deleteFile()`; then one `createAuditTrail(old: publication, action: publication.draft.purged, cascadeContext: {publication, removed})`. A list that cannot be read, or that reaches 500 rows, refuses with `unverifiable`. Split into `DraftPurgeProof` (the four proofs) and `DraftPurgeRecords` (the OpenRegister reads and writes). Tests: `DraftPurgeServiceTest` (18, including the four named) and `DraftPurgeControllerTest` (8, the route to `destroy()`, 404/403/503 before any purge, 409 naming the evidence, 500 naming what went).
- [ ] 1.2 Add Delete permanently to the detail page for a draft or in-review publication, in its own dialog under `src/dialogs/` (REQ-PLC-004). Verify: `tests/e2e/publication-draft-purge.spec.ts` "a draft and everything on it goes", carrying `@e2e` REQ-PLC-004, which reads the audit entry afterwards.
  - Written: `tests/e2e/publication-draft-purge.spec.ts` (a draft with two files and a document reference, Delete permanently on its page, confirmed in the dialog; the publication and the reference read 404 and one `publication.draft.purged` entry lists all four). Not run, needs a live instance. Left: the button and the dialog under `src/dialogs/`.

## 2. Verification

- [ ] 2.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`.
- [ ] 2.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran. Expect gate-19 to want the `@e2e` reference from 1.2.
- [ ] 2.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 2.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Row 5.14 becomes `production` only once a store release ships it.
