# Tasks: harden-listings-admin-write-surface

## Implementation Tasks

### Task 1: Admin-gate and allow-list ListingsController::create()
- **spec_ref**: `openspec/changes/harden-listings-admin-write-surface/specs/dashboard/spec.md#requirement-create-a-new-listing-admin-only-allow-listed-lst-003`
- **files**: `lib/Controller/ListingsController.php`, `tests/Unit/Controller/ListingsControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a non-admin authenticated user WHEN POST `/api/listings` THEN the request is rejected by the admin guard and no listing is created
  - GIVEN an admin WHEN POST `/api/listings` with off-list fields (e.g. `statusCode`, `lastSync`, `available`) THEN those fields are silently dropped and only `CREATABLE_LISTING_FIELDS` persist
  - GIVEN an admin WHEN POST `/api/listings` with a `directory` URL that fails `assertSafeOutboundUrl()` THEN the response is `400` and no listing is created
- [ ] Replace `@NoAdminRequired` on `create()` with `#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]`
- [ ] Add `CREATABLE_LISTING_FIELDS` const (UPDATABLE set + `directory`, `catalog`, `slug`, `status`) and filter the payload before `saveObject()`
- [ ] Validate the `directory` field via `FILTER_VALIDATE_URL` + `DirectoryService::assertSafeOutboundUrl()` (expose a public wrapper if needed)
- [ ] Unit tests for all three criteria

### Task 2: Enforce the DIR-005 admin gate on add()
- **spec_ref**: `openspec/changes/harden-listings-admin-write-surface/specs/dashboard/spec.md#requirement-add-a-new-listing-from-a-url-admin-only-dir-005`
- **files**: `lib/Controller/ListingsController.php`, `tests/Unit/Controller/ListingsControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a non-admin authenticated user WHEN POST `/api/listings/add` THEN the request is rejected by the admin guard
  - GIVEN an anonymous caller WHEN POST `/api/listings/add` THEN the response remains `403` and no listing is created
  - GIVEN an admin WHEN POST `/api/listings/add` with a valid directory URL THEN a listing is created (behaviour unchanged)
- [ ] Drop `@NoAdminRequired` from `add()` and add `#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]`
- [ ] Keep the explicit session guard as defence-in-depth
- [ ] Unit tests for admin / non-admin / anonymous

### Task 3: Admin-gate synchronise()
- **spec_ref**: `openspec/changes/harden-listings-admin-write-surface/specs/dashboard/spec.md#requirement-synchronize-a-specific-listings-directory-admin-only-dir-003`
- **files**: `lib/Controller/ListingsController.php`, `tests/Unit/Controller/ListingsControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a non-admin authenticated user WHEN POST `/api/listings/sync` THEN the request is rejected by the admin guard
  - GIVEN an admin WHEN POST `/api/listings/sync` (with or without `id`) THEN sync behaviour is unchanged
  - GIVEN the hourly cron WHEN it runs THEN `doCronSync()` is unaffected (it does not pass through the controller)
- [ ] Drop `@NoAdminRequired` from `synchronise()` and add `#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]`
- [ ] Unit tests for admin / non-admin

### Task 4: Frontend — surface admin-only affordances correctly
- **spec_ref**: `openspec/changes/harden-listings-admin-write-surface/specs/dashboard/spec.md#requirement-add-a-new-listing-from-a-url-admin-only-dir-005`
- **files**: `src/views/directory/DirectoryIndex.vue`, `src/modals/directory/AddDirectoryModal.vue`
- **acceptance_criteria**:
  - GIVEN a non-admin user on the Directory page THEN the "Add directory" and "Sync" actions are hidden or disabled with an explanatory tooltip (NC CSS variables only, English i18n keys via the l10n scripts)
- [ ] Read admin state from initial state / capabilities and gate the buttons
- [ ] Register any new i18n keys via `scripts/l10n-ai.js add` (all locales)

### Task 5: Spec sync
- **spec_ref**: `openspec/changes/harden-listings-admin-write-surface/specs/dashboard/spec.md`
- **files**: `openspec/specs/dashboard/spec.md`
- **acceptance_criteria**:
  - GIVEN the delta is applied THEN LST-003 reads admin-only + allow-listed and DIR-005 carries the non-admin-rejection scenario
- [ ] Run `/opsx-sync` (or manual sync) after implementation is verified
