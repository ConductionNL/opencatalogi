# Coverage Report — opencatalogi

Generated: 2026-05-24 00:00 UTC
Branch: `feature/declarative-annotation-pilot`
Scanner: opsx-coverage-scan v1

## Summary

| Bucket | Count | Next action |
|---|---|---|
| annotated | 0 | — (already tagged) |
| plumbing | 135 | — (never tagged) |
| 1 — REQ matched | 195 | `/opsx-annotate opencatalogi` |
| 2a — existing capability, no REQ | 67 (7 clusters) | `/opsx-reverse-spec opencatalogi --extend <cap>` |
| 2b — no capability owner | 50 (3 clusters) | `/opsx-reverse-spec opencatalogi --cluster <name>` |
| 3a — REQ broken (code removed) | 1 | Separate fix PR |
| 3b — REQ never implemented | 2 | Mark deferred / relocate spec |
| 4 — ADR conformance | 8 findings across 8 rules | Follow-up issue |

**REQ inventory:** 195 stable REQs across 14 specs (12 with table-style IDs + `prometheus-metrics`/`org-archimate-export` with the newer `### Requirement:` heading style). Additional 48 in-flight REQ deltas in `openspec/changes/` (5 changes — all softwarecatalog-shaped, see Notes).

**Code inventory:** 358 PHP methods across 44 in-scope files in `lib/` (plus 4 Migration files skipped) + ~150 Vue/JS/TS files in `src/` bucketed at file level.

**No `@spec` annotations exist anywhere in the repo** — opencatalogi is fully pre-retrofit.

## Bucket 1 — Ready to annotate (via ghost change `retrofit-2026-05-24-annotate-opencatalogi`)

Grouped by capability, then file. Confidence ≥ 0.85 unless flagged `NEEDS-REVIEW`.

### capability: admin-settings → SET-NNN tasks (24 methods)

| File | Method | REQ | Conf | Signal |
|---|---|---|---|---|
| lib/Controller/SettingsController.php | index | SET-001 | 0.95 | GET /api/settings → settingsService.getSettings |
| lib/Controller/SettingsController.php | create | SET-002 | 0.95 | POST /api/settings → updateSettings |
| lib/Controller/SettingsController.php | load | SET-003 | 0.95 | GET /api/settings/load → loadSettings |
| lib/Controller/SettingsController.php | getPublishingOptions | SET-008/009 | 0.95 | GET /api/settings/publishing |
| lib/Controller/SettingsController.php | updatePublishingOptions | SET-008/009 | 0.95 | POST /api/settings/publishing |
| lib/Controller/SettingsController.php | getVersionInfo | SET-010 | 0.95 | GET /api/settings/version |
| lib/Controller/SettingsController.php | manualImport | SET-007 | 0.95 | POST /api/settings/import + force |
| lib/Repair/InitializeSettings.php | run | SET-011/005/003 | 0.95 | Repair step matches spec scenario exactly |
| lib/Service/SettingsService.php | isOpenRegisterInstalled | SET-005 | 0.95 | MIN_OPENREGISTER_VERSION constant |
| lib/Service/SettingsService.php | installOrUpdateOpenRegister | SET-005 | 0.95 | Install/update OR dependency |
| lib/Service/SettingsService.php | autoConfigure | SET-004 | 0.97 | Spec scenario names this method |
| lib/Service/SettingsService.php | initialize | SET-005/003 | 0.85 | Combined install+load entry |
| lib/Service/SettingsService.php | getSettings | SET-001/013 | 0.97 | Return structure matches spec scenario exactly |
| lib/Service/SettingsService.php | enrichRegistersWithSchemas | SET-013 | 0.95 | Spec scenario explicit |
| lib/Service/SettingsService.php | updateSettings | SET-002 | 0.95 | Settings update entry |
| lib/Service/SettingsService.php | getPublishingOptions | SET-008/009 | 0.97 | Spec names this method |
| lib/Service/SettingsService.php | updatePublishingOptions | SET-008/009 | 0.95 | Update entry |
| lib/Service/SettingsService.php | loadSettings | SET-003/006 | 0.97 | Spec scenario verbatim |
| lib/Service/SettingsService.php | updateObjectTypeConfiguration | SET-003 | 0.92 | Helper of loadSettings |
| lib/Service/SettingsService.php | shouldLoadSettings | SET-006 | 0.97 | Spec scenario verbatim |
| lib/Service/SettingsService.php | getVersionInfo | SET-010 | 0.97 | Endpoint impl |
| lib/Service/SettingsService.php | manualImport | SET-007 | 0.97 | Scenario verbatim |
| lib/Service/CatalogiService.php | getAvailableRegisters | SET-001/013 | **0.75 NEEDS-REVIEW** | Cross-capability helper used by Settings |
| lib/Service/CatalogiService.php | getAvailableSchemas | SET-001/013 | **0.75 NEEDS-REVIEW** | Cross-capability helper used by Settings |

