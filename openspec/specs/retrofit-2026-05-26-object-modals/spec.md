# retrofit-2026-05-26-object-modals Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-26-object-modals. Update Purpose after archive.

> @e2e exclude Whole-spec reverse-engineered Vue object-modal component-logic capability — every scenario asserts component-internal behaviour (modal title/schema/property derivation from the active object, required-field validation blocking save, form↔JSON two-way sync, oversized-file rejection, per-file publish gating by state, tag add, invalid-JSON upload block, merge-needs-target, migration-needs-valid-mapping). These are deterministic component-unit assertions verified by vitest over src/modals/object/*.vue with mounted props/emit assertions — they have no distinct browsable page beyond the object view/edit modal, whose open/view flow is already real-UI covered under generic-object-modals::user-views-an-object and publications::open-the-publish-dialog.

## Requirements
### Requirement: Object view/edit modal (REQ-OBJM-001)
The object view/edit modal MUST present an existing object's data for inspection and editing, deriving the modal title, the resolved schema, and the editable property set from the active object and its register/schema context.

#### Scenario: Modal opens for an existing object
- **GIVEN** an active object with a register and schema
- **WHEN** the view/edit modal is opened
- **THEN** the modal title, resolved schema, and schema properties MUST be derived from that object

### Requirement: Schema-driven form editing (REQ-OBJM-002)
The modal MUST render a form whose fields are driven by the resolved schema, MUST apply schema defaults for new objects, MUST track required/missing fields, MUST report per-field validation errors, and MUST enforce constant/immutable property rules.

#### Scenario: Required fields block save
- **GIVEN** a schema with required properties
- **WHEN** one or more required values are missing
- **THEN** the save action MUST be disabled and the missing fields MUST be reported

### Requirement: Form and JSON two-way sync (REQ-OBJM-003)
The modal MUST keep the structured form and a raw JSON view in sync, validating the JSON and reflecting form edits into JSON and JSON edits into the form.

#### Scenario: Editing JSON updates the form
- **GIVEN** valid JSON entered in the JSON view
- **WHEN** the JSON is applied
- **THEN** the structured form MUST reflect the parsed values

### Requirement: Object file attachment management (REQ-OBJM-004)
The modal and the file-upload modal MUST allow attaching one or more files to an object, validating file size and rejecting oversized or duplicate files, editing file labels and tags, retrying failed uploads, and reporting upload progress.

#### Scenario: Oversized file is rejected
- **GIVEN** a selected file exceeding the allowed size
- **WHEN** the upload is prepared
- **THEN** the file MUST be flagged as too big and excluded from upload

### Requirement: Per-file publication actions (REQ-OBJM-005)
The modal MUST allow publishing, depublishing, deleting, and downloading individual attached files (and the object itself), individually and in bulk over the selected file set, and MUST gate each action by the object's publication state.

#### Scenario: Publish action gated by state
- **GIVEN** a file that is already published
- **WHEN** the file's available actions are computed
- **THEN** the publish action MUST be hidden and the depublish action MUST be offered

### Requirement: Object tag management (REQ-OBJM-006)
The modal MUST allow adding, listing, and saving tags on the active object.

#### Scenario: New tag is added
- **GIVEN** the tag editor is open
- **WHEN** a new tag is entered and confirmed
- **THEN** the tag MUST be added to the object's tag set

### Requirement: JSON object upload (REQ-OBJM-007)
The upload-object modal MUST let a user paste/prettify JSON and create an object in a chosen register and schema, validating the JSON and resolving register/schema/mapping options before save.

#### Scenario: Invalid JSON blocks upload
- **GIVEN** malformed JSON in the upload field
- **WHEN** the upload is attempted
- **THEN** validation MUST fail and the upload MUST NOT proceed

### Requirement: Object merge wizard (REQ-OBJM-008)
The merge modal MUST guide a user through selecting a target object, choosing which mergeable properties (including files and relations) to carry over, and performing the merge, only enabling the merge when a valid target and selection exist.

#### Scenario: Merge requires a target
- **GIVEN** no target object selected
- **WHEN** the merge readiness is computed
- **THEN** the merge MUST be disabled

### Requirement: Object migration wizard (REQ-OBJM-009)
The migration modal MUST let a user move an object to a different register/schema by mapping source properties to target properties, loading available registers and schema properties, and performing the migration only when a valid mapping exists.

#### Scenario: Migration requires a valid mapping
- **GIVEN** a target register and schema selected
- **WHEN** the property mapping is incomplete
- **THEN** the migration MUST be disabled until the mapping is valid

