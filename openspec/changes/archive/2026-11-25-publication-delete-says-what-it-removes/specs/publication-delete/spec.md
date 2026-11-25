# publication-delete

## ADDED Requirements

### Requirement: Deleting a publication says what disappears (REQ-PDEL-001)

Deleting one publication from the Publications page MUST ask for
confirmation in a dialog titled "Delete publication" that names the
publication, says the action cannot be undone, and SHALL say that the
publication and its attachments disappear from the public page and from the
Woo sitemap and that archiving keeps them for the retention term instead.
Cancel MUST delete nothing.

@e2e tests/e2e/publication-delete.spec.ts

#### Scenario: The editor reads the consequence before deleting

- **GIVEN** publication "Woo decision on the swimming pool tender"
- **WHEN** an editor chooses Delete in its row actions
- **THEN** the dialog title is "Delete publication"
- **AND** the dialog asks "Do you want to delete "Woo decision on the swimming pool tender"? This action cannot be undone."
- **AND** it says the publication and its attachments disappear from the public page and from the Woo sitemap

#### Scenario: Cancel keeps the publication

- **GIVEN** the delete dialog for "Woo decision on the swimming pool tender"
- **WHEN** the editor clicks Cancel
- **THEN** the publication is still in the list

### Requirement: Other schemas in a catalog keep the generic dialog (REQ-PDEL-002)

The publication delete text MUST apply only to the publication register and
schema pair. Deleting a record of another pair in the same catalog SHALL show
the library's generic delete dialog.

@e2e tests/e2e/publication-delete.spec.ts

#### Scenario: A publiccode record gets the generic text

- **GIVEN** a catalog with a publication pair and a publiccode pair
- **WHEN** an editor deletes a publiccode record
- **THEN** the dialog title is the library's "Delete item"