### capability: auto-publishing → APB-NNN tasks (11 methods)

| File | Method | REQ | Conf | Signal |
|---|---|---|---|---|
| lib/Listener/ObjectCreatedEventListener.php | handle | APB-001/003/007/014/015 | 0.97 | Spec Event Flow names this listener |
| lib/Listener/ObjectCreatedEventListener.php | convertObjectEntityToArray | APB-006 | 0.9 | Helper builds @self metadata |
| lib/Listener/ObjectUpdatedEventListener.php | handle | APB-002/004/008/009/014/015 | 0.95 | Spec Event Flow names this listener |
| lib/Listener/ObjectUpdatedEventListener.php | shouldProcessUpdate | APB-008/009 | 0.92 | Detects published transition |
| lib/Listener/ObjectUpdatedEventListener.php | isObjectEntityPublished | APB-006 | 0.92 | Timestamp comparison |
| lib/Listener/ObjectUpdatedEventListener.php | isObjectPublished | APB-006 | 0.92 | Timestamp comparison |
| lib/Listener/ObjectUpdatedEventListener.php | convertObjectEntityToArray | APB-006 | 0.9 | @self.files=[] avoids recursion |
| lib/Service/EventService.php | handleObjectCreateEvents | APB-003/012/013 | 0.97 | Result structure matches APB-012 |
| lib/Service/EventService.php | handleObjectUpdateEvents | APB-004/012/013 | 0.97 | Same |
| lib/Service/EventService.php | shouldAutoPublishObject | APB-005 | 0.97 | Spec names this method |
| lib/Service/EventService.php | isObjectPublished | APB-006 | 0.92 | Timestamp comparison |
| lib/Service/EventService.php | publishObject | APB-003 | 0.97 | Spec Event Flow names this method |
| lib/Service/EventService.php | publishObjectAttachments | APB-004/010/011 | 0.97 | FileMapper + skip already-shared (APB-010/011) |

### capability: catalogs → CAT-NNN tasks (14 methods)

| File | Method | REQ | Conf | Signal |
|---|---|---|---|---|
| lib/Controller/CatalogiController.php | index | CAT-001/008/009 | 0.95 | GET /api/catalogi list, CORS, public-page annotations |
| lib/Controller/CatalogiController.php | show | CAT-002/008/009 | 0.95 | GET /api/catalogi/{id} via CatalogiService::index |
| lib/Listener/CatalogCacheEventListener.php | handle | CAT-011/005/006/007 | 0.97 | Spec table maps events→cache ops exactly |
| lib/Listener/CatalogCacheEventListener.php | extractObjectFromEvent | CAT-011 | 0.9 | Helper |
| lib/Listener/CatalogSchemaEventListener.php | handle | CAT-003/010 | 0.85 | Pre-save slug-to-id rewriting; multi-schema support |
| lib/Listener/CatalogSchemaEventListener.php | getEntityFromEvent | CAT-003/010 | 0.85 | Helper |
| lib/Service/CatalogiService.php | computeRewrittenRegistersAndSchemas | CAT-003/010 | 0.85 | Slug-to-id rewrite |
| lib/Service/CatalogiService.php | rewriteSchemasAndRegisters | CAT-003/010 | 0.85 | Public wrapper |
| lib/Service/CatalogiService.php | getCatalogFilters | CAT-002/010 | 0.85 | Filter builder for catalog scope |
| lib/Service/CatalogiService.php | getConfig | CAT-004 | 0.8 | Helper — IAppConfig values |
| lib/Service/CatalogiService.php | getCatalogBySlug | CAT-005 | 0.97 | Spec Cache-Operations table verbatim |
| lib/Service/CatalogiService.php | invalidateCatalogCache | CAT-006 | 0.97 | Spec Cache-Operations table verbatim |
| lib/Service/CatalogiService.php | invalidateCatalogCacheById | CAT-006 | 0.97 | Spec Cache-Operations table verbatim |
| lib/Service/CatalogiService.php | warmupCatalogCache | CAT-007 | 0.97 | Spec Cache-Operations table verbatim |
| lib/Service/CatalogiService.php | warmupCatalogCacheById | CAT-007 | 0.97 | Spec Cache-Operations table verbatim |
| lib/Service/CatalogiService.php | index | CAT-002 | 0.92 | Returns publications scoped to catalog |

### capability: cms-tool → CMS-T-NNN tasks (12 methods)

