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

### Requirement: A publication keeps one identifier for life (REQ-CVS-006)

Every publication MUST carry one identifier, its UUID, that never changes. The
public API MUST return it as the publication's `id`, the DCAT feed MUST emit it
as `dct:identifier`, and `GET /api/{catalogSlug}/{uuid}` MUST keep answering for
it after the publication's title, slug or other fields are edited.

#### Scenario: A citation survives an edit
- **GIVEN** a public publication with UUID "4f2c9a1e-7b3d-4e8a-9c51-2d6f0b8e3a17" in catalogue `woo-requests`
- **WHEN** an editor changes its title and slug
- **THEN** `GET /api/woo-requests/4f2c9a1e-7b3d-4e8a-9c51-2d6f0b8e3a17` still returns it with the same `id`
- **AND** its dataset in the DCAT feed still carries that UUID as `dct:identifier`
