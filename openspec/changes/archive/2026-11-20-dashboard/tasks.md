# Tasks: dashboard

## 1. Bug Fix — Dead Route (DSH-004 / Gap 17)

- [x] 1.1 Open `appinfo/routes.php` and remove the entry `['name' => 'dashboard#index', 'url' => '/index', 'verb' => 'GET']`
- [x] 1.2 Verify no other code references `dashboard#index` (grep for `dashboard#index` and `generateUrl.*dashboard.*index`)
- [x] 1.3 Smoke test: confirm `GET /apps/opencatalogi/index` now returns 404 instead of 500 Live pass 17 Nov (livepass-opencatalogi): the route is gone and the path no longer 500s; it now answers 200 with the SPA through the `/{path}` catch-all added since, not 404.

## 2. Bug Fix — Broadcast Cron Not Registered (DIR-007 / Gap 12)

- [x] 2.1 Open `appinfo/info.xml` and add `<job>OCA\OpenCatalogi\BackgroundJob\Broadcast</job>` inside the `<background-jobs>` block alongside `DirectorySync`
- [x] 2.2 Verify `lib/BackgroundJob/Broadcast.php` exists, extends `TimedJob`, and calls `BroadcastService::broadcast(null)` (no code change expected)
- [x] 2.3 Smoke test: confirm Nextcloud lists `OCA\OpenCatalogi\BackgroundJob\Broadcast` in the background job table after app reload Live pass 17 Nov (livepass-opencatalogi): `occ background-job:list` lists `OCA\OpenCatalogi\BackgroundJob\Broadcast` after `occ app:enable`.

## 3. Dashboard Bootstrap Verification (DSH-005..008)

- [x] 3.1 Verify `Application::register()` in `lib/AppInfo/Application.php` registers `CatalogWidget`, `UnpublishedPublicationsWidget`, and `UnpublishedAttachmentsWidget` via `$context->registerDashboardWidget()`
- [x] 3.2 Verify `ObjectCreatedEvent` → `ObjectCreatedEventListener` is registered
- [x] 3.3 Verify `ObjectUpdatedEvent` → `ObjectUpdatedEventListener` is registered
- [x] 3.4 Verify `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent` → `CatalogCacheEventListener` are all registered
- [x] 3.5 Verify `ToolRegistrationEvent` → `ToolRegistrationListener` is registered
- [x] 3.6 Verify `include_once __DIR__ . '/../../vendor/autoload.php'` is present in `Application::register()`

## 4. SPA Routing Verification (DSH-001..003)

- [x] 4.1 Smoke test all registered deep-link routes by curling each and confirming 200 + SPA HTML is returned Live pass 17 Nov (livepass-opencatalogi): `/`, `/catalogi`, `/publications/publications`, `/publications/publications/{id}`, `/search`, `/organizations`, `/themes`, `/glossary`, `/pages`, `/menus`, `/directory` all 200 with the SPA bundle.
- [x] 4.2 Verify CSP header on the SPA page includes `connect-src *` (or equivalent to allow all domains)
- [x] 4.3 Verify `appinfo/routes.php` contains entries for all SPA routes: `/`, `/catalogi`, `/publications/{catalogSlug}`, `/publications/{catalogSlug}/{id}`, `/search`, `/organizations`, `/themes`, `/glossary`, `/pages`, `/menus`, `/directory`

## 5. Listing CRUD API Verification (LST-001..006)

