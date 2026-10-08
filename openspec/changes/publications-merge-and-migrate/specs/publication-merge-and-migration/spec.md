# publication-merge-and-migration

## ADDED Requirements

### Requirement: Two selected publications can be merged from the list (REQ-PMM-001)

The Publications page SHALL offer "Merge" in its selection bar. With exactly
two publications selected it MUST open the merge dialog at "Configure merge"
with the first selected publication as the source and the second as the
target. With any other number selected it MUST open nothing and SHALL say
"Select exactly two publications to merge". Confirming the merge MUST send
`POST /api/objects/{register}/{schema}/{sourceId}/merge` with the target, the
chosen values, the file choice and the relation choice, and SHALL show the
merge report.

@e2e tests/e2e/publications-merge-and-migrate.spec.ts

#### Scenario: Merging a draft into its final version

- **GIVEN** publications "Decision on the Woo request about the Oostpolder wind farm" and "Woo decision Oostpolder wind farm, draft" in the same register and schema
- **WHEN** an editor selects both, chooses "Merge", keeps the source title, chooses Transfer to target object for files and clicks "Merge objects"
- **THEN** one merge request is sent for the first publication with the second as target and file action transfer
- **AND** the merge report shows the properties changed and the files transferred
- **AND** the list no longer shows the source publication

#### Scenario: Merge needs exactly two

- **GIVEN** three selected publications
- **WHEN** an editor chooses "Merge"
- **THEN** no dialog opens
- **AND** the page says "Select exactly two publications to merge"

### Requirement: Selected publications can be migrated to another schema (REQ-PMM-002)

The Publications page SHALL offer "Migrate" in its selection bar for one or
more selected publications. It MUST open the migration dialog with exactly
the selected publications. The dialog MUST NOT allow "Migrate objects" until
a target register, a target schema and at least one property mapping are set,
and SHALL warn per unmapped source property that it is discarded. Confirming
MUST send `POST /api/migrate` with the selected ids, the target register and
schema, and the mapping, and SHALL show the migration report.

@e2e tests/e2e/publications-merge-and-migrate.spec.ts

#### Scenario: Migrating old imports to the current schema

- **GIVEN** three publications in schema "Publication (old Woo import)"
- **WHEN** an editor selects them, chooses "Migrate", picks schema "Publication", maps title, summary and case number and leaves Attachments unmapped
- **THEN** the dialog warns "Attachments is not mapped, so it is discarded for these 3 objects"
- **WHEN** they click "Migrate objects"
- **THEN** one migrate request is sent with the three ids and the mapping
- **AND** the report counts the objects migrated and failed

#### Scenario: No mapping, no migration

- **GIVEN** the migration dialog with a target register and schema chosen and no property mapped
- **WHEN** the editor looks at the footer
- **THEN** "Migrate objects" is disabled

### Requirement: Only editors see the merge and migrate actions (REQ-PMM-003)

The "Merge" and "Migrate" actions MUST be shown only to a user who may edit
publications, the same rule that shows the built-in delete action.

@e2e tests/e2e/publications-merge-and-migrate.spec.ts

#### Scenario: A reader has no merge action

- **GIVEN** a signed-in user who may only read publications
- **WHEN** they select two publications
- **THEN** the selection bar has neither "Merge" nor "Migrate"
