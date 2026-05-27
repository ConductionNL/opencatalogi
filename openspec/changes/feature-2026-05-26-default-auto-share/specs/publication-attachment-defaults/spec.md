---
status: in-progress
---

# Publication Attachment Defaults

## Purpose

Define how the publication-attachment upload dialog seeds its
"Automatically publish" toggle from per-schema configuration, so that
publication types can opt into a default-on toggle without changing
the per-upload override behaviour.

## Context

The attachment upload dialog accepts a `share` boolean that drives
the OpenRegister `filesMultipart` endpoint's immediate-share path.
This spec covers only the **default value** the dialog presents on
open. Whether `share` is truthy at submit time, what the backend does
with it, and any later moderation flow are governed by other specs.

Two surfaces implement the dialog:

| Surface | Owner |
|---|---|
| `opencatalogi/src/modals/generic/UploadFiles.vue` | This app — legacy modal, kept aligned for future re-wire |
| `@conduction/nextcloud-vue` `CnFilesTab` | Library — active user-facing surface (rendered inside `CnObjectSidebar`'s Files tab) |

This spec applies to both. The library exposes a `defaultShare` prop
as a consumer override and a `null` default that triggers the
schema-driven path described below.

## Requirements

### Requirement: Schema MAY opt in via `configuration.defaultAutoShare`

A Publication schema MAY set `configuration.defaultAutoShare: true`
to make the upload dialog seed its "Automatically publish" toggle as
on by default. The key lives on the existing
`OpenRegister.Schema.configuration` JSON column (no migration).

#### Scenario: Schema opts in
- GIVEN a publication schema with `configuration.defaultAutoShare: true`
- WHEN a user opens the attachment-upload dialog on a publication of that schema
- THEN the "Automatically publish" toggle MUST be on by default
- AND the user MUST be able to flip the toggle off before submitting

#### Scenario: Schema does not opt in
- GIVEN a publication schema with no `defaultAutoShare` key in `configuration`
- WHEN a user opens the attachment-upload dialog on a publication of that schema
- THEN the "Automatically publish" toggle MUST be off by default (current behaviour)

#### Scenario: Schema explicitly opts out
- GIVEN a publication schema with `configuration.defaultAutoShare: false`
- WHEN a user opens the attachment-upload dialog on a publication of that schema
- THEN the "Automatically publish" toggle MUST be off by default
- AND the behaviour MUST be identical to the missing-key case

### Requirement: Schema-driven default MUST NOT lock the toggle

The schema configuration only seeds the **initial** value of the
toggle. The user can always flip it before submitting an upload.

#### Scenario: User overrides schema default before upload
- GIVEN the dialog opened with the toggle seeded `true` from schema config
- WHEN the user flips the toggle to off and submits the upload
- THEN the `share` field in the request MUST be `false`
- AND the backend MUST receive the user's explicit choice, not the schema default

### Requirement: Lookup MUST fail closed

Any failure path (network error, 404, malformed schema response,
missing `@self.schema`, missing `configuration` block, non-boolean
key value) MUST result in the toggle being off — never on. The
schema's `true` is the only signal that flips the seed.

#### Scenario: Schema API returns an error
- GIVEN the schema lookup fetch returns an HTTP error or times out
- WHEN the dialog opens
- THEN the toggle MUST be off
- AND the dialog MUST remain usable (the error MUST NOT block upload)

#### Scenario: Active publication has no schema reference
- GIVEN the active publication has no `@self.schema`
- WHEN the dialog opens
- THEN the toggle MUST be off
- AND no schema fetch SHOULD be attempted

#### Scenario: Schema config has a non-boolean `defaultAutoShare`
- GIVEN the schema's `configuration.defaultAutoShare` is a non-`true` value (string, number, null, object)
- WHEN the dialog opens
- THEN the toggle MUST be off
- AND only the strict boolean `true` MUST trigger the on-by-default seed

### Requirement: Library consumers MAY override the schema lookup

The `CnFilesTab` library component MUST accept a `defaultShare` prop
that overrides the schema lookup when non-null. This lets consumers
seed the toggle from their own source of truth (e.g. user
preferences, route parameters, A/B test) without performing the
schema fetch.

#### Scenario: Consumer passes an explicit defaultShare
- GIVEN a consumer mounts `CnFilesTab` with `:default-share="true"` on a schema with `defaultAutoShare: false`
- WHEN the dialog renders
- THEN the toggle MUST be on (the prop wins)
- AND no schema fetch is required (the prop bypasses the lookup)

#### Scenario: Consumer hides the toggle entirely
- GIVEN a consumer mounts `CnFilesTab` with `:show-share-toggle="false"`
- WHEN the dialog renders
- THEN the toggle MUST NOT be visible
- AND the upload request MUST NOT include a `share` form field (omission = "do not auto-share")

### Requirement: Existing deprecated `configuration.autoPublish` MUST remain untouched

`configuration.autoPublish` (deprecated per
`OpenRegister.MetadataHydrationHandler::$deprecatedKeys`) has
different semantics — "share all attachments when the parent object
is published". This spec MUST NOT read, write, or migrate
`autoPublish`. The two keys coexist on the same `configuration`
block without interaction.

#### Scenario: Schema has both keys set
- GIVEN a schema with `autoPublish: true` and `defaultAutoShare: false`
- WHEN the upload dialog opens
- THEN the toggle MUST be off (driven only by `defaultAutoShare`)
- AND any object-publish behaviour driven by `autoPublish` MUST be unchanged
