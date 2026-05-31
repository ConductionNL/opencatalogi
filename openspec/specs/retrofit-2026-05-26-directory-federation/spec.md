# retrofit-2026-05-26-directory-federation Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-26-directory-federation. Update Purpose after archive.
## Requirements
### Requirement: Publication-type management (REQ-DIR-001)
The directory side bar MUST allow creating, copying, and deleting publication types, MUST toggle a publication type's enablement, MUST resolve a publication type's id, and MUST reflect the set of checked/switched publication types.

#### Scenario: Publication type toggled
- **GIVEN** a listed publication type
- **WHEN** its enablement toggle is changed
- **THEN** the publication type's enabled state MUST be updated

### Requirement: Directory synchronization (REQ-DIR-002)
The directory side bar MUST synchronize the directory and individual publication types with their source, and MUST resolve the active listing item and open external links.

#### Scenario: Directory synchronized
- **GIVEN** the directory side bar
- **WHEN** the synchronize-directory action runs
- **THEN** the directory listings MUST be refreshed from their source

### Requirement: Directory listing modals (REQ-DIR-003)
The add-directory and view-directory modals MUST display a listing (with formatted dates and action labels) and MUST close on completion.

#### Scenario: Listing displayed
- **GIVEN** a listing passed to the view-directory modal
- **WHEN** the modal renders
- **THEN** the listing's fields MUST be displayed with formatted dates

