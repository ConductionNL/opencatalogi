---
status: proposed
---

# Woo metadata suggestions

## ADDED Requirements

### Requirement: Missing Woo metadata is proposed from data the instance holds (REQ-WMS-001)

When a publication lacks its information category, publisher organisation or handling type, the app SHALL propose a value from data it already holds and SHALL record the source and a reason with it. Every proposed value SHALL resolve against the TOOI and DiWoo value lists; a value that does not resolve SHALL NOT be proposed. A field that already has a value SHALL get no proposal.

#### Scenario: A synchronised record arrives without a category

- **GIVEN** a publication created by a synchronisation in a Woo catalogue schema for category 2.1 and no category set
- **WHEN** an editor opens the publication page
- **THEN** a pending suggestion for the category shows the value, the source rules and the reason

#### Scenario: A field someone already set

- **GIVEN** a publication whose handling type an editor set to vaststelling
- **WHEN** the rules floor runs for that publication
- **THEN** no suggestion for the handling type is created

### Requirement: Hermiq may add suggestions, never replace them (REQ-WMS-002)

An editor SHALL be able to ask Hermiq for suggestions from the text of the publication's documents. Hermiq's suggestions SHALL only add to the rules floor, SHALL be checked against the same value lists, and SHALL be marked with the source hermiq. Without Hermiq, the action SHALL not be offered.

#### Scenario: An editor asks Hermiq

- **GIVEN** Hermiq is installed and a publication lacks a summary and a category
- **WHEN** the editor presses Ask Hermiq on the publication page
- **THEN** new pending suggestions appear marked hermiq, and the rules suggestions stay as they were

#### Scenario: Hermiq is not installed

- **GIVEN** Hermiq is not installed
- **WHEN** an editor opens the publication page
- **THEN** no Ask Hermiq action is shown

### Requirement: Nothing is written until a person accepts (REQ-WMS-003)

A suggestion SHALL change the publication only when a person with the right to update that publication accepts it. Accepting SHALL write the value and record who accepted it and when. Rejecting SHALL leave the publication unchanged. A user without update rights SHALL be refused.

#### Scenario: An editor accepts a category

- **GIVEN** a pending category suggestion on a publication
- **WHEN** the editor presses Accept
- **THEN** the publication's category holds the value
- **AND** the suggestion shows accepted with the editor's name and the time

#### Scenario: A reader without update rights

- **GIVEN** a user who can read but not update the publication
- **WHEN** they call `POST /api/metadata-suggestions/{id}/accept`
- **THEN** the call is refused and the publication is unchanged
