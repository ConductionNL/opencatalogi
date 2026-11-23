# Tasks: harden-listings-admin-write-surface

## Implementation Tasks

### Task 1: Admin-gate and allow-list ListingsController::create()
- **spec_ref**: `openspec/changes/harden-listings-admin-write-surface/specs/dashboard/spec.md#requirement-create-a-new-listing-admin-only-allow-listed-lst-003`
- **files**: `lib/Controller/ListingsController.php`, `tests/Unit/Controller/ListingsControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a non-admin authenticated user WHEN POST `/api/listings` THEN the request is rejected by the admin guard and no listing is created
  - GIVEN an admin WHEN POST `/api/listings` with off-list fields (e.g. `statusCode`, `lastSync`, `available`) THEN those fields are silently dropped and only `CREATABLE_LISTING_FIELDS` persist
  - GIVEN an admin WHEN POST `/api/listings` with a `directory` URL that fails `assertSafeOutboundUrl()` THEN the response is `400` and no listing is created
- [x] Replace `@NoAdminRequired` on `create()` with `#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]`
- [x] Add `CREATABLE_LISTING_FIELDS` const (UPDATABLE set + `directory`, `catalog`, `slug`, `status`) and filter the payload before `saveObject()`
- [x] Validate the `directory` field via `FILTER_VALIDATE_URL` + `DirectoryService::assertSafeOutboundUrl()` (expose a public wrapper if needed)
- [x] Unit tests for all three criteria

### Task 2: Enforce the DIR-005 admin gate on add()
- **spec_ref**: `openspec/changes/harden-listings-admin-write-surface/specs/dashboard/spec.md#requirement-add-a-new-listing-from-a-url-admin-only-dir-005`
- **files**: `lib/Controller/ListingsController.php`, `tests/Unit/Controller/ListingsControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a non-admin authenticated user WHEN POST `/api/listings/add` THEN the request is rejected by the admin guard
  - GIVEN an anonymous caller WHEN POST `/api/listings/add` THEN the response remains `403` and no listing is created
  - GIVEN an admin WHEN POST `/api/listings/add` with a valid directory URL THEN a listing is created (behaviour unchanged)
- [x] Drop `@NoAdminRequired` from `add()` and add `#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]`
- [x] Keep the explicit session guard as defence-in-depth
- [x] Unit tests for admin / non-admin / anonymous

### Task 3: Admin-gate synchronise()
- **spec_ref**: `openspec/changes/harden-listings-admin-write-surface/specs/dashboard/spec.md#requirement-synchronize-a-specific-listings-directory-admin-only-dir-003`
- **files**: `lib/Controller/ListingsController.php`, `tests/Unit/Controller/ListingsControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a non-admin authenticated user WHEN POST `/api/listings/sync` THEN the request is rejected by the admin guard
  - GIVEN an admin WHEN POST `/api/listings/sync` (with or without `id`) THEN sync behaviour is unchanged
  - GIVEN the hourly cron WHEN it runs THEN `doCronSync()` is unaffected (it does not pass through the controller)
- [x] Drop `@NoAdminRequired` from `synchronise()` and add `#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]`
- [x] Unit tests for admin / non-admin

### Task 4: Frontend — surface admin-only affordances correctly
- **spec_ref**: `openspec/changes/harden-listings-admin-write-surface/specs/dashboard/spec.md#requirement-add-a-new-listing-from-a-url-admin-only-dir-005`
- **files**: `src/views/directory/DirectoryIndex.vue`, `src/modals/directory/AddDirectoryModal.vue`
- **acceptance_criteria**:
  - GIVEN a non-admin user on the Directory page THEN the "Add directory" and "Sync" actions are hidden or disabled with an explanatory tooltip (NC CSS variables only, English i18n keys via the l10n scripts)
- [x] Read admin state from initial state / capabilities and gate the buttons — already
      implemented pre-existing via `useIsAdmin()` composable (real NC group membership
      check, not a DOM-attribute read); `DirectoryIndex.vue` already gates `show-add`
      and the row-level "Sync Directory" action behind `isAdmin`, and shows a
      `NcNoteCard` explaining the read-only state to non-admins. No code change needed.
- [x] Register any new i18n keys via `scripts/l10n-ai.js add` (all locales) — no new
      user-facing strings were introduced by this change; nothing to register.

### Task 5: Spec sync
- **spec_ref**: `openspec/changes/harden-listings-admin-write-surface/specs/dashboard/spec.md`
- **files**: `openspec/specs/dashboard/spec.md`
- **acceptance_criteria**:
  - GIVEN the delta is applied THEN LST-003 reads admin-only + allow-listed and DIR-005 carries the non-admin-rejection scenario
- [x] Run `/opsx-sync` (or manual sync) after implementation is verified — manually
      applied the delta to `openspec/specs/dashboard/spec.md` (LST-003, DIR-003,
      DIR-005 updated with admin-only wording and non-admin-rejection scenarios).
