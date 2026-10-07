---
status: proposed
---

# Woo batch creates publications

## ADDED Requirements

### Requirement: Publishing a Woo batch creates a public publication with its documents attached (REQ-WBP-001)

Implements hydra `woo-citizen-journey`: Both publishing paths MUST create a public, searchable publication.

Publishing an approved batch SHALL create one `publication` with `publicationKind: actief`, the batch's `wooCategory` (default `infocat014`), its `caseReference`, a `publicationDate` of the moment of publishing, and every disclosable document attached as a published file. `niet_openbaar` documents SHALL NOT be attached, and `deels_openbaar` documents SHALL be attached as their redacted version. The batch SHALL record the publication's id and URL.

#### Scenario: A published batch is found

- **GIVEN** an approved Woo batch with one `openbaar`, one `deels_openbaar` and one `niet_openbaar` document
- **WHEN** the editor publishes it
- **THEN** a publication with `publicationKind: actief` exists with two published files: the first document and the redacted second
- **AND** the batch's `wooPublication.publication` names it

### Requirement: The approval gate stays, and a missing document stops the publish (REQ-WBP-002)

Implements hydra `woo-citizen-journey` design C6 (the batch keeps its approval gate).

Publishing SHALL still require `ready_for_review` and a completed approval. Every document SHALL be resolved to a file before anything is written; when one cannot be found, publishing SHALL fail with that document's name and SHALL create no publication. Publishing a batch again after a partial failure SHALL reuse the recorded publication.

#### Scenario: A document is missing

- **GIVEN** an approved batch whose second document no longer exists
- **WHEN** the editor publishes it
- **THEN** the publish fails naming that document
- **AND** no publication is created and the batch stays `ready_for_review`

#### Scenario: Not approved

- **GIVEN** a batch in `ready_for_review` without a completed approval
- **WHEN** the editor publishes it
- **THEN** the publish is refused and no publication is created
