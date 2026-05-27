---
kind: code
depends_on:
  - ConductionNL/openregister#feature/577-schema-default-auto-share
  - ConductionNL/nextcloud-vue#feature/files-tab-auto-share
---

# Proposal: Default 'Automatically publish' per schema

## Summary

Make the "Automatically publish" / "Automatisch delen" toggle in the
RegieTool attachment-upload dialog default to a value configured **per
publication schema**. Schemas opt-in via a new boolean
`configuration.defaultAutoShare`; absence is treated as `false`
(existing behaviour).

## Why

Editors uploading attachments today must flip the toggle on every
single upload when the publication type they work on is conventionally
"publish immediately". For publication types where the default is
"keep as concept" the current behaviour is correct, but there is no
way to express the preferred default per type — every upload starts
identical regardless of context.

Closing this gap removes per-upload friction (extra clicks, easy to
forget) for publication workflows where direct publication is the
norm, and keeps the conservative default for workflows where concept-
first is the standard.

Tracking issue: ConductionNL/opencatalogi#577

## What changes

- **Read path (this app):** `src/modals/generic/UploadFiles.vue` reads
  the active publication's schema configuration on dialog open and
  seeds the `share` toggle from `configuration.defaultAutoShare`.
  Schemas without the key keep the current default (off). Users can
  still override per upload.
- **Write path (sibling repo):** the active user-facing surface is
  `CnFilesTab` in `@conduction/nextcloud-vue` (see depends_on); a
  parallel PR there exposes `defaultShare` / `showShareToggle` /
  `shareLabel` props and seeds from the same schema key when
  `defaultShare` is `null`.

## Capabilities

### Modified Capabilities
- `publication-attachment-defaults` — new spec defining how schema
  configuration drives the upload-dialog default for the share/publish
  toggle.

## Out of scope

- A dedicated UI control on the schema editor's Configuration tab.
  `@conduction/nextcloud-vue`'s `CnSchemaFormDialog` already preserves
  arbitrary `configuration` keys through `{...defaults, ...item}`
  spread, so the field can be set today via the existing JSON
  pipeline or API. A follow-up issue should add an explicit checkbox.
- Renaming or migrating the existing deprecated
  `configuration.autoPublish` key. Its semantics ("share all
  attachments when the object is published", per
  `OpenRegister/lib/Service/Object/SaveObject/MetadataHydrationHandler.php`)
  differ from this proposal ("default state of the per-upload
  toggle"). The two keys coexist; only `defaultAutoShare` affects the
  upload dialog default.
