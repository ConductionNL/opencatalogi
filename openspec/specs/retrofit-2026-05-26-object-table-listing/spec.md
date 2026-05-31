# retrofit-2026-05-26-object-table-listing Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-26-object-table-listing. Update Purpose after archive.
## Requirements
### Requirement: Schema-driven object table columns (REQ-TBL-001)
The generic object table MUST derive its displayed columns from the active schema's properties and the object metadata, MUST honour the enabled/ordered column configuration, and MUST render each cell value from the matching object property.

#### Scenario: Columns reflect schema properties
- **GIVEN** a schema with named properties
- **WHEN** the table renders
- **THEN** the property columns MUST correspond to those schema properties in the configured order

### Requirement: Table selection (REQ-TBL-002)
The table MUST support selecting individual rows and selecting/deselecting all rows, exposing the selected set and the all/some-selected state.

#### Scenario: Select-all toggles every row
- **GIVEN** a table with multiple rows
- **WHEN** the select-all control is toggled on
- **THEN** every row MUST become selected and the all-selected state MUST be true

### Requirement: Table actions and pagination (REQ-TBL-003)
The table MUST offer per-object actions, mass actions over the selection, and a generic action executor that respects per-action disabled state, MUST handle row click and open-link navigation, and MUST paginate its rows with page and page-size controls and a view-mode toggle.

#### Scenario: Disabled action is not executed
- **GIVEN** an action marked disabled for an object
- **WHEN** the action is requested
- **THEN** the executor MUST NOT run the action

### Requirement: Pagination component (REQ-TBL-004)
The pagination component MUST allow changing the current page and the page size, and MUST compute the window of visible page numbers.

#### Scenario: Page change emitted
- **GIVEN** a paginated list
- **WHEN** the user selects a different page
- **THEN** the component MUST emit the new page

### Requirement: Publication card (REQ-TBL-005)
The publication card MUST derive and display a publication's title, summary (truncated when long), status, formatted date, and attached-file count.

#### Scenario: Long summary truncated
- **GIVEN** a publication with a summary exceeding the display limit
- **WHEN** the card renders
- **THEN** the displayed summary MUST be truncated

### Requirement: Markdown editor wrapper (REQ-TBL-006)
The markdown editor MUST bind its content two-way and invoke a change handler when the content changes.

#### Scenario: Content change handled
- **GIVEN** the markdown editor is mounted
- **WHEN** the content changes
- **THEN** the change handler MUST be invoked with the new content

