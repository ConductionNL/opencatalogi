# publication-selection-actions

## ADDED Requirements

### Requirement: The selection bar opens the mass dialogs on the selected publications (PSA-001)

The Publications page SHALL offer Publish, Depublish, Lock, Unlock and Validate
in its selection bar while publications are selected. Each MUST open its mass
dialog with exactly the selected publications. Which dialog an action opens
SHALL be set in the manifest, not in code.

@e2e tests/e2e/publications-selection-actions.spec.ts

#### Scenario: Publishing two selected publications

- **GIVEN** the Publications list of catalog "Woo-verzoeken" with "Besluit op het Woo-verzoek over windpark Oostpolder" and "Raadsstukken voor de vergadering van 15 oktober" selected
- **WHEN** an editor chooses "Publish" in the selection bar
- **THEN** the publish dialog opens listing those two publications

#### Scenario: Locking three selected publications

- **GIVEN** three selected publications
- **WHEN** an editor chooses "Lock"
- **THEN** the lock dialog opens with "Selected publications (3)", an optional process name and an optional duration

#### Scenario: No selection staged, nothing opens

- **GIVEN** a bulk action whose rows are no longer on the page
- **WHEN** it is chosen
- **THEN** no dialog opens

### Requirement: The list reloads after a selection action (PSA-002)

When a dialog opened from the selection bar closes, the page MUST clear the
selection and reload the list, so the rows show what the dialog changed.

@e2e tests/e2e/publications-selection-actions.spec.ts

#### Scenario: Locked rows show their new state

- **GIVEN** the lock dialog opened from the selection bar
- **WHEN** the editor locks the publications and closes the dialog
- **THEN** the selection is empty
- **AND** the list is fetched again

### Requirement: Selection actions follow the delete right (PSA-003)

The selection actions MUST NOT show when the page's built-in delete action is
switched off.

@e2e exclude A manifest toggle; CatalogPublicationsIndex.spec.js mounts the page with showDeleteAction false and asserts no selection actions.

#### Scenario: A page without delete

- **GIVEN** the Publications page with `showDeleteAction` false
- **WHEN** a user selects publications
- **THEN** the selection bar has none of the declared selection actions
