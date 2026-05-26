# Default 'Automatically publish' per schema

## Why

In the RegieTool's "Add attachment" dialog (`UploadFiles.vue`), the
"Automatically publish" toggle (Dutch: "Automatisch delen") always
defaults to **off**, regardless of which publication / publication
type the user is uploading to. For publication types where direct
publication is the norm, this means the user must flip the toggle on
every single upload — extra clicks and easy to forget.

The OpenRegister `Schema` entity already stores arbitrary JSON
configuration via its `configuration` column, so a per-schema default
fits naturally without a backend migration.

Tracking issue: ConductionNL/opencatalogi#577

## What Changes

- `UploadFiles.vue` reads `configuration.defaultAutoShare` (boolean)
  from the active publication's schema when the modal opens and uses
  it as the initial value of the `share` toggle.
- Schemas without `configuration.defaultAutoShare` keep the current
  behaviour (`share` defaults to `false`).
- The user can still override the toggle per upload — the schema
  value only seeds the initial state.

## Out of scope

- A dedicated UI control on the schema editor's Configuration tab.
  The schema editor lives in `@conduction/nextcloud-vue`
  (`CnSchemaConfigurationTab.vue`) and preserves arbitrary
  `configuration` keys via spread (`{...defaults, ...item}`), so the
  field can already be set via the existing JSON pipeline or API.
  A follow-up issue should add an explicit checkbox there.
- Renaming or migrating the existing deprecated `configuration.autoPublish`
  key (it has different semantics: post-hoc share on object publish,
  not "default state of the upload toggle").
