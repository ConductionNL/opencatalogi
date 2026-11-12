# publications

## ADDED Requirements

### Requirement: A field an administrator adds to the publication schema shows on the publication form (REQ-CVS-001)

When an administrator adds a property to the publication schema in
OpenRegister's schema editor, the publication form on the Publications page
MUST offer that property, and saving MUST store it on the publication. The
public publication endpoint MUST serve it like the shipped properties. This
MUST need no change to OpenCatalogi's code or manifest.

#### Scenario: An administrator adds a case number
- **GIVEN** an administrator who adds the text property `zaaknummer` to the publication schema in OpenRegister
- **WHEN** an editor opens Add on the Publications page of catalogue "Woo-publicaties"
- **THEN** the form offers the field `zaaknummer`
- **AND** a publication saved with `zaaknummer` "Z-2026-00871" returns that value from `GET /api/woo-publicaties/{id}` once it is public

#### Scenario: An added field that is required
- **GIVEN** the same property, marked required in the schema
- **WHEN** an editor saves a publication without it
- **THEN** the save is refused and the form names `zaaknummer`
