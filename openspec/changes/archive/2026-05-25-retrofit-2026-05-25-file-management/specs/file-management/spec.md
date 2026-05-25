---
retrofit_extensions:
  - FIL-016
  - FIL-017
  - FIL-018
  - FIL-019
---

# File Management

## ADDED Requirements

### Requirement: Upload files to a publication from the frontend (FIL-016)
The system SHALL provide an `UploadFiles` modal that uploads one or more files to a
publication's OpenRegister files endpoint
(`/index.php/apps/openregister/api/objects/{register}/{schema}/{publicationId}/files`,
PUT for an existing file id, with file content and optional tags), reading the active
publication's register/schema/id from the object store and supporting tag assignment via
`/api/tags`.

**Priority:** Must **Status:** Implemented

#### Scenario: Upload a file to the active publication
- GIVEN the upload modal is open with the active publication selected
- WHEN the user uploads a file
- THEN the file MUST be sent to the publication's OpenRegister `.../files` endpoint
- AND any selected tags MUST be applied

### Requirement: Delete a publication attachment (FIL-017)
The system SHALL provide a `DeleteAttachmentDialog` that deletes the active
`publicationAttachment` by issuing `DELETE` to the OpenRegister files endpoint
`/api/objects/{register}/{schema}/{publicationId}/files/{attachmentId}` (register/schema/id
read from the active publication's `@self`), then refreshes the publication's attachments
and closes the dialog after a short delay.

**Priority:** Must **Status:** Implemented

#### Scenario: Delete an attachment
- GIVEN the active publication and the active attachment
- WHEN the delete-attachment dialog is confirmed
- THEN a `DELETE` request MUST be sent to the `.../files/{attachmentId}` endpoint
- AND the publication's attachments MUST be refreshed afterward

### Requirement: Edit attachment metadata (FIL-018)
The system SHALL provide an `EditAttachmentModal` that updates an attachment's metadata via
`objectStore.updateObject('attachment', id, attachment)` and closes the modal through the
navigation store on completion.

**Priority:** Should **Status:** Implemented

#### Scenario: Edit an attachment
- GIVEN the edit-attachment modal is open
- WHEN the user saves changes
- THEN the attachment MUST be persisted via `objectStore.updateObject('attachment', id, attachment)`

### Requirement: File-selection composable and mass-attachment modal (FIL-019)
The system SHALL provide a `useFileSelection` composable exposing drop-zone state, a file
list, tag setters, duplicate rejection, and reset/open helpers
(`openFileUpload`, `files`, `setFiles`, `setTags`, `reset`, `isOverDropZone`,
`rejectedDuplicates`), and a `MassAttachmentModal` for bulk attachment operations built on
top of it.

**Priority:** Should **Status:** Implemented

#### Scenario: Select files via the composable
- GIVEN a component using `useFileSelection`
- WHEN files are dropped or chosen
- THEN the composable's file list MUST update and duplicates MUST be rejected
