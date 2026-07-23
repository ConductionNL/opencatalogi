# Tasks: dashboard

## 1. Bug Fix — Dead Route (DSH-004 / Gap 17)

- [ ] 1.1 Open `appinfo/routes.php` and remove the entry `['name' => 'dashboard#index', 'url' => '/index', 'verb' => 'GET']`
- [ ] 1.2 Verify no other code references `dashboard#index` (grep for `dashboard#index` and `generateUrl.*dashboard.*index`)
- [ ] 1.3 Smoke test: confirm `GET /apps/opencatalogi/index` now returns 404 instead of 500

## 2. Bug Fix — Broadcast Cron Not Registered (DIR-007 / Gap 12)

- [ ] 2.1 Open `appinfo/info.xml` and add `<job>OCA\OpenCatalogi\Cron\Broadcast</job>` inside the `<background-jobs>` block alongside `DirectorySync`
- [ ] 2.2 Verify `lib/Cron/Broadcast.php` exists, extends `TimedJob`, and calls `BroadcastService::broadcast(null)` (no code change expected)
- [ ] 2.3 Smoke test: confirm Nextcloud lists `OCA\OpenCatalogi\Cron\Broadcast` in the background job table after app reload

## 3. Dashboard Bootstrap Verification (DSH-005..008)

- [ ] 3.1 Verify `Application::register()` in `lib/AppInfo/Application.php` registers `CatalogWidget`, `UnpublishedPublicationsWidget`, and `UnpublishedAttachmentsWidget` via `$context->registerDashboardWidget()`
- [ ] 3.2 Verify `ObjectCreatedEvent` → `ObjectCreatedEventListener` is registered
- [ ] 3.3 Verify `ObjectUpdatedEvent` → `ObjectUpdatedEventListener` is registered
- [ ] 3.4 Verify `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent` → `CatalogCacheEventListener` are all registered
- [ ] 3.5 Verify `ToolRegistrationEvent` → `ToolRegistrationListener` is registered
- [ ] 3.6 Verify `include_once __DIR__ . '/../../vendor/autoload.php'` is present in `Application::register()`

## 4. SPA Routing Verification (DSH-001..003)

- [ ] 4.1 Smoke test all registered deep-link routes by curling each and confirming 200 + SPA HTML is returned
- [ ] 4.2 Verify CSP header on the SPA page includes `connect-src *` (or equivalent to allow all domains)
- [ ] 4.3 Verify `appinfo/routes.php` contains entries for all SPA routes: `/`, `/catalogi`, `/publications/{catalogSlug}`, `/publications/{catalogSlug}/{id}`, `/search`, `/organizations`, `/themes`, `/glossary`, `/pages`, `/menus`, `/directory`

## 5. Listing CRUD API Verification (LST-001..006)

- [ ] 5.1 Smoke test `GET /api/listings` (authenticated) — confirm 200 with paginated list
- [ ] 5.2 Smoke test `GET /api/listings/{id}` (unauthenticated) — confirm 200 and public access works
- [ ] 5.3 Smoke test `POST /api/listings` (authenticated) — confirm listing created and 201 returned
- [ ] 5.4 Smoke test `PUT /api/listings/{id}` (authenticated) — confirm listing updated
- [ ] 5.5 Smoke test `DELETE /api/listings/{id}` (authenticated) — confirm listing deleted
- [ ] 5.6 Verify `ListingController` reads `listing_register` and `listing_schema` from `IAppConfig` and passes them to `ObjectService`
- [ ] 5.7 Verify CORS annotations (`#[CORS]`) and public-page annotations (`#[PublicPage]`, `#[NoCSRFRequired]`) are correct on `GET /api/listings/{id}` and `POST /api/listings/add`

## 6. Directory Synchronisation Verification (DIR-001..011)

- [ ] 6.1 Smoke test `GET /api/directory` (unauthenticated) — confirm 200 with combined directory data
- [ ] 6.2 Smoke test `POST /api/directory` with a directory URL — confirm `DirectoryService::syncDirectory()` is called
- [ ] 6.3 Smoke test `POST /api/listings/sync` with a listing ID — confirm listing's directory URL is fetched and sync runs
- [ ] 6.4 Verify `POST /api/listings/add` is accessible without authentication and triggers `DirectoryService::syncDirectory()`
- [ ] 6.5 Verify CORS OPTIONS endpoints exist and return correct headers for all public listing/directory endpoints
- [ ] 6.6 Verify `DirectoryService` implements anti-loop protection (5-minute TTL cache of processed directory URLs)
- [ ] 6.7 Verify `DirectoryService` checks `lastSync` timestamp to determine listing staleness before re-syncing
- [ ] 6.8 Verify `DirectoryService` converts remote catalog objects to local listing objects (title, summary, schemas mapping)
- [ ] 6.9 Verify `DirectoryService` reads the `publications` field from directory data to populate listing's publications URL

## 7. Unit Tests (ADR-008)

- [ ] 7.1 Add/verify `ListingControllerTest.php` covers: list (200), get by ID (200 + public), create (201), update (200), delete (200/204), unauthenticated write (401)
- [ ] 7.2 Add/verify `DirectoryControllerTest.php` covers: get directory (200), sync with URL (200), OPTIONS preflight (200 with CORS headers)
- [ ] 7.3 Add/verify `DirectoryServiceTest.php` covers: anti-loop protection, staleness check, catalog-to-listing conversion, publications URL detection
- [ ] 7.4 Add/verify `BroadcastTest.php` covers: job extends `TimedJob`, calls `BroadcastService::broadcast(null)`, interval is 4 hours

## 8. Documentation (ADR-009)

- [ ] 8.1 Add/update `docs/features/dashboard.md` documenting SPA routing, dashboard widgets, and deep-link URL patterns
- [ ] 8.2 Add/update `docs/features/directory.md` documenting listing CRUD, directory sync, and cron jobs
- [ ] 8.3 Note the two bug fixes (dead route removed, Broadcast cron registered) in the relevant docs or changelog

## 9. Internationalization (ADR-007)

- [ ] 9.1 Verify all user-facing strings in dashboard Vue components (`DashboardIndex.vue`, `DirectoryIndex.vue`, `EditListingModal.vue`, `AddDirectoryModal.vue`, `ViewDirectoryModal.vue`) are wrapped in `t('opencatalogi', '...')`
- [ ] 9.2 Run `npm run check:l10n` — confirm zero MISSING and zero UNWRAPPED
- [ ] 9.3 Run `npm run find:unwrapped` — confirm no unregistered prose candidates in dashboard/directory Vue files