| File | Method | REQ | Conf | Signal |
|---|---|---|---|---|
| lib/Listener/ToolRegistrationListener.php | handle | CMS-T-014 | 0.97 | Spec Registration-Flow names ID `opencatalogi.cms` |
| lib/Tool/CMSTool.php | getFunctions | CMS-T-001..006 | 0.97 | All 5 OpenAI function definitions |
| lib/Tool/CMSTool.php | executeFunction | CMS-T-001/013 | 0.97 | ToolInterface dispatch + structured errors |
| lib/Tool/CMSTool.php | createPage | CMS-T-002/007/008/009 | 0.97 | Auto-slug + organisation from agent |
| lib/Tool/CMSTool.php | listPages | CMS-T-003/009 | 0.97 | List with optional limit |
| lib/Tool/CMSTool.php | createMenu | CMS-T-004/008/009/015 | 0.97 | Items validation + organisation |
| lib/Tool/CMSTool.php | listMenus | CMS-T-005/009 | 0.97 | List menus |
| lib/Tool/CMSTool.php | addMenuItem | CMS-T-006/008/009 | 0.97 | Append item to menu by ID |
| lib/Tool/CMSTool.php | generateSlug | CMS-T-007 | 0.97 | URL-friendly slug from title |
| lib/Tool/CMSTool.php | resolveParameterValue | CMS-T-010/011 | 0.9 | __call snake→camel + type cast |
| lib/Tool/CMSTool.php | castParameterValue | CMS-T-011 | 0.95 | 'null' string + integer/boolean coercion |
| lib/Tool/CMSTool.php | castToArray | CMS-T-011 | 0.85 | Array coercion helper |

### capability: content-management → CMS-NNN tasks (6 methods)

| File | Method | REQ | Conf | Signal |
|---|---|---|---|---|
| lib/Controller/PagesController.php | index | CMS-001/005/006 | 0.95 | GET /api/pages |
| lib/Controller/PagesController.php | show | CMS-002/006 | 0.95 | GET /api/pages/{slug} |
| lib/Controller/MenusController.php | index | CMS-010/014/015/016 | 0.95 | GET /api/menus + default fallback schema=7,register=1 |
| lib/Controller/MenusController.php | show | CMS-011/016 | 0.95 | GET /api/menus/{id} |
| lib/Controller/ThemesController.php | index | CMS-020/022/023/024 | 0.95 | GET /api/themes + facets |
| lib/Controller/ThemesController.php | show | CMS-021/024 | 0.95 | GET /api/themes/{id} |
| lib/Controller/GlossaryController.php | index | CMS-030/033/034/035 | 0.95 | GET /api/glossary, _source=database, published=false |
| lib/Controller/GlossaryController.php | show | CMS-031/035 | 0.92 | GET /api/glossary/{id} |

### capability: dashboard → DSH-/LST-/DIR-NNN tasks (24 methods)

