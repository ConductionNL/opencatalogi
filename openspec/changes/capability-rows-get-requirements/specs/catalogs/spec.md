# catalogs

## ADDED Requirements

### Requirement: Catalogues of several organisations show one publication without copying it (REQ-CRR-002)

A catalogue SHALL select its publications by register, schema and optional
filters, and MUST NOT hold copies of them. Each catalogue MUST carry its own
owning organisation. A publication that matches the selection of two
catalogues MUST appear in both, as the same object.

#### Scenario: Two organisations list the same decision

- **GIVEN** catalogue "Gemeente A" and catalogue "Omgevingsdienst" of two organisations, both selecting schema `publication` with theme "Milieu"
- **WHEN** a publication with theme "Milieu" is published
- **THEN** it appears in the public list of both catalogues with the same id

#### Scenario: An edit shows in both catalogues

- **GIVEN** that publication in both catalogues
- **WHEN** an editor changes its title
- **THEN** both catalogues show the new title, and no second object exists
