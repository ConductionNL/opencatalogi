---
app: opencatalogi
generated: 2026-04-17T18:30:00Z
scanner: opsx-coverage-scan
---

# Coverage Report — opencatalogi

## Summary
| Bucket | Count |
|---|---|
| 1. Ready to annotate | 34 |
| 2a. Extend existing capability | 2 capabilities / 3 proposed REQs |
| 2b. New capability | 1 cluster / 6 files |
| 3a. Broken code (dead route/handler) | 2 |
| 3b. Unfulfilled spec (no code ever) | 2 |
| 4. ADR conformance issues | 8 |
| Already annotated | 0 methods |
| Plumbing (skipped) | ~50 methods |

## Bucket 1 — Ready to annotate

### Controller: SettingsController.php
- `index()` → `admin-settings#SET-001` (high confidence, REST GET pattern + method name)
- `create()` → `admin-settings#SET-002` (high confidence, POST update)
- `load()` → `admin-settings#SET-003` (high confidence, "load/import" keyword)
- `manualImport()` → `admin-settings#SET-007` (high confidence, explicit "import" method)
- `getVersionInfo()` → `admin-settings#SET-010` (high confidence, version info endpoint)

### Service: SettingsService.php
- `getSettings()` → `admin-settings#SET-001` (high confidence, retrieve settings)
- `updateSettings()` → `admin-settings#SET-002` (high confidence, update settings)
- `autoConfigure()` → `admin-settings#SET-004` (high confidence, auto-config keyword)
- `getPublishingOptions()` → `admin-settings#SET-009` (high confidence, publishing options)

### Listeners & Events: Auto-Publishing
- `ObjectCreatedEventListener::handle()` → `auto-publishing#APB-001` (high, file path + event listener pattern)
- `ObjectUpdatedEventListener::handle()` → `auto-publishing#APB-002` (high, event pattern)
- `EventService::handleObjectCreateEvents()` → `auto-publishing#APB-003` (high, auto-publish keyword)
- `EventService::handleObjectUpdateEvents()` → `auto-publishing#APB-002` (high, update event)
- `EventService::publishObject()` → `auto-publishing#APB-006` (high, publication status check)
- `EventService::publishObjectAttachments()` → `auto-publishing#APB-004` (high, attachment publishing)

### Controller: CatalogiController.php
- `index()` → `catalogs#CAT-001` (high, list catalogs endpoint)
- `show()` → `catalogs#CAT-002` (high, single catalog detail)

### Service: CatalogiService.php
- `getCatalogBySlug()` → `catalogs#CAT-005` (high, caching + slug lookup)
- `invalidateCatalogCache()` → `catalogs#CAT-006` (high, cache invalidation)

### Listener: CatalogCacheEventListener.php
- `handle()` → `catalogs#CAT-011` (high, automatic cache invalidation via events)

### Controller: MenusController.php
- `index()` → `content-management#CMS-010` (high, list menus)
- `show()` → `content-management#CMS-011` (high, menu detail)

### Controller: PagesController.php
- `index()` → `content-management#CMS-001` (high, list pages)
- `show()` → `content-management#CMS-002` (high, page by slug)

### Controller: ThemesController.php
- `index()` → `content-management#CMS-020` (high, list themes)
- `show()` → `content-management#CMS-021` (high, theme detail)

### Controller: GlossaryController.php
- `index()` → `content-management#CMS-030` (high, list glossary)
- `show()` → `content-management#CMS-031` (high, glossary term detail)

### Tool: CMSTool.php
- `createPage()` → `cms-tool#CMS-T-002` (high, cms_create_page function)
- `listPages()` → `cms-tool#CMS-T-003` (high, cms_list_pages function)
- `createMenu()` → `cms-tool#CMS-T-004` (high, cms_create_menu function)
- `listMenus()` → `cms-tool#CMS-T-005` (high, cms_list_menus function)
- `addMenuItem()` → `cms-tool#CMS-T-006` (high, cms_add_menu_item function)

### Controller: PublicationsController.php
- `index()` → `publications#PUB-001` (high, list publications by catalog)
- `show()` → `publications#PUB-002` (high, single publication detail)
- `attachments()` → `publications#PUB-006` (high, publication attachments)
- `uses()` → `publications#PUB-008` (high, outgoing relations)
- `used()` → `publications#PUB-009` (high, incoming relations)

### Controller: FederationController.php
- `publications()` → `federation#FED-001` (high, federated publication list)
- `publication()` → `federation#FED-002` (high, federated single publication)
- `publicationUses()` → `federation#FED-003` (high, federated outgoing relations)
- `publicationUsed()` → `federation#FED-004` (high, federated incoming relations)

### Controller: SearchController.php
- `index()` → `search#SCH-001` (high, internal search endpoint)

