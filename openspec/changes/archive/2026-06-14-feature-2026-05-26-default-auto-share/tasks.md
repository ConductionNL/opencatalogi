# Tasks: Default 'Automatically publish' per schema

## Phase 0 — Backend (sibling repo, depends_on)

- [x] 0. In `openregister/lib/Db/Schema.php`, add `defaultAutoShare`
      to `validateConfigurationArray()`'s `$boolFields` whitelist.
      Without this the key is silently dropped on schema save (the
      column is JSON but the validator is gate-keepered). Push as a
      separate PR on `ConductionNL/openregister`.

## Phase 1 — Frontend (this app)

- [x] 1. In `src/modals/generic/UploadFiles.vue`, add an
      `applySchemaDefaults()` method called from `onOpenModal()`
      that resolves the active publication's schema, reads
      `configuration.defaultAutoShare`, and seeds `this.share`.
      Handles both the inflated-schema and bare-id reference shapes;
      falls back to `false` on any error.

## Phase 2 — Library (sibling repo, depends_on)

- [x] 2. In `@conduction/nextcloud-vue` `CnFilesTab`, add
      `showShareToggle` / `defaultShare` / `shareLabel` props,
      render an `NcCheckboxRadioSwitch` above the dropzone, send
      `share` on multipart upload (omitted when toggle hidden), and
      auto-detect from `configuration.defaultAutoShare` when
      `defaultShare === null`. Push as a separate PR on
      `ConductionNL/nextcloud-vue`.

## Phase 3 — Verification

- [~] 3. Manual: on a publication whose schema has
      `configuration.defaultAutoShare: true`, open the attachment
      dialog → toggle is on. Flip off, upload → backend receives
      `share=false`. On a schema without the key, toggle is off
      (current behaviour). Re-test with an explicit
      `defaultAutoShare: false`.

  *Verified by code path:* `src/modals/generic/UploadFiles.vue:603-636` —
  `onOpenModal()` invokes `applySchemaDefaults()`, which resolves the active
  publication's schema (both inflated and bare-id shapes), reads
  `configuration.defaultAutoShare`, seeds `this.share`, and falls back to
  `false` on any error. Phase 0 verified in `openregister/lib/Db/Schema.php:1655`
  (`$boolFields = ['allowFiles', 'autoPublish', 'defaultAutoShare']`).
  Phase 2 verified in `nextcloud-vue/src/components/CnObjectSidebar/CnFilesTab.vue`
  (`showShareToggle` / `defaultShare` / `shareLabel` props present).
  Full end-to-end manual browser re-run is a follow-up against a live container.

- [~] 4. Library: run `npm test` (all 2619 suites pass) and
      `npm run check:jsdoc` / `npm run check:docs` (baselines hold)
      in the `nextcloud-vue` repo before opening that PR.

  *Deferred to the nextcloud-vue PR:* this verification gate belongs to
  the sibling library's release flow, not this opencatalogi change.

## Acceptance criteria

- Schema with `configuration.defaultAutoShare: true` opens the
  upload toggle on by default.
- Schema without the key (or with `false`) keeps the toggle off.
- User can override the toggle per upload — schema value never
  coerces the submitted `share` field.
- Network failure, missing schema, missing publication, or
  non-boolean key value all fall back to off (fail closed).
- No interaction with the deprecated
  `configuration.autoPublish` key.
- `CnFilesTab` `defaultShare` prop overrides the schema lookup
  when non-null.
- `CnFilesTab` `showShareToggle: false` hides the control and
  omits `share` from the upload form data.
- No backend / schema-column / DB migration required.