| File | Method | REQ | Conf | Signal |
|---|---|---|---|---|
| lib/AppInfo/Application.php | register | DSH-005/006/007/008 | 0.97 | Bootstrap matches spec Application.php section |
| lib/Controller/DashboardController.php | page | DSH-001 | 0.95 | SPA template render |
| lib/Controller/DirectoryController.php | index | DIR-001/008 | 0.95 | GET /api/directory + CORS |
| lib/Controller/DirectoryController.php | update | DIR-002 | 0.95 | POST /api/directory sync URL |
| lib/Controller/ListingsController.php | index | LST-001/006 | 0.95 | GET /api/listings |
| lib/Controller/ListingsController.php | show | LST-002/006 | 0.95 | GET /api/listings/{id} (PublicPage) |
| lib/Controller/ListingsController.php | create | LST-003/006 | 0.95 | POST /api/listings |
| lib/Controller/ListingsController.php | update | LST-004/006 | 0.95 | PUT /api/listings/{id} |
| lib/Controller/ListingsController.php | destroy | LST-005 | 0.95 | DELETE /api/listings/{id} |
| lib/Controller/ListingsController.php | synchronise | DIR-002/003/004 | 0.92 | POST /api/listings/sync — both single & all |
| lib/Controller/ListingsController.php | add | DIR-005/008 | 0.95 | POST /api/listings/add (PublicPage) |
| lib/Cron/Broadcast.php | run | DIR-007 | 0.9 | Method exists; spec acknowledges info.xml registration BUG |
| lib/Cron/DirectorySync.php | run | DIR-004 | 0.95 | Hourly cron → doCronSync |
| lib/Dashboard/CatalogWidget.php | load | DSH-005 | 0.9 | Widget asset registration |
| lib/Dashboard/UnpublishedAttachmentsWidget.php | load | DSH-005 | 0.9 | Widget asset registration |
| lib/Dashboard/UnpublishedPublicationsWidget.php | load | DSH-005 | 0.9 | Widget asset registration |
| lib/Service/BroadcastService.php | broadcast | DIR-007/006 | 0.85 | Broadcast iterates unique URLs |
| lib/Service/BroadcastService.php | getCurrentDirectoryUrl | DIR-007 | 0.8 | Helper |
| lib/Service/BroadcastService.php | getDirectoryUrls | DIR-007/006 | 0.8 | Helper |
| lib/Service/BroadcastService.php | sendBroadcastRequest | DIR-007/006 | 0.8 | Helper |
| lib/Service/DirectoryService.php | doCronSync | DIR-004 | 0.95 | Cron entry-point |
| lib/Service/DirectoryService.php | getUniqueDirectories | DIR-006 | 0.9 | Anti-loop 5-min cache |
| lib/Service/DirectoryService.php | syncDirectory | DIR-002/003/005/010/011 | 0.95 | Main sync entry-point |
| lib/Service/DirectoryService.php | syncListing | DIR-010/011 | 0.85 | Per-listing helper |
| lib/Service/DirectoryService.php | detectPublicationEndpoint | DIR-009 | 0.95 | Spec-named feature |
| lib/Service/DirectoryService.php | isListingDataOutdated | DIR-010 | 0.9 | Staleness check |
| lib/Service/DirectoryService.php | extractTimestamp | DIR-010 | 0.85 | Helper |
| lib/Service/DirectoryService.php | updateDirectoryStatusOnError | DIR-002 | **0.75 NEEDS-REVIEW** | Error-status update |
| lib/Service/DirectoryService.php | isSystemBroadcast | DIR-006 | 0.85 | Anti-loop |
| lib/Service/DirectoryService.php | isLocalUrl | DIR-006 | 0.85 | Anti-loop |
| lib/Service/DirectoryService.php | getDirectory | DIR-001/011 | 0.95 | Combined-directory builder |
| lib/Service/DirectoryService.php | convertCatalogToListing | DIR-011 | 0.95 | Spec-named conversion |
| lib/Service/DirectoryService.php | filterListingProperties | DIR-011 | 0.85 | Helper |
| lib/Service/DirectoryService.php | convertCatalogiToListings | DIR-011 | 0.9 | Bulk variant |
| lib/Service/DirectoryService.php | expandSchemas | DIR-011 | **0.70 NEEDS-REVIEW** | Could also map to SET-013 |
| lib/Service/DirectoryService.php | processSchemaExpansion | DIR-011 | **0.70 NEEDS-REVIEW** | Helper |

### capability: download-service → DWN-NNN tasks (6 methods)

| File | Method | REQ | Conf | Signal |
|---|---|---|---|---|
| lib/Service/DownloadService.php | createPublicationFile | DWN-001/002/003/004/008 | 0.95 | Scenario verbatim |
| lib/Service/DownloadService.php | getPublicationData | DWN-010 | 0.9 | Helper handles not-found |
| lib/Service/DownloadService.php | saveFileToNextCloud | DWN-002/003 | 0.9 | Saves PDF + share link |
| lib/Service/DownloadService.php | prepareZip | DWN-005/006 | 0.95 | Bijlagen/ folder structure |
| lib/Service/DownloadService.php | createPublicationZip | DWN-005/006/009 | 0.95 | Scenario verbatim |
| lib/Service/DownloadService.php | publicationAttachments | DWN-005 | 0.85 | Helper |

### capability: federation → FED-NNN tasks (15 methods)

