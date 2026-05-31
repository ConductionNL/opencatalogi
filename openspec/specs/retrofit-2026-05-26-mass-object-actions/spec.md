# retrofit-2026-05-26-mass-object-actions Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-26-mass-object-actions. Update Purpose after archive.
## Requirements
### Requirement: Mass publish/depublish with date and mode (REQ-MASS-001)
The mass publish and depublish dialogs MUST act on the current object selection, MUST support an optional publish/depublish date (validated against a minimum date), and MUST support a mode that distinguishes immediate from scheduled state changes.

#### Scenario: Past date rejected
- **GIVEN** a publish date earlier than the minimum allowed date
- **WHEN** the date is entered
- **THEN** the dialog MUST flag the date as invalid and disable submission

### Requirement: Already-in-state and unsupported warnings (REQ-MASS-002)
The mass publish/depublish dialogs MUST detect objects already in the target state and objects that do not support the action, MUST surface counts and warnings for both, and MUST exclude unsupported objects from the operation.

#### Scenario: Already-published objects are counted
- **GIVEN** a selection containing already-published objects
- **WHEN** the mass-publish dialog opens
- **THEN** the count of already-published objects MUST be shown as a warning

### Requirement: Mass delete (REQ-MASS-003)
The mass delete dialog MUST act on the current selection, confirm the count, perform the deletion, and navigate to the deleted view on success.

#### Scenario: Delete confirms count
- **GIVEN** a selection of objects to delete
- **WHEN** the dialog opens
- **THEN** the dialog title MUST reflect the number of objects to delete

### Requirement: Mass lock/unlock (REQ-MASS-004)
The mass lock and unlock dialogs MUST act on the current selection, confirm the count, and apply the lock/unlock to each selected object.

#### Scenario: Lock applies to selection
- **GIVEN** a selection of unlocked objects
- **WHEN** the mass-lock action runs
- **THEN** each selected object MUST be locked

### Requirement: Mass validate (REQ-MASS-005)
The mass validate dialog MUST act on the current selection, confirm the count, and validate each selected object against its schema.

#### Scenario: Validate applies to selection
- **GIVEN** a selection of objects
- **WHEN** the mass-validate action runs
- **THEN** each selected object MUST be validated

### Requirement: Mass attachment publish/depublish (REQ-MASS-006)
The mass attachment dialog MUST act on a filtered set of attachment ids initialized from the selection, confirm the filtered count, and publish/depublish those attachments.

#### Scenario: Attachment selection initialized
- **GIVEN** the dialog is opened with a selection
- **WHEN** initialization runs
- **THEN** the filtered attachment ids MUST be derived from the selection

### Requirement: Selection list components (REQ-MASS-007)
The selected-objects and selected-attachments list components MUST display each selected item with its name/subtitle/schema (or size), surface per-item errors and disabled reasons, and allow removing an item from the selection.

#### Scenario: Item can be removed
- **GIVEN** a selected item shown in the list
- **WHEN** its remove control is used
- **THEN** the item MUST be removed from the selection

