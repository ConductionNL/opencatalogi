# publications

## ADDED Requirements

### Requirement: An editor links a publication to related publications (REQ-CRG-003)

The publication schema MUST declare a property that holds references to other
publications. The publication page MUST let an editor add and remove related
publications, and MUST list both the publications it links to and the ones that
link to it, by title. The public API MUST return the related publications that
the reader may read, and MUST leave out the ones the reader may not read.

#### Scenario: An editor links a decision to its request

- **GIVEN** publications "Besluit op Woo-verzoek Oostpolder" and "Woo-verzoek Oostpolder"
- **WHEN** an editor adds "Woo-verzoek Oostpolder" as related on the decision and saves
- **THEN** the decision's page lists "Woo-verzoek Oostpolder" under related publications
- **AND** the request's page lists the decision as linking to it

#### Scenario: A draft stays out of the public answer

- **GIVEN** a public publication related to a draft publication
- **WHEN** an anonymous reader reads the public publication through the API
- **THEN** the draft is not among its related publications