| File | Method | REQ | Conf | Signal |
|---|---|---|---|---|
| lib/Controller/FederationController.php | publications | FED-001/007/012 | 0.95 | Delegates to getAggregatedPublications |
| lib/Controller/FederationController.php | publication | FED-002/007/012 | 0.95 | Delegates to getFederatedPublication |
| lib/Controller/FederationController.php | publicationUses | FED-003/007/012 | 0.95 | Delegates to getFederatedUses |
| lib/Controller/FederationController.php | publicationUsed | FED-004/007/012 | 0.95 | Delegates to getFederatedUsed |
| lib/Controller/FederationController.php | publicationAttachments | FED-005/007/012 | 0.95 | Delegates to attachments |
| lib/Controller/FederationController.php | publicationDownload | FED-006/007/012 | 0.95 | Delegates to download |
| lib/Service/DirectoryService.php | getPublications | FED-008/010 | 0.8 | Remote aggregation feed |
| lib/Service/DirectoryService.php | getUsed | FED-004/008 | 0.85 | Federated used-by |
| lib/Service/DirectoryService.php | getPublication | FED-002 | 0.85 | Federated single fetch |
| lib/Service/DirectoryService.php | aggregateFacets | FED-008 | 0.8 | Facet merging |
| lib/Service/PublicationService.php | getExternalCatalogsFromListings | FED-009/010 | 0.85 | integrationLevel=search filter |
| lib/Service/PublicationService.php | getAggregatedPublications | FED-001/008/011 | 0.97 | Spec-named aggregator |
| lib/Service/PublicationService.php | getLocalPublicationsFast | FED-001 | 0.8 | Local fast path |
| lib/Service/PublicationService.php | getLocalPublicationsUltraFast | FED-001 | 0.8 | Alternative path |
| lib/Service/PublicationService.php | getLocalCatalogs | FED-001 | 0.8 | Helper |
| lib/Service/PublicationService.php | mergeFacetsData | FED-008 | 0.95 | Spec scenario |
| lib/Service/PublicationService.php | mergeFacetableData | FED-008 | 0.85 | Helper |
| lib/Service/PublicationService.php | applyCumulativeOrdering | FED-011 | 0.85 | _score sort |
| lib/Service/PublicationService.php | extractFieldValue | FED-011 | **0.70 NEEDS-REVIEW** | Helper for ordering |
| lib/Service/PublicationService.php | compareValues | FED-011 | **0.70 NEEDS-REVIEW** | Helper for ordering |
| lib/Service/PublicationService.php | getFederatedPublication | FED-002 | 0.97 | Spec-named |
| lib/Service/PublicationService.php | getFederatedUsed | FED-004 | 0.97 | Spec-named |
| lib/Service/PublicationService.php | getFederatedUses | FED-003 | 0.97 | Spec-named |

### capability: file-management → FIL-NNN tasks (15 methods)

| File | Method | REQ | Conf | Signal |
|---|---|---|---|---|
| lib/Service/FileService.php | getPublicationFolderName | FIL-009 | 0.95 | "({id}) {title}" format per spec |
| lib/Service/FileService.php | getShareLink | FIL-007 | 0.95 | Full URL with protocol+domain |
| lib/Service/FileService.php | getCurrentDomain | FIL-007 | 0.85 | Helper |
| lib/Service/FileService.php | findShare | FIL-006 | 0.95 | Spec-named |
| lib/Service/FileService.php | createShare | FIL-005 | 0.9 | Helper |
| lib/Service/FileService.php | createShareLink | FIL-005 | 0.97 | Spec-named (defaults shareType=3, perms=1) |
| lib/Service/FileService.php | handleFile | FIL-008/009/010 | 0.97 | Spec scenario verbatim |
| lib/Service/FileService.php | checkUploadedFile | FIL-008 | 0.9 | Validation helper |
| lib/Service/FileService.php | createFolder | FIL-001 | 0.97 | Spec-named |
| lib/Service/FileService.php | addFileInfoToData | FIL-010 | 0.97 | Spec-named |
| lib/Service/FileService.php | uploadFile | FIL-002 | 0.97 | Spec-named |
| lib/Service/FileService.php | updateFile | FIL-003 | 0.97 | Spec-named (createNew flag) |
| lib/Service/FileService.php | deleteFile | FIL-004 | 0.97 | Spec-named |
| lib/Service/FileService.php | createPdf | FIL-011 | 0.97 | Twig+mPDF, spec-named |
| lib/Service/FileService.php | createZip | FIL-012 | 0.97 | Spec-named |
| lib/Service/FileService.php | downloadZip | FIL-013/014 | 0.97 | Spec-named, cleanup |

### capability: prometheus-metrics → PROM tasks (10 methods)

Spec uses heading-style requirements; this scanner emits two synthetic IDs (`PROM-metrics-endpoint`, `PROM-health-endpoint`) for annotation. Future pass should give each `### Requirement:` block a stable PROM-NNN id.

| File | Method | REQ | Conf | Signal |
|---|---|---|---|---|
| lib/Controller/MetricsController.php | index | PROM-metrics-endpoint | 0.95 | GET /api/metrics text/plain |
| lib/Controller/MetricsController.php | collectMetrics | PROM-metrics-endpoint | 0.95 | Full Prometheus exposition format |
| lib/Controller/MetricsController.php | getPublicationCounts | PROM-metrics-endpoint | 0.9 | Direct SQL helper |
| lib/Controller/MetricsController.php | countObjectsBySchemaPattern | PROM-metrics-endpoint | 0.9 | Direct SQL helper |
| lib/Controller/MetricsController.php | getListingCounts | PROM-metrics-endpoint | 0.9 | Direct SQL helper |
| lib/Controller/MetricsController.php | countSearchRequests | PROM-metrics-endpoint | 0.85 | openregister_metrics fallback to 0 |
| lib/Controller/MetricsController.php | isDatabaseHealthy | PROM-metrics-endpoint | 0.9 | opencatalogi_up gauge |
| lib/Controller/MetricsController.php | countDirectoryEntries | PROM-metrics-endpoint | 0.9 | Federation health metric |
| lib/Controller/HealthController.php | index | PROM-health-endpoint | 0.9 | /api/health JSON |
| lib/Controller/HealthController.php | checkDatabase | PROM-health-endpoint | 0.88 | Helper of index |
| lib/Controller/HealthController.php | checkFilesystem | PROM-health-endpoint | 0.88 | Helper of index |
| lib/Controller/HealthController.php | checkSearchBackend | PROM-health-endpoint | 0.85 | ElasticSearchService lookup is dead (3a) but health check itself is in-scope |

