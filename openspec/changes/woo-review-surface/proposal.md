---
kind: code
depends_on: []
---

# Proposal: woo-review-surface

## Summary

The approver of a Woo publication batch sees what the public will see, a rejection goes back to its author with a reason, and the log of what was blacked out and why exists.

- Rows: 4.2, 4.7, 4.12.
- Wave: 1.
- Depends on: nothing to build. Uses OpenRegister `TransitionEngine`, `x-openregister-approval-chains`, `TaskTerminalEvent`, `EntityRelationMapper::findAnonymisedEntitiesWithBasesForFile()`. Row 4.27 ("require review") ships off by default until this change lands.
- Decision: D1 (this is the publication batch review; a Woo request's review is dossiq's); D2 respected (no second redaction path).
- Build rules: openspec/woo-build-rules.md

## Why

The person who approves a Woo publication batch is the last one to see it before the public does. Today that person sees the assessment form, not what the public will see. A rejection has nowhere to go: the batch stays where it is and its author is not told. And the log of what was blacked out, and why, does not exist, although the main spec says it does.

Rows, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`:

- **4.2** "The reviewer sees the public rendering, not a form". Ours: no. Evidence: "opencatalogi src/manifest.json#WooBatchDetail shows the assessment form. Nothing renders the public view of the document for a reviewer before it goes out".
- **4.7** "A log records what was removed, and on which ground". Ours: partial, production. Evidence: "#wooAssessment.weigeringsgronden + .redactionInstructions record the decision and the instruction, not what was removed from the file". The main spec `woo-transparency` (requirement Weigeringsgronden, scenario "Partial redaction with grounds") says "the redaction mapping (entity -> ground) MUST be stored for the besluit"; the code does not store it.
- **4.12** "A rejected record returns to its author with the reason attached". Ours: partial, production. Evidence: "#wooAssessment.assessment holds a verdict and redactionInstructions holds a reason, but nothing routes the document back to an author".

Read on development at 35999c296. `wooBatch.status` is `in_progress`, `ready_for_review` or `published`, with no lifecycle declaration; `WooService::markReadyForReview()` moves it forward and `publishBatch()` publishes. `DocumentRedactor` redacts through OpenRegister's `FileService::anonymizeDocument()` with the entities from `EntityRelationMapper::findEntitiesForAnonymization()`. OpenRegister's `EntityRelationMapper::findAnonymisedEntitiesWithBasesForFile(int $fileId)` returns what was actually redacted with each relation's `bases` (its grounds, `entity-relation-grondslagen`). OpenRegister approvals are task sequences provisioned from `x-openregister-approval-chains` on a lifecycle transition (`approval-workflow` REQ-006, `flow-approval-consolidation`); a rejection ends the sequence with the decider's comment.

## What changes

- The Woo batch gets a lifecycle on OpenRegister: `in_progress`, `ready_for_review`, `approved`, `published`, with transitions `submit`, `approve`, `reject` (with a required `reason`) and `publish`. `approve` carries an approval chain `batchReview` for the role `woo-reviewer`. Publishing needs `approved`.
- The review page shows, per document, the public view: the redacted file in the viewer and the public metadata exactly as the publication payload will carry them. Approve stays disabled until the reviewer has opened the public view of every document.
- A rejection runs `reject` with the reviewer's reason. The batch goes back to `in_progress`, its author (`createdBy`) is assigned again, the reason is stored on the batch with who and when, and the author gets a notification. Single documents can be sent back the same way: the assessment goes back to `te_beoordelen` with the reason.
- Each partly public document stores its redaction log: per redacted passage the entity type, its position, the value, and the grounds from OpenRegister's `bases`. A passage without a ground blocks publishing.

## Fail closed

- A batch that is not `approved` is not published, whatever path calls `publishBatch()`.
- A partly public document whose redaction log has a passage without a ground, or whose log cannot be read, or whose log is empty while the redaction removed passages, blocks the publish and names the document. Publishing a redaction without its legal ground is not allowed (Woo art. 5.1 and 5.2 require the ground per withheld part).
- The redaction log is internal: it lives on `wooAssessment`, which has no public read rule, and its values never reach a public response.
- When the notification cannot be sent, the rejection still stands and the batch page shows the reason to the author; the notification is not claimed as sent.

## Out of scope

- The review of a Woo request (decision D1: dossiq owns it).
- Editing the redaction from the review page. The reviewer approves or rejects.

## Dependencies

- None to build. Uses OpenRegister on development: `TransitionEngine`, `x-openregister-approval-chains`, `TaskTerminalEvent`, `EntityRelationMapper::findAnonymisedEntitiesWithBasesForFile()`.
- `woo-redaction-scans-and-text-layer` (wave 2) and the D2 move of redaction guarantees into OpenRegister change how the redaction runs; the log reads OpenRegister's relation rows either way.

## Wave

Wave 1. It needs nothing new.

## Decisions

- D1: this is the publication batch review in OpenCatalogi; a Woo request's own review is dossiq's.
- D2 (redaction guarantees move into OpenRegister's engine): respected; this change reads the engine's relation rows and adds no second redaction path.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 4.2 | The reviewer sees the public rendering, not a form | no | REQ-WRV-001, scenario "The reviewer sees each document as the public will" |
| 4.7 | A log records what was removed, and on which ground | partial | REQ-WRV-003, scenarios "The log says what was removed and why" and "A passage without a ground blocks publishing" |
| 4.12 | A rejected record returns to its author with the reason attached | partial | REQ-WRV-002, scenario "A rejected batch goes back to its author with the reason" |
