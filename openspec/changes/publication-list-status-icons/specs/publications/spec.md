# publications

## ADDED Requirements

### Requirement: A publication row starts with its status icon (REQ-PSI-001)

In the table view of the Publications page, each publication row MUST start with a status icon, and its card MUST show the same icon and color. The status SHALL be the `state` the server sends per row (publication-lifecycle-on-or REQ-PLC-002), the value the State column shows; the browser SHALL NOT derive it from the publication's dates. The icons are: a green list icon for `public`, an orange clock for `scheduled`, an orange pencil for `draft`, `in_review` and `approved`, a red alert for `withdrawn`, and a blue archive icon for `archived`. A row of another schema in the catalog, or a row without a known state, MUST NOT show a status icon.

@e2e tests/e2e/publication-list-status-icons.spec.ts

#### Scenario: A public publication starts with a green list icon

- **GIVEN** a publication in stored state `published` whose publication date has passed and that has no depublication date
- **WHEN** an editor opens the Publications page in the table view
- **THEN** its row starts with the list icon in the success color

#### Scenario: A draft starts with an orange pencil, whatever its dates

- **GIVEN** a publication in stored state `draft` with a publication date that has passed
- **WHEN** an editor opens the Publications page in the table view
- **THEN** its row starts with the pencil icon in the warning color

#### Scenario: A withdrawn publication starts with a red alert

- **GIVEN** a publication in stored state `published` whose publication and depublication dates have both passed
- **WHEN** an editor opens the Publications page in the table view
- **THEN** its row starts with the alert icon in the error color