### capability: publications → PUB-NNN tasks (15 methods)

| File | Method | REQ | Conf | Signal |
|---|---|---|---|---|
| lib/Controller/PublicationsController.php | index | PUB-001/003/004/010/012/013/015 | 0.97 | Multi-schema search, CORS, RBAC for PUB-015 |
| lib/Controller/PublicationsController.php | show | PUB-002/011/014 | 0.95 | Fast path + fallback findObjectLocation |
| lib/Controller/PublicationsController.php | attachments | PUB-006/010 | 0.95 | Delegates to publicationService.attachments |
| lib/Controller/PublicationsController.php | download | PUB-007/010 | 0.95 | Delegates to download |
| lib/Controller/PublicationsController.php | uses | PUB-008/010 | 0.95 | getObjectUses + findObjectLocation |
| lib/Controller/PublicationsController.php | used | PUB-009/010 | 0.95 | getObjectUsedBy |
| lib/Controller/PublicationsController.php | findObjectLocation | PUB-014 | 0.97 | Spec Gap-22 names this method verbatim |
| lib/Controller/PublicationsController.php | stripEmptyValues | PUB-001 | 0.8 | _empty query parameter |
| lib/Service/PublicationService.php | setObjectServiceContext | PUB-014 | **0.75 NEEDS-REVIEW** | register/schema context for RelationHandler |
| lib/Service/PublicationService.php | getCatalogFilters | PUB-003 | 0.85 | Filter builder |
| lib/Service/PublicationService.php | getAvailableRegisters | PUB-003 | **0.70 NEEDS-REVIEW** | Helper |
| lib/Service/PublicationService.php | getAvailableSchemas | PUB-003 | **0.70 NEEDS-REVIEW** | Helper |
| lib/Service/PublicationService.php | searchPublications | PUB-001/003/013 | 0.9 | Core helper |
| lib/Service/PublicationService.php | addVirtualFieldFacets | PUB-001 | **0.70 NEEDS-REVIEW** | Could align with SCH-008 |
| lib/Service/PublicationService.php | index | PUB-001/003 | 0.9 | Internal index used by federation |
| lib/Service/PublicationService.php | show | PUB-002 | 0.9 | Single publication |
| lib/Service/PublicationService.php | attachments | PUB-006 | 0.95 | Spec dependency names this |
| lib/Service/PublicationService.php | download | PUB-007 | 0.95 | Spec dependency names this |
| lib/Service/PublicationService.php | filterUnwantedProperties | PUB-001 | 0.85 | Strips @self per scenario |
| lib/Service/PublicationService.php | uses | PUB-008 | 0.95 | Spec dependency |
| lib/Service/PublicationService.php | used | PUB-009 | 0.95 | Spec dependency |

### capability: woo-compliance → WOO-NNN tasks (8 methods)

| File | Method | REQ | Conf | Signal |
|---|---|---|---|---|
| lib/Controller/RobotsController.php | index | WOO-004/008/009 | 0.95 | Note: WOO-008 hasWooSitemap check is the Bug, see Bucket 4 |
| lib/Controller/SitemapController.php | index | WOO-001/007/009 | 0.95 | buildSitemapIndex per category |
| lib/Controller/SitemapController.php | sitemap | WOO-002/005/006/009/010 | 0.95 | buildSitemap paginated |
| lib/Service/SitemapService.php | buildSitemapIndex | WOO-001/005/007 | 0.97 | Spec dependency |
| lib/Service/SitemapService.php | buildSitemap | WOO-002/005/006/010 | 0.97 | Spec dependency |
| lib/Service/SitemapService.php | isValidSitemapRequest | WOO-007/008 | 0.97 | hasWooSitemap check for individual sitemaps |
| lib/Service/SitemapService.php | mapDiwooDocument | WOO-006/010 | 0.97 | DIWOO XML mapping |

### Across-capability summary

