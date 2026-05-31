# retrofit-2026-05-26-menu-page-management Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-26-menu-page-management. Update Purpose after archive.
## Requirements
### Requirement: Menu view/edit modal (REQ-MENU-001)
The menu modal MUST present a menu's state and items, MUST allow adding, editing, deleting, and reordering items, MUST fetch available groups for scoping, MUST validate input, and MUST save the menu; the delete-menu-item modal MUST confirm and remove a single item.

#### Scenario: Item reorder persists
- **GIVEN** a menu with multiple items
- **WHEN** an item position update is applied and the menu is saved
- **THEN** the new ordering MUST be persisted

### Requirement: Menu item form (REQ-MENU-002)
The menu-item form MUST let a user configure a menu item including icon selection (building and filtering the available icon-option list per prefix, formatting/prettifying SVG), group scoping (normalized), value mode (single/multiline with encode/decode), and footer positioning, then save the item.

#### Scenario: Multiline value round-trips
- **GIVEN** a multiline value entered in the form
- **WHEN** the value is encoded for storage and decoded for display
- **THEN** the decoded value MUST equal the originally entered value

### Requirement: Page view/edit modal (REQ-MENU-003)
The page modal MUST present a page's state and its ordered content blocks, MUST allow adding, editing, and deleting content, MUST fetch and normalize groups, MUST validate input, and MUST save the page.

#### Scenario: Contents shown in order
- **GIVEN** a page with multiple content blocks
- **WHEN** the modal renders
- **THEN** the content blocks MUST be presented in their sorted order

### Requirement: Page content form (REQ-MENU-004)
The page-content form MUST let a user create or edit a content block (with group normalization and validation) and the delete-page-content dialog MUST confirm and remove a content block from its page.

#### Scenario: Content block deleted
- **GIVEN** a content block on a page
- **WHEN** the delete dialog is confirmed
- **THEN** the content block MUST be removed from the page

### Requirement: Copy menu (REQ-MENU-005)
The copy-menu dialog MUST allow duplicating a menu and MUST close on completion.

#### Scenario: Dialog closes after copy
- **GIVEN** the copy-menu dialog is open
- **WHEN** the close action is invoked
- **THEN** the dialog MUST close

