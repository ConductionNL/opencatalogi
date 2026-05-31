# Capability: generic-dialogs

## ADDED Requirements

### Requirement: Destructive confirmation dialogs (REQ-DLG-001)
Each delete dialog (object, attachment, category, multiple categories, listing, multiple themes) MUST require explicit confirmation, MUST perform the deletion of the targeted entity or selection, and MUST refresh the affected list and close on completion.

#### Scenario: Delete requires confirmation
- **GIVEN** a delete dialog is open
- **WHEN** the user confirms
- **THEN** the targeted entity (or selection) MUST be deleted and the list refreshed

### Requirement: Copy object dialog (REQ-DLG-002)
The copy-object dialog MUST duplicate the targeted object and close on completion.

#### Scenario: Object copied
- **GIVEN** the copy-object dialog is open for an object
- **WHEN** the copy is confirmed
- **THEN** a duplicate object MUST be created and the dialog closed

### Requirement: Publish publication dialog (REQ-DLG-003)
The publish-publication dialog MUST publish the targeted publication and close on completion.

#### Scenario: Publication published
- **GIVEN** the publish dialog is open for a publication
- **WHEN** the publish is confirmed
- **THEN** the publication MUST be published and the dialog closed