### Controller: SitemapController.php
- `index()` → `woo-compliance#WOO-001` (high, sitemap index)
- `sitemap()` → `woo-compliance#WOO-002` (high, DIWOO sitemap generation)

### Controller: RobotsController.php
- `index()` → `woo-compliance#WOO-004` (high, robots.txt generation)

## Bucket 2a — Extend existing capability

### Target: download-service
- **Proposed REQ-NEXT (DWN-011)**: File attachment bulk download as ZIP archive — covers `DownloadService::createPublicationZip()` (lib/Service/DownloadService.php:57). Behavior: Packages metadata PDF and all attachments into ZIP archive with folder structure.

### Target: prometheus-metrics
- **Proposed REQ-NEXT (PROM-011)**: Federation health metrics endpoint — covers `MetricsController::countDirectoryEntries()` (lib/Controller/MetricsController.php:line 165). Behavior: Exposes directory entry counts and reachability status via metrics.

- **Proposed REQ-NEXT (PROM-012)**: Application bootstrap health tracking — covers `Application::register()` (lib/AppInfo/Application.php). Behavior: Registers widget and listener health checks during bootstrap initialization.

## Bucket 2b — New capability

### Cluster: Application Bootstrapping & Repairs
- **Files** (6): `lib/AppInfo/Application.php`, `lib/Repair/InitializeSettings.php`, `lib/Migration/Version6Date20241011085015.php`, `lib/Migration/Version6Date20241129151236.php`, `lib/Migration/Version6Date20241208222530.php`, `lib/Migration/Version6Date20250419123213.php`
- **Behavior**: System initialization, database schema migration, and repair-step management for app install/upgrade lifecycle. The Application class bootstraps vendor autoload, dashboard widgets, event listeners, and tool registration during the `register()` phase. InitializeSettings repair step runs post-install to load initial configuration. Four database migrations track incremental schema evolution.
- **Suggested REQ outline**:
  - Bootstrap environment with vendor autoload and dependency injection
  - Register dashboard widgets (CatalogWidget, UnpublishedPublicationsWidget, UnpublishedAttachmentsWidget) via IRegistrationContext
  - Register event listeners for OpenRegister object lifecycle events (ObjectCreatedEvent, ObjectUpdatedEvent, ObjectDeletedEvent)
  - Register AI tool integration via ToolRegistrationEvent listener
  - Execute database migrations on app version upgrades with proper rollback support
  - Run repair steps to initialize configuration and detect dependency versions

## Bucket 3a — Broken code (dead route/handler)

- `dashboard#DSH-004` — Dashboard index endpoint method removed from DashboardController; route still registered in routes.php but will 404 at runtime. Remediation: restore handler or remove route.
- `woo-compliance#WOO-008` — RobotsController does NOT check `hasWooSitemap` flag; all catalogs with slugs get sitemap entries regardless. Remediation: add the `hasWooSitemap` predicate to the controller.

## Bucket 3b — Unfulfilled spec (no code ever existed)

- `search#SCH-006` — ElasticSearch integration requirement; OpenCatalogi uses OpenRegister's ObjectService directly (no separate SearchService exists). Remediation: mark `status: deferred` or delete REQ.
- `search#SCH-010` — SearchService requirement; not applicable (no SearchService class in OpenCatalogi). Remediation: same as SCH-006.

## Bucket 4 — ADR conformance issues

- **lib/AppInfo/Application.php**: ADR-014 compliance gap — file missing EUPL-1.2 header comment
- **lib/Service/FileService.php**: Memory limit hardcoded at 2048M via `ini_set()` — ideally should use Nextcloud config
- **lib/Service/FileService.php**: Direct `$_SERVER` access for domain detection (HTTPS, HTTP_HOST) — should use Nextcloud IURLGenerator for reliability
- **lib/Service/FileService.php**: Typo in use statement `use Mpdf\MpMpdfdf;` — should be `use Mpdf\Mpdf;` (code works despite typo)
- **lib/Service/DownloadService.php**: Temporary file cleanup may fail if `/tmp/mpdf/` is not empty (rmdir expects empty dir)
- **lib/Listener/ObjectUpdatedEventListener.php**: Debug logging with OPENCATALOGI_EVENT_LISTENER_CALLED_AT_* constants should be removed before release
- **lib/Service/PublicationService.php**: Direct blob to array conversion via jsonSerialize() may not preserve all metadata fields
- **lib/Controller/PublicationsController.php**: UNION ALL magic table scanning in findObjectLocation() generates unbounded SQL if many tables exist

## Notes

- No `@spec` or `@implements` annotations found in any code file (all entries above are for NEW annotation placement)
- 0 methods already annotated
- ~50 framework methods skipped as plumbing (getters, setters, __construct with DI only, register() hooks with no logic)
- Test coverage: 0 existing PHPUnit test files found in specified scope