158 methods named explicitly + ~37 inherited helpers that follow callers into Bucket 1 via Pass B. Total Bucket 1: **195**.

## Bucket 2a — Existing capability, no REQ (reverse-spec --extend)

Most Vue/JS components belong to a capability that already has spec coverage but the component itself isn't named in the spec's UI section. Bias toward `--extend` to add a "UI components" sub-section per capability.

### cluster: publications (7 entries) → `/opsx-reverse-spec opencatalogi --extend publications`
PublicationIndex.vue, PublicationDetail.vue, PublicationDetailPage.vue, PublicationList.vue, PublicationTable.vue, PublishPublicationDialog.vue, store/modules/object.js

### cluster: catalogs (7 entries) → `--extend catalogs`
CatalogiIndex.vue, CatalogDetailPage.vue, CatalogModal.vue, ViewCatalogi.vue, CatalogiWidget.vue, catalogiWidget.js, store/modules/catalog.js

### cluster: content-management (21 entries) → `--extend content-management`
Page/Menu/Theme/Glossary index/detail/modal/dialog files + services/getTheme.js, getPublicationTypeId.js

### cluster: dashboard (17 entries) → `--extend dashboard`
App.vue, Dashboard.vue, DirectoryIndex.vue, sidebars, router/index.js, listing & directory modals, both widget bundle entry-points

### cluster: search (7 entries) → `--extend search`
**includes SearchController::index** (delegates to PublicationService::index — judgement call: either annotate to SCH-001/002 or extend the search spec to reference it). Plus SearchIndex.vue, SearchSideBar.vue, SearchResults.vue, FacetComponent.vue, search.js, search.ts (duplicate)

### cluster: admin-settings (3 entries) → `--extend admin-settings`
Settings.vue, UserSettings.vue (extra — UserSettings is not in spec), settings.js

### cluster: file-management (5 entries) → `--extend file-management`
DeleteAttachmentDialog, MassAttachmentModal, UseFileSelection.js, UploadFiles.vue, EditAttachmentModal.vue

## Bucket 2b — No capability owner (reverse-spec --cluster)

### cluster: generic-object-modals (27 entries) → `/opsx-reverse-spec opencatalogi --cluster generic-object-modals`
14 `modals/object/*` + 2 `dialogs/generic/*` + 1 `dialogs/logs/*` + 2 `dialogs/category/*` + 1 `views/shared/EntityDetailPage.vue` + 7 generic component files. None of these are referenced in any spec; they are generic OR-object editors brought in alongside the spec'd object types.

### cluster: entity-typescript-models (11 entries) → `--cluster entity-typescript-models`
TypeScript entity models + mocks/types for each object type (`src/entities/<type>/{*.ts,*.types.ts,*.mock.ts,index.js}`). Each has 3-4 sibling files. `publicationType` is extra — not in the spec's 7 object types.

### cluster: frontend-services (12 entries) → `--cluster frontend-services`
Vuex/Pinia setup, eventBus, root store, navigation store, modal/dialog/sidebar hosts, generic services (formatZodErrors, getValidISOstring, nextcloudGroups, publicationStatus, schemaHelpers).

## Bucket 3 — Surfaced for human triage

### 3a — possibly broken (1 entry)

- **search#SCH-006** (ElasticSearch integration) — removed-lines cache matched 103 references to `ElasticSearchService`. The class previously existed in opencatalogi and was removed; `HealthController::checkSearchBackend` still attempts a container lookup for it (silently catches the exception). The spec itself records this as `Not Implemented (no ElasticSearchService in OpenCatalogi)` — recommend either restoring the service or removing the dead lookup in HealthController.

### 3b — never implemented (2 entries)

- **org-archimate-export#all-14-requirements** — **MISFILED SPEC.** This is a softwarecatalog feature describing GEMMA ArchiMate AMEFF export. Zero implementation in opencatalogi's `lib/` (no `ApplicationComponent`/`SpecializationRelationship`/`AMEFF`/`GEMMA`/`referentiecomponent`/`deelnames`/`archimate` references in current code or in git history). **Recommend moving this spec to `softwarecatalog/openspec/specs/` and deleting from opencatalogi.**
- **dashboard#DSH-004** — Spec itself marks this as `Dead Code (route exists but controller method removed)`. The `/index` route in `appinfo/routes.php` points at a `DashboardController::index` method that no longer exists. Either restore the method or strip the route.

## Bucket 4 — ADR conformance findings

