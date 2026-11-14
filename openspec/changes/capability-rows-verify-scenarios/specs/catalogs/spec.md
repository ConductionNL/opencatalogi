# catalogs

## ADDED Requirements

### Requirement: An administrator chooses the kinds of publication a catalogue holds (REQ-CVS-002)

The catalogue form MUST let an administrator pick one or more registers and one
or more schemas for the catalogue. The saved choice MUST decide which
publications the catalogue lists, on its staff page and on `GET /api/{catalogSlug}`.

#### Scenario: A catalogue gets a second kind of publication
- **GIVEN** catalogue "Raadsstukken" that holds only the schema "agenda"
- **WHEN** an administrator edits it, adds the schema "besluit" and saves
- **THEN** `GET /api/raadsstukken` lists agendas and decisions
- **AND** reopening the form shows both schemas selected

#### Scenario: A schema taken off the catalogue
- **GIVEN** the same catalogue with "agenda" and "besluit"
- **WHEN** the administrator removes "agenda" and saves
- **THEN** `GET /api/raadsstukken` no longer lists agendas