- [x] 5.1 Smoke test `GET /api/listings` (authenticated) — confirm 200 with paginated list Live pass 17 Nov (livepass-opencatalogi): 200 with `results`, `total`, `page`, `pages`.
- [x] 5.2 Smoke test `GET /api/listings/{id}` (unauthenticated) — confirm 200 and public access works Live pass 17 Nov (livepass-opencatalogi): 200 anonymously for a listing with `published` in the past; an unpublished listing is 404 to anonymous readers by the listing schema's read rule.
- [x] 5.3 Smoke test `POST /api/listings` (authenticated) — confirm listing created and 201 returned Live pass 17 Nov (livepass-opencatalogi): was 500 for every create (the allow-list dropped the schema's required `catalogusId`); fixed in this pass, now 200 with the listing (the controller answers 200, not 201), and a refused payload is 400 with the reason.
- [x] 5.4 Smoke test `PUT /api/listings/{id}` (authenticated) — confirm listing updated Live pass 17 Nov (livepass-opencatalogi): 200, title changed and read back anonymously; an unknown id is now 404 (was 500).
- [x] 5.5 Smoke test `DELETE /api/listings/{id}` (authenticated) — confirm listing deleted Live pass 17 Nov (livepass-opencatalogi): 200 `Listing removed`, then 404 on read.
- [x] 5.6 Verify `ListingController` reads `listing_register` and `listing_schema` from `IAppConfig` and passes them to `ObjectService`
- [x] 5.7 Verify CORS annotations (`#[CORS]`) and public-page annotations (`#[PublicPage]`, `#[NoCSRFRequired]`) are correct on `GET /api/listings/{id}` and `POST /api/listings/add`
  - Live pass 17 Nov: left open. `OPTIONS /api/listings/{id}` answers the CORS headers, but the GET answer itself carries no `Access-Control-Allow-Origin`, and `POST /api/listings/add` is admin-only since harden-listings-admin-write-surface (403 anonymously), so this item's expectation no longer matches the spec.
  - Built 17 Nov (round 2): `ListingsController::show()` now adds the CORS headers (origin from the `cors_allowed_origins` allowlist, never a reflected arbitrary origin) to every answer, the 404 and 503 included (DIR-008). `show` keeps `@PublicPage` / `@NoCSRFRequired`; `add` is correctly admin-only (`#[AuthorizedAdminSetting]`) per LST-003. Tests: `ListingsControllerTest::testShowAnswersWithTheCorsHeaders`, `testShowFollowsTheAllowlistOnA404`.

## 6. Directory Synchronisation Verification (DIR-001..011)

- [x] 6.1 Smoke test `GET /api/directory` (unauthenticated) — confirm 200 with combined directory data (live pass, decision 139) Live pass 17 Nov (livepass-opencatalogi): 200 anonymously with the combined directory.
- [x] 6.2 Smoke test `POST /api/directory` with a directory URL — confirm `DirectoryService::syncDirectory()` is called (live pass, decision 139) Live pass 17 Nov (livepass-opencatalogi): as admin: an unresolvable host answers 400 `Directory URL host could not be resolved` and a loopback host 400 `Directory URL host is not allowed`, both from DirectoryService's outbound guard. A successful sync was not run: it announces this instance to the remote directory.
- [x] 6.3 Smoke test `POST /api/listings/sync` with a listing ID — confirm listing's directory URL is fetched and sync runs (live pass, decision 139) Live pass 17 Nov (livepass-opencatalogi): as admin the listing's directory was fetched (example.com, 404) and the failure recorded on the listing (`lastSync`, `statusCode` 404, `lastError` without the query string) after this pass's fix; as editor1 403.
- [x] 6.4 Verify `POST /api/listings/add` is admin-only (LST-003, harden-listings-admin-write-surface) and triggers `DirectoryService::syncDirectory()` (reworded 20 Nov: the main spec made `add` admin-only, so "accessible without authentication" no longer holds). Verify: `ListingsControllerTest::testAddHasAuthorizedAdminSettingGate`, `::testAddReturns403WhenAnonymous`, `::testAddSyncsTheGivenDirectoryOnceAndReturnsItsResult`.
  - Live pass 17 Nov: left open. The main spec (LST-003, harden-listings-admin-write-surface) makes `add` admin-only; anonymous access is refused by design, so this item needs rewording rather than a fix.
- [x] 6.5 Verify CORS OPTIONS endpoints exist and return correct headers for all public listing/directory endpoints
- [x] 6.6 Verify `DirectoryService` implements anti-loop protection (5-minute TTL cache of processed directory URLs)
- [x] 6.7 Verify `DirectoryService` checks `lastSync` timestamp to determine listing staleness before re-syncing
- [x] 6.8 Verify `DirectoryService` converts remote catalog objects to local listing objects (title, summary, schemas mapping)
- [x] 6.9 Verify `DirectoryService` reads the `publications` field from directory data to populate listing's publications URL

## 7. Unit Tests (ADR-008)

- [x] 7.1 Add/verify `ListingControllerTest.php` covers: list (200), get by ID (200 + public), create (201), update (200), delete (200/204), unauthenticated write (401)
- [x] 7.2 Add/verify `DirectoryControllerTest.php` covers: get directory (200), sync with URL (200), OPTIONS preflight (200 with CORS headers)
- [x] 7.3 Add/verify `DirectoryServiceTest.php` covers: anti-loop protection, staleness check, catalog-to-listing conversion, publications URL detection
- [x] 7.4 Add/verify `BroadcastTest.php` covers: job extends `TimedJob`, calls `BroadcastService::broadcast(null)`, interval is 4 hours

## 8. Documentation (ADR-009)

- [x] 8.1 Add/update `docs/features/dashboard.md` documenting SPA routing, dashboard widgets, and deep-link URL patterns
- [x] 8.2 Add/update `docs/features/directory.md` documenting listing CRUD, directory sync, and cron jobs
- [x] 8.3 Note the two bug fixes (dead route removed, Broadcast cron registered) in the relevant docs or changelog

## 9. Internationalization (ADR-007)

- [x] 9.1 Verify all user-facing strings in dashboard Vue components (`DashboardIndex.vue`, `DirectoryIndex.vue`, `EditListingModal.vue`, `AddDirectoryModal.vue`, `ViewDirectoryModal.vue`) are wrapped in `t('opencatalogi', '...')`
- [x] 9.2 Run `npm run check:l10n` — confirm zero MISSING and zero UNWRAPPED
  - 17 Nov (round 2): zero MISSING, zero UNWRAPPED. The three former UNWRAPPED hits were `actionIconName="Delete"`, an icon component name; `scripts/check-l10n.js` now lists `actionIconName` as a non-display attribute. One UNUSED key remains, the OpenRegister-version notice that `lib/AppInfo/OpenRegisterVersionCheck.php` translates server-side.
- [x] 9.3 Run `npm run find:unwrapped` — confirm no unregistered prose candidates in dashboard/directory Vue files
