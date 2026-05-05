---
status: draft
priority: high
estimated_effort: large
---

# Method Decomposition — OpenCatalogi

## Goal
Eliminate 110 PHPMD complexity suppressions by decomposing complex methods into smaller, focused units. Each suppression represents a method or class that exceeds PHPMD's strict thresholds (CC>10, NPath>200, MethodLength>100, ClassLength>1000).

## Current State
- **CyclomaticComplexity suppressions:** 43 (methods with >10 branches)
- **NPathComplexity suppressions:** 26 (methods with >200 execution paths)
- **ExcessiveMethodLength suppressions:** 22 (methods >100 lines)
- **ExcessiveClassComplexity suppressions:** 9 (classes with too much logic)
- **ExcessiveClassLength suppressions:** 2 (classes >1000 lines)
- **CouplingBetweenObjects suppressions:** 8 (too many dependencies)
- **TooManyMethods suppressions:** 0

## Files Requiring Decomposition

### Priority 1 — Highest complexity (files with 5+ suppressions)

**lib/Service/DirectoryService.php** (28 suppressions)
Core directory service handling federation, harvesting, and catalogue synchronization across distributed OpenCatalogi instances. Class-level suppressions (3) for class length, class complexity, and coupling. Method-level suppressions on 8+ methods including `syncExternalCatalog` (CC+NPath+MethodLength), `harvestDirectory` (CC+NPath+MethodLength), `syncPublications` (CC+NPath+MethodLength), `syncOrganizations` (CC+NPath+MethodLength), `federatedSearch` (CC+NPath+MethodLength), `registerDirectory` (CC+NPath+MethodLength), `syncAttachments` (CC+NPath+MethodLength), and `validateDirectoryEntry` (CC+NPath).

**lib/Service/PublicationService.php** (24 suppressions)
Publication management service handling CRUD, validation, metadata extraction, and DCAT/Schema.org compliance. Class-level suppressions (3) for class length, class complexity, and coupling. Method-level suppressions on `createPublication` (CC+NPath+MethodLength), `updatePublication` (CC+NPath+MethodLength), `validatePublication` (CC+NPath+MethodLength), `enrichPublication` (CC+NPath+MethodLength), `handleAttachments` (CC+NPath+MethodLength), `buildDcatResponse` (CC+NPath+MethodLength), `resolveThemes` (CC), `buildSearchResponse` (CC+NPath+MethodLength), `validateRequiredFields` (CC), and `applyAccessControl` (CC).

**lib/Controller/PublicationsController.php** (12 suppressions)
Publications REST controller with complex create, update, publish, and unpublish endpoints. Class-level suppressions (2) for class complexity and coupling. Method-level suppressions on `create` (CC+NPath+MethodLength), `update` (CC+NPath+MethodLength), `publish` (CC+MethodLength), and `unpublish` (CC+MethodLength).

**lib/Service/SettingsService.php** (8 suppressions)
Application settings management with validation, defaults, and multi-source configuration. Class-level suppressions (2) for class complexity and NPath. Method-level suppressions on `getSettings` (CC), `validateSettings` (CC), `migrateSettings` (CC), `resolveSourceConfig` (CC), `buildSearchConfig` (CC), and `getSearchSettings` (CC+NPath).

**lib/Service/SearchService.php** (6 suppressions)
Search service with multi-backend support (database, Elasticsearch, Solr). Class-level suppression for class complexity. Method-level suppressions on `search` (CC+NPath+MethodLength) and `buildSearchQuery` (CC+NPath).

**lib/Tool/CMSTool.php** (5 suppressions)
CMS tool for publication management via MCP/tool interface. Class-level suppression for class complexity. Method-level suppressions on `execute` (MethodLength), `handlePublicationCreate` (CC+NPath), and `handlePublicationSearch` (CC).

**lib/Service/CatalogiService.php** (5 suppressions)
Catalogue management service. Class-level suppressions (2) for class complexity and coupling. Method-level suppressions on `syncCatalog` (CC+NPath+MethodLength).

