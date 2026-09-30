---
status: proposed
---

# Publish and withdraw in one action

## ADDED Requirements

### Requirement: The server tells the page whether a publication is public (REQ-PPW-001)

`GET /api/publications/{id}/visibility` SHALL answer the publication's state as one of draft, scheduled, public, withdrawn or archived, computed from its publication date, depublication date and lifecycle status. It SHALL refuse a user who cannot read the publication.

#### Scenario: A publication with a past publication date

- **GIVEN** a publication with a publication date last week and no depublication date
- **WHEN** an editor's page calls `GET /api/publications/{id}/visibility`
- **THEN** the answer is public

#### Scenario: A withdrawn publication

- **GIVEN** a publication whose depublication date has passed
- **WHEN** the page asks for its visibility
- **THEN** the answer is withdrawn

### Requirement: An editor publishes or withdraws a publication in one action (REQ-PPW-002)

The publication page SHALL offer Publish now for a draft or scheduled publication and Withdraw for a public or scheduled one, each only when it applies. Publish now SHALL make the publication public at once. Withdraw SHALL require a reason, take the publication down at once, record who did it and why, and send a withdrawal to every channel the publication reached. Only a user who may update the publication SHALL be able to do either.

#### Scenario: An editor withdraws a publication published by mistake

- **GIVEN** a public publication that reached the national Woo-index
- **WHEN** the editor chooses Withdraw, enters the reason "Wrong annex attached" and confirms
- **THEN** readers of the public API no longer get the publication
- **AND** a depublication with the editor's name, the reason and a withdrawal for the Woo-index channel is stored
- **AND** the message names any channel that did not confirm

#### Scenario: A reader without update rights

- **GIVEN** a user who can read but not update a public publication
- **WHEN** they call `POST /api/publications/{id}/withdraw`
- **THEN** the call is refused and the publication stays public

### Requirement: A withdrawn publication can be published again (REQ-PPW-003)

A withdrawn publication SHALL offer Publish again, which makes it public at once. Earlier depublications SHALL stay in its history. Archive SHALL remain the only final step.

#### Scenario: An editor restores a publication after fixing it

- **GIVEN** a withdrawn publication
- **WHEN** the editor chooses Publish again and confirms
- **THEN** the publication is public
- **AND** its earlier depublication is still listed

### Requirement: One document comes down without its publication (REQ-PPW-004)

An editor SHALL be able to withdraw one document of a publication with a reason. The document SHALL leave the public API and the DiWoo sitemap at once, the rest of the publication SHALL stay public, and a depublication naming the document SHALL be stored.

#### Scenario: An annex with personal data

- **GIVEN** a public publication with a decision and two annexes
- **WHEN** the editor withdraws one annex with the reason "Contains a home address"
- **THEN** the harvester no longer finds that annex in the category sitemap
- **AND** the decision and the other annex are still listed
- **AND** a depublication names the annex, the editor and the reason
