# publication-selection-actions Specification

## Purpose
The Publications page's selection bar opens the mass dialogs (publish, depublish, lock, unlock, validate, merge, migrate) on the selected publications, reloads the list when a dialog closes, and follows the page's delete right. Created by archiving change publications-selection-actions (2026-11-24); holds PSA-001 to PSA-005.

## Requirements

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

### Requirement: Publish and depublish work on any schema with publication date fields (PSA-004)

Publish and Depublish SHALL be offered in the selection bar of every catalog,
also one whose page pair is not the publication schema, but only while at
least one selected object can be published or depublished: its schema has
the date property the action writes, and it is not yet published (Publish)
or it is published or scheduled (Depublish). Each selected object
MUST be handled by its own schema's date properties: `publicationDate`, else
`publicatiedatum`, for the publication date (P) and `depublicationDate`, else
`depublicatiedatum`, for the depublication date (D). Its status MUST follow
the publication window: Depublished when D is set and on or before today,
else Concept when P is not set, Scheduled when P is after today, and
Published otherwise.

Depublish MUST set D on a published object. On a scheduled object it MUST set
D when D falls after P, and otherwise clear P, so the object goes back to
Concept rather than keep a window that never opens. It MUST skip concepts,
depublished objects and objects whose schema has no depublication date.
Publish MUST set P and clear D (when the schema has one), and skip published
objects and objects whose schema has no publication date.

@e2e tests/e2e/publications-selection-actions.spec.ts

#### Scenario: Depublishing a Woo document with Dutch date fields

- **GIVEN** a catalog whose schema "Vergaderstukken decentrale overheden" declares `publicatiedatum` and `depublicatiedatum`
- **AND** a selected document with `publicatiedatum` 2026-01-10 and no `depublicatiedatum`
- **WHEN** an editor depublishes it now on 2026-06-15
- **THEN** its `depublicatiedatum` is saved as 2026-06-15 and its `publicatiedatum` stays 2026-01-10

@e2e exclude no Dutch-named schema ships in the e2e seed; the property-name resolution is unit-level, covered by publicationWindow.spec.js and MassPublicationDates.spec.js

#### Scenario: Depublish is not offered for a selection it would skip

- **GIVEN** a catalog whose schema declares `publicatiedatum` but no depublication date
- **WHEN** an editor selects a document of it
- **THEN** the selection bar offers no Depublish

@e2e exclude no schema without a depublication date ships in the e2e seed; covered by CatalogPublicationsIndex.spec.js

#### Scenario: Only Publish is offered for a selected concept

- **GIVEN** only a publication without a publication date is selected
- **WHEN** the editor looks at the selection bar
- **THEN** it offers Publish and no Depublish

#### Scenario: Depublishing a scheduled publication

- **GIVEN** a selected publication with `publicationDate` 2026-12-01
- **WHEN** an editor depublishes it now on 2026-06-15
- **THEN** its `publicationDate` is cleared and it is a concept again

#### Scenario: A concept is not depublished

- **GIVEN** a selected publication without a publication date
- **WHEN** an editor opens Depublish
- **THEN** the dialog says it is a concept and will be skipped

### Requirement: A row can be published or depublished on its own (PSA-005)

Every row of a schema with publication date fields SHALL offer Publish while
it is not published and Depublish while it is published or scheduled, in its
row actions. Each MUST open the same dialog as the selection bar with only
that row. When the dialog closes the list MUST reload, and the selection MUST
stay as it was.

@e2e tests/e2e/publications-selection-actions.spec.ts

#### Scenario: Depublishing one published row

- **GIVEN** a published row "Raadsstukken voor de vergadering van 15 oktober"
- **WHEN** an editor chooses "Depublish" in its row actions
- **THEN** the depublish dialog opens listing that one row

#### Scenario: A concept row offers no Depublish

- **GIVEN** a row without a publication date
- **WHEN** an editor opens its row actions
- **THEN** they show Publish and not Depublish
