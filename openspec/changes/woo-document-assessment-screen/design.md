# Design: woo-document-assessment-screen

## D1. Boards

Canvas 5NkFW28vZUUij43xzxHg5a, boards `OcWooBatch` and `OcLakken` (local copies in `zuiddrecht/v2/project/`). Follow them for labels, order and actions:

- `OcWooBatch`: header with the batch title, status chip ("Being assessed") and breadcrumb Woo batches / case reference; the line "Assessed: 62% · 214 documents · created <date> by <name>"; a "What now?" card that says how many are left and offers Mark ready for review; the "Document queue" with four stacks To assess, Public, Partly public, Not public, each with its count; cards with title, meta (type, pages) and, where set, the ground chip ("Personal privacy, 5.1.2e"); card actions Preview and Redact. Below: Inventory list with the two downloads, Public reading room with Publish to reading room ("Available once the batch is reviewed"), and the Woo request card (that card belongs to `woo-request-intake`, not to this change).
- `OcLakken`: header with the document name, chips "Partly public" and "Redaction not verified", breadcrumb Woo batches / case reference / document; the meta line "pdf · 9 pages · 6 redactions on 3 pages · assessed by <name> on <date>"; three steps (Original kept, never published; Redacted version generated, a separate file with date and time; Verified by nobody yet, needs a person to check all N redactions); a toggle Redacted version / Original; page thumbnails with mark counts; the notice "This document cannot be published until its redacted version is verified. Publishing the batch is refused while it is open, and the original is never published in its place."; the Redactions list ("6 kept · 1 rejected") with per mark the number, what it is, the ground and who decided; the line "Findings are decided one by one in OpenRegister."; the action Mark redaction verified.

## D2. Reading the queue

The page reads the assessments through OpenRegister's object store (`useObjectStore`, ADR-022), filtered on the batch's `caseReference` and the `wooAssessment` schema from initial state, not through a new OpenCatalogi list endpoint. Columns group on `assessment`. Counts come from the same `documentSummary` the page reads today, so the header and the columns agree.

## D3. Saving an assessment

A move calls the existing `PUT /api/woo/batches/{batchId}/documents/{docId}` with `assessment` and `weigeringsgronden`. That is not a pass-through: it validates the grounds and runs `DocumentRedactor` for `deels_openbaar`. The dialog `src/dialogs/WooAssessmentDialog.vue` (ADR-004) asks the grounds from `GET /api/woo/weigeringsgronden`, an `NcSelect` with `inputLabel`, at least one required for both partly and not public. A refused save (422 with the service's message) puts the card back in its old column and shows the message on the card. The response's `redactionStatus` and `redactionMessage` are shown on a partly public card: "Redacting", "Redacted, not verified", "Verified" or the failure reason.

Drag uses the column list's native reorder; the card's action menu offers "Move to" with the four columns for keyboard and screen reader users (WCAG 2.5.7). A move is announced in a live region.

## D4. Human verification

`woo-redaction-pipeline` verifies the file mechanically (a separate file, different bytes, no residual findings). The board adds a person: "Verified by nobody yet". Two properties on `wooAssessment`, in a register fragment `lib/Settings/register.d/woo-redaction-verification.json` (ADR-037): `redactionVerifiedBy` (string) and `redactionVerifiedAt` (date-time). `POST /api/woo/batches/{batchId}/documents/{docId}/verify-redaction` (`#[NoAdminRequired]` with the same right as `updateAssessment`) sets both, only when `redactionStatus` is `verified` and the stored hash still matches the file; otherwise 409 naming the reason. A new PUT that changes the redaction clears both. `DocumentRedactor::assertPublishable()` also refuses a `deels_openbaar` document without `redactionVerifiedBy`, with the reason "redaction not checked by a person". The unredacted list on the batch page shows that reason too.

## D5. The redaction page

Route `/woo/:id/documents/:docId`, page `WooRedaction` in `src/manifest.json` (type custom), component `src/views/woo/WooRedaction.vue`. The two versions come from OpenRegister's file endpoints for `anonymizedDocument` and the original `documentReference`, shown in Nextcloud's viewer. The redactions list reads OpenRegister's entity relations for the redacted file (`GET /apps/openregister/api/entity-relations?fileId=`), with kept and rejected counts; each row links to the finding in OpenRegister. When `woo-review-surface` adds `redactionLog`, the list reads that instead; until then it shows the relations.

## D6. Navigation

`WooBatches` gets `rowRoute: "WooBatchDetail"`. The Deck note and its warning card go from `WooBatchDetail.vue`.

## D7. Order with other changes

`woo-review-surface` (open) replaces the queue with the public view while a batch is `ready_for_review` and adds Reject and the redaction log. This change builds the queue and the redaction page it assumes ("Show the log on the review and assessment pages", its task 3.3). Build this one first; if `woo-review-surface` lands first, keep its review mode and add the queue for the other states.

## D8. Tests

Unit: `WooControllerTest::testVerifyRedactionNeedsAVerifiedFile`, `::testVerifyRedactionIsClearedByANewAssessment`; `DocumentRedactorTest::testAnUncheckedRedactionBlocksThePublish`. e2e `tests/e2e/woo-document-assessment.spec.ts` on a seeded batch with three documents and a fixture PDF with one name in it.
