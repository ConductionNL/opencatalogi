# Publications

## Purpose

A publication may be saved incomplete as a draft; the fields a public record needs are required from review on. Design: `../../design.md`. Boards `OcNieuwePublicatie`, `OcPublicatieBewerken` and `OcPubliceren` on canvas 5NkFW28vZUUij43xzxHg5a.

## ADDED Requirements

### Requirement: A draft needs only a title (REQ-PRP-001)

The publication schema SHALL declare, in its `x-openregister-lifecycle` states, no required fields beyond `title` for state `draft`, and `summary`, `description` and `organization` required for states `in_review`, `approved` and `published`.

#### Scenario: An editor saves an incomplete draft
<!-- @e2e exclude Server-side rule; proven by PublicationRequiredAtPublishTest::testADraftWithOnlyATitleIsSaved. -->
- **GIVEN** a new publication with only the title "Council papers for 15 October"
- **WHEN** the editor saves it
- **THEN** it is stored in state `draft`

### Requirement: Publishing refuses a publication missing a required field (REQ-PRP-002)

Every transition into `in_review`, `approved` or `published` SHALL be refused by OpenRegister while `summary`, `description` or `organization` is empty, and the refusal SHALL name each missing field. OpenCatalogi SHALL NOT publish through any path that bypasses the transition.

#### Scenario: Publish now names the missing fields
<!-- @e2e exclude Server-side rule; proven by PublicationRequiredAtPublishTest::testPublishingWithoutSummaryIsRefusedByName. -->
- **GIVEN** a draft with a title and an organisation but no summary and no description
- **WHEN** an editor publishes it
- **THEN** the publish is refused naming `summary` and `description`
- **AND** the publication stays a draft

#### Scenario: A Woo batch publish follows the same rule
<!-- @e2e exclude Server-side path; proven by BatchPublicationWriterTest::testABatchPublicationGoesThroughTheTransition. -->
- **GIVEN** a Woo batch whose decision publication has no organisation
- **WHEN** the batch is published
- **THEN** the publish is refused naming `organization`

### Requirement: The forms say what is needed to publish (REQ-PRP-003)

The new and edit publication forms SHALL mark summary, description and organisation "Required to publish". The publish dialog SHALL list, before the editor confirms, each selected publication that misses a field with the names of those fields, and SHALL publish only the others.

#### Scenario: Two selected, one incomplete
- **GIVEN** two selected publications, one without a summary
- **WHEN** the editor opens Publish publications
- **THEN** the dialog names the incomplete one with "summary"
- **AND** the button reads "Publish 1 of 2 publications"
