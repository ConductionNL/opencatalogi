# Woo transparency

## Purpose

The officer's screens for assessing the documents of a Woo batch and checking a redaction, on top of the redaction `woo-redaction-pipeline` built. Design: `../../design.md`. Boards `OcWooBatch` and `OcLakken` on canvas 5NkFW28vZUUij43xzxHg5a.

## ADDED Requirements

### Requirement: A batch opens from the Woo index (REQ-WDA-001)

The `WooBatches` index page SHALL open a batch's detail page at `/woo/:id` when its row is clicked.

#### Scenario: An officer opens a batch
- **GIVEN** a Woo batch with case reference WOO-2026-031
- **WHEN** the officer clicks its row on the Woo batches page
- **THEN** the batch page for WOO-2026-031 opens

### Requirement: The batch page shows the document queue and saves an assessment (REQ-WDA-002)

The batch page SHALL show every assessment of the batch in four columns, To assess, Public, Partly public and Not public, each with its count, and each card SHALL show the file name, type and size, and the refusal grounds when set. Moving a card to another column SHALL save the new assessment through `PUT /api/woo/batches/{batchId}/documents/{docId}`. A move into Partly public or Not public SHALL first ask for at least one refusal ground in a dialog, and SHALL NOT save without one. A refused save SHALL return the card to its column and show the refusal. Every move MUST be possible from the keyboard through the card's action menu.

#### Scenario: Assessing a document as partly public runs the redaction
- **GIVEN** a batch with a document "Email thread with residents" in To assess
- **WHEN** the officer moves it to Partly public and picks the ground 5.1 lid 2 sub e
- **THEN** the assessment is saved as `deels_openbaar` with that ground
- **AND** the card shows the redaction state the save returned

#### Scenario: No ground, no save
- **GIVEN** a document in To assess
- **WHEN** the officer moves it to Not public and closes the dialog without a ground
- **THEN** nothing is saved and the card is back in To assess

#### Scenario: A keyboard user moves a card
- **GIVEN** a document in To assess with focus on its card
- **WHEN** the officer opens the card's action menu and chooses Move to Public
- **THEN** the assessment is saved as `openbaar` and the move is announced

### Requirement: A partly public document has a redaction page (REQ-WDA-003)

A partly public card SHALL offer Redact, which opens `/woo/:id/documents/:docId`. That page SHALL show the redacted version and the original, switchable, the redactions with their ground and who decided them, the counts of kept and rejected findings, and the three steps original kept, redacted version generated and verified. It SHALL link each finding to OpenRegister, where findings are decided, and SHALL NOT decide findings itself.

#### Scenario: The officer compares the two versions
- **GIVEN** a partly public document whose redacted version was generated with six kept findings and one rejected
- **WHEN** the officer opens its redaction page
- **THEN** the page shows the redacted version, "6 kept · 1 rejected" and six rows with their grounds
- **AND** choosing Original shows the original file

### Requirement: A person marks a redaction verified before it can be published (REQ-WDA-004)

The system SHALL store `redactionVerifiedBy` and `redactionVerifiedAt` on a `wooAssessment` when an officer chooses Mark redaction verified, and only when its `redactionStatus` is `verified` and the stored hash still matches the redacted file. A later assessment save that changes the redaction SHALL clear both. Publishing a batch SHALL be refused while a partly public document has no `redactionVerifiedBy`, and the refusal SHALL name the document.

#### Scenario: An unchecked redaction blocks the batch
<!-- @e2e exclude Fail-closed publish path; proven by DocumentRedactorTest::testAnUncheckedRedactionBlocksThePublish. -->
- **GIVEN** a batch whose only partly public document has a mechanically verified redaction nobody marked verified
- **WHEN** the batch is published
- **THEN** the publish is refused and names that document with "redaction not checked by a person"

#### Scenario: Marking a failed redaction verified is refused
<!-- @e2e exclude API refusal; proven by WooControllerTest::testVerifyRedactionNeedsAVerifiedFile. -->
- **GIVEN** a partly public document whose `redactionStatus` is `failed`
- **WHEN** an officer asks to mark its redaction verified
- **THEN** the answer is 409 naming the redaction status and nothing is stored

#### Scenario: The officer verifies and the batch can go out
- **GIVEN** a partly public document with a verified redacted file
- **WHEN** the officer chooses Mark redaction verified on its redaction page
- **THEN** the page shows the officer's name and the time under Verified
- **AND** the document leaves the list of documents that cannot be published yet
