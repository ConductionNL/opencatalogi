# publications

## ADDED Requirements

### Requirement: A publication row starts with its status icon (REQ-PSI-001)

In the table view of the Publications page, each publication row MUST start with the status icon its card shows,
colored by that status: a green list icon for a published publication, an orange pencil for a draft, and a red alert
for a depublished one. The status SHALL come from the publication's dates, as on the card. A row of another schema in
the catalog MUST NOT show a status icon.

@e2e tests/e2e/publication-list-status-icons.spec.ts

#### Scenario: A published publication starts with a green list icon

- **GIVEN** a publication whose publication date has passed and that has no depublication date
- **WHEN** an editor opens the Publications page in the table view
- **THEN** its row starts with the list icon in the success color

#### Scenario: A draft starts with an orange pencil

- **GIVEN** a publication without a publication date
- **WHEN** an editor opens the Publications page in the table view
- **THEN** its row starts with the pencil icon in the warning color

#### Scenario: A depublished publication starts with a red alert

- **GIVEN** a publication whose publication and depublication dates have both passed
- **WHEN** an editor opens the Publications page in the table view
- **THEN** its row starts with the alert icon in the error color
