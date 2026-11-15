# publications

## ADDED Requirements

### Requirement: An editor creates a publication with a title, summary, description and themes (REQ-CRR-001)

An editor MUST be able to create a publication from the Publications page of a
catalogue. The form SHALL offer a title, a summary, a description and one or
more themes. The themes are the publication's category. A saved publication
MUST be listed in its catalogue as a draft until it is published.

#### Scenario: An editor adds a publication

- **GIVEN** an editor on the Publications page of catalogue "Woo-publicaties"
- **WHEN** they choose Add, enter the title "Besluit subsidie buurthuis", a summary and a description, pick the theme "Welzijn" and save
- **THEN** the catalogue lists "Besluit subsidie buurthuis" as a draft
- **AND** opening it shows the summary, the description and the theme "Welzijn"

#### Scenario: A publication without a title is not saved

- **GIVEN** the same form with an empty title
- **WHEN** the editor saves
- **THEN** the save is refused and the title field says it is required