### Priority 2 — Medium complexity (files with 2-4 suppressions)

- `lib/Service/EventService.php` (3) — Event service with class complexity and 2 CC suppressions
- `lib/Service/ElasticSearchService.php` (3) — Elasticsearch integration with 2 CC and 1 NPath suppression
- `lib/Controller/ThemesController.php` (2) — Themes controller with CC + NPath
- `lib/Controller/ListingsController.php` (2) — Listings controller with coupling + CC
- `lib/Controller/GlossaryController.php` (2) — Glossary controller with CC + NPath
- `lib/Service/FileService.php` (2) — File service with class complexity + coupling
- `lib/Listener/CatalogCacheEventListener.php` (2) — Cache event listener with CC + NPath

### Priority 3 — Single suppressions

- `lib/Service/SitemapService.php` (1) — ExcessiveMethodLength
- `lib/Service/BroadcastService.php` (1) — CouplingBetweenObjects
- `lib/Migration/Version6Date20241011085015.php` (1) — ExcessiveMethodLength
- `lib/Listener/ObjectUpdatedEventListener.php` (1) — CyclomaticComplexity
- `lib/Http/XMLResponse.php` (1) — CyclomaticComplexity
- `lib/AppInfo/Application.php` (1) — CouplingBetweenObjects

## Decomposition Strategy

### For CyclomaticComplexity (>10 branches)
Extract conditional branches into private helper methods:
- Guard clauses: Extract early-return validation into `validate{Thing}()` methods
- Switch-like logic: Extract case handlers into `handle{Case}()` methods
- Nested conditions: Flatten by extracting inner blocks into descriptive methods

### For NPathComplexity (>200 paths)
Reduce execution paths by:
- Breaking method into pipeline stages (each stage = private method)
- Extracting independent conditional blocks into separate methods
- Using early returns to eliminate nested paths

### For ExcessiveMethodLength (>100 lines)
Split long methods into logical phases:
- Validation phase -> `validate{Input}()`
- Preparation phase -> `prepare{Data}()`
- Processing phase -> `process{Thing}()`
- Response phase -> `build{Response}()`

### For ExcessiveClassComplexity / ExcessiveClassLength
Extract method groups into Handler classes (existing pattern in codebase):
- Create `{ClassName}/{HandlerName}Handler.php`
- Move related methods to the handler
- Inject handler via constructor
- Delegate from original methods (keep public API stable)

### For CouplingBetweenObjects (>13 dependencies)
Reduce constructor parameters by:
- Grouping related dependencies into a single service
- Using lazy loading for rarely-used dependencies
- Moving methods that use specific deps to handler classes

## Testing Strategy

### Before decomposition
1. Run existing unit tests: `docker exec -w /var/www/html/custom_apps/opencatalogi nextcloud php vendor/bin/phpunit -c phpunit-unit.xml`
2. Note any pre-existing failures
3. Run PHPMD to record current suppression count: `./vendor/bin/phpmd lib/ text phpmd.xml 2>&1 | wc -l`

### During decomposition (per method)
1. Verify `php -l` passes on all changed files
2. Run unit tests for the specific class: `--filter ClassName`
3. Run PHPMD on the specific file to confirm suppression can be removed

### After decomposition
1. Full unit test suite passes
2. PHPMD reports 0 violations (no new warnings)
3. Total suppression count reduced by expected amount
4. `composer check:strict` passes
5. Manual smoke test in browser (http://localhost:3000)

## Acceptance Criteria
- [ ] All CyclomaticComplexity suppressions eliminated or reduced to <=5
- [ ] All NPathComplexity suppressions eliminated or reduced to <=5
- [ ] All ExcessiveMethodLength suppressions eliminated or reduced to <=5
- [ ] ExcessiveClassComplexity reduced by extracting handler classes
- [ ] No new PHPMD violations introduced
- [ ] All existing tests continue to pass
- [ ] No behavioral changes (pure refactoring)
