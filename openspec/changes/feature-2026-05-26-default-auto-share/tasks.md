# Tasks: Default 'Automatically publish' per schema

## Phase 1 — Frontend

- [ ] 1. In `src/modals/generic/UploadFiles.vue`, when the modal
      opens (`onOpenModal`), resolve the active publication's schema
      and read `configuration.defaultAutoShare`. Seed the `share`
      data property with that value (defaulting to `false` if the
      schema reference, the schema, or the key is missing). The
      lookup must succeed both when the publication carries an
      inflated schema object on `@self.schema` and when it only
      carries the schema id.

## Phase 2 — Verification

- [ ] 2. Manual: open the "Add attachment" dialog on a publication
      whose schema has `configuration.defaultAutoShare: true` —
      verify the toggle is on by default and can still be flipped
      off per upload. Then verify a schema without the key keeps
      the toggle off (current behaviour).
