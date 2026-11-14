# woo-transparency

## ADDED Requirements

### Requirement: A batch goes to review only once every document in it is assessed (REQ-CRG-001)

The system MUST refuse to mark a Woo batch ready for review while any of its
documents is still to be assessed (`te_beoordelen`), and MUST refuse a batch
that holds no documents. The batch page SHALL offer the action only when the
batch passes this check.

#### Scenario: An unassessed document blocks the review

- **GIVEN** a batch with three documents, two assessed and one still to be assessed
- **WHEN** the officer marks the batch ready for review
- **THEN** the request is refused and the batch stays in progress
- **AND** the batch page does not offer the action

#### Scenario: Every document assessed

- **GIVEN** the same batch after the third document is assessed as public
- **WHEN** the officer marks the batch ready for review
- **THEN** the batch is ready for review

#### Scenario: An empty batch

- **GIVEN** a batch without documents
- **WHEN** the officer marks it ready for review
- **THEN** the request is refused