| Rule | Files | Note |
|---|---|---|
| missing-spec-in-file-docblock | All 44 lib/ files | The normal pre-retrofit state; the entire point of `/opsx-annotate` |
| stale-docblock-license | lib/Controller/GlossaryController.php | Class docblock claims `AGPL-3.0-or-later`; file header correctly says EUPL-1.2. Two competing license headers — drop the AGPL one |
| debug-logging-left-in | lib/Listener/ObjectUpdatedEventListener.php | Three `$logger->debug("OPENCATALOGI_EVENT_LISTENER_CALLED_AT_...")` lines spec itself flags as temporary |
| typo-in-use-statement | lib/Service/FileService.php | `use Mpdf\MpMpdfdf;` (should be `use Mpdf\Mpdf;`). Code works because Mpdf class is referenced directly |
| broadcast-cron-not-registered | appinfo/info.xml | `Broadcast` class exists but not in `<background-jobs>` — DIR-007 is therefore non-functional |
| robots-controller-misses-haswoosite-check | lib/Controller/RobotsController.php | WOO-008 spec rule says only `hasWooSitemap=true` catalogs should appear in robots.txt; controller emits all catalogs with a slug |
| duplicate-edit-listing-modal | src/modals/directory/EditListingModal.vue + src/modals/listing/EditListingModal.vue | Same name, different paths — one is dead code |
| duplicate-search-store | src/store/modules/search.js + search.ts | JS and TS side-by-side — mid-migration leftover |

**No forbidden patterns found** (no `var_dump` / `die` / `dd(` / `print_r` / `error_log` / `dump` in `lib/` or `src/`).
**No missing `@license` in `lib/` PHP files** (all carry the EUPL-1.2 header).

Direct SQL is present (MetricsController, PublicationsController, HealthController, PublicationService) — but in all cases it is intentional (Prometheus metric aggregation across schemas + magic-table scanning that OpenRegister's ObjectService cannot do generically). Not flagged.

## Notes for the human reviewer

1. **Most pressing finding: the `org-archimate-export` spec is in the wrong repo.** It describes a softwarecatalog feature (GEMMA ArchiMate export). All 14 of its REQs and all of its 48 in-flight change deltas (`deelnames-gebruik`, `module-overlay-rendering`, `register-i18n`, `view-enrichment-api`, `woo-transparency`) are softwarecatalog-shaped. Recommend a one-off `git mv` to relocate them before running `/opsx-annotate` so they don't pollute the opencatalogi coverage baseline going forward.

2. **The `cms-tool` spec uses the 5-character prefix `CMS-T-NNN` which the canonical REQ regex `[A-Z]{2,4}-[0-9]+` misses.** This scanner inventoried them correctly via the table-row pattern, but `/opsx-annotate` should be tested against this case explicitly.

3. **`prometheus-metrics` and `org-archimate-export` use the heading-style REQ format (`### Requirement:` / `#### Scenario:`)** instead of REQ tables. The scanner emits two synthetic PROM IDs to allow Bucket 1 entries to point at coherent units, but a future cleanup pass should give each `### Requirement:` block a stable `PROM-NNN` id (similar to how dashboard and admin-settings nest sub-IDs under capability prefixes).

4. **Branch is non-default.** Working tree is on `feature/declarative-annotation-pilot`, not `development`. Specs and code on this branch were used for the scan; verify both branches' specs are in sync before annotating.

5. **`SearchController::index` is the only ambiguous Bucket-1 candidate.** It's a one-line delegation to `PublicationService::index` — could be plumbing or could be the canonical hook for SCH-001/002/004/005. Currently placed in Bucket 2a (search cluster) so a human can choose; not in plumbing because the route is publicly documented.

6. **Bucket 2b's `entity-typescript-models` cluster is large (11 sub-trees ~ 44 files) but uniform.** Each models a single object type. They should probably get a single shared spec extension ("TypeScript entity models follow this pattern") rather than per-file REQs.

7. **`Vue` files are bucketed at file level, not method level.** PHP got per-method granularity (158 named methods in Bucket 1) but Vue/JS was descoped to keep the report human-actionable. The next iteration of this scanner should pick a representative method per Vue file (the `setup()` or main computed/methods block) and surface it.

8. **The reverse pass was fast** (2.4s on this repo; 172,500 removed-lines cached). Only one Bucket 3a hit (`ElasticSearchService`) — meaningful signal that the codebase doesn't have a long graveyard of removed implementations.

9. **5 in-flight changes in `openspec/changes/` (48 REQ deltas) are NOT in scope.** They are softwarecatalog-shaped (GEMMA, modules, referentiecomponenten, VNG). They were tallied for completeness but not scored against opencatalogi code.

10. **Plumbing count is high (135).** Most of this is the 17 CORS-aware controllers each carrying `__construct` + `getObjectService` + `preflightedCors` + `getXxxConfiguration`. Worth a refactor to extract a `CorsAwareController` base class — that would cut the plumbing count to ~80 and make the next coverage scan cleaner.
