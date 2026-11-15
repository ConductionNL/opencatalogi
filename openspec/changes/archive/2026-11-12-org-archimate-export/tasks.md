# Tasks: org-archimate-export

> Focus: completing the gaps in the partially-implemented export.
> Implemented: base GEMMA passthrough, ApplicationComponent elements, SpecializationRelationships, view copying, Titel view SWC naming, Bron property definition, connection elements, GET endpoint with `{organizationUuid}` path param.
> Missing: deelnames data layer, typed organization folders, liberal boolean parameter parsing, file name sanitization, frontend toggle checkboxes.

---

## Deduplication Check

- [ ] **DC-1** Search `softwarecatalog/lib/Service/` for existing `parseBool`, `sanitizeFilename`, and deelname query helpers before implementing new ones.
  - Expected result: no overlap found — document findings in PR description.
  - `ViewService::getGebruikData()` step 2 is the canonical deelname query pattern — reuse it, do not duplicate.

---

## 1. Boolean Parameter Parsing (REQ-OAE-013)

### Task 1.1: Add `parseBool()` helper to SettingsController
- **spec_ref**: `specs/org-archimate-export/spec.md#req-oae-013`
- **files**: `softwarecatalog/lib/Controller/SettingsController.php`
- **acceptance_criteria**:
  - GIVEN `?modules=1` WHEN the controller reads the parameter THEN `parseBool('1')` MUST return `true`
  - GIVEN `?deelnames=yes` WHEN the controller reads the parameter THEN `parseBool('yes')` MUST return `true`
  - GIVEN `?gebruik=TRUE` WHEN the controller reads the parameter THEN `parseBool('TRUE')` MUST return `true`
  - GIVEN `?modules=false` WHEN the controller reads the parameter THEN `parseBool('false')` MUST return `false`
  - GIVEN no parameter WHEN the controller reads a missing parameter THEN the supplied default MUST be returned
- **@spec**: `openspec/changes/org-archimate-export/tasks.md#task-1.1`
- [ ] Add private `parseBool(string $value, bool $default = false): bool` using `in_array(strtolower($value), ['1', 'yes', 'true', 'on'], true)`
- [ ] Replace all `=== 'true'` comparisons in `exportOrgArchiMate()` with `parseBool()` calls
- [ ] Default `modules` to `true`, `deelnames` and `gebruik` to `false` when the parameter is absent

---

## 2. File Name Sanitization (REQ-OAE-007)

### Task 2.1: Sanitize organization name in Content-Disposition filename
- **spec_ref**: `specs/org-archimate-export/spec.md#req-oae-007`
- **files**: `softwarecatalog/lib/Controller/SettingsController.php` or `softwarecatalog/lib/Service/ArchiMateService.php`
- **acceptance_criteria**:
  - GIVEN org name "Gemeente 's-Hertogenbosch" WHEN the filename is computed THEN Content-Disposition MUST contain `Gemeente__s-Hertogenbosch`
  - GIVEN org name "Zeist" WHEN the filename is computed THEN Content-Disposition MUST contain `Zeist` (no change)
  - GIVEN any org name WHEN the XML model `<name>` is set THEN it MUST use the original unsanitized name
- **@spec**: `openspec/changes/org-archimate-export/tasks.md#task-2.1`
- [ ] Add sanitization: `preg_replace('/[^A-Za-z0-9._-]/', '_', $orgName)` applied only to the filename, not to the XML model name
- [ ] Verify `exportOrganizationArchiMateXml()` still receives the original `$orgName` for the `<model><name>` element

---

## 3. Deelnames Data Layer — Service Layer (REQ-OAE-011, REQ-OAE-012)

### Task 3.1: Add deelname query to ArchiMateService
- **spec_ref**: `specs/org-archimate-export/spec.md#req-oae-012`
- **files**: `softwarecatalog/lib/Service/ArchiMateService.php`
- **acceptance_criteria**:
  - GIVEN `options['deelnames'] === true` and org UUID "uuid-zeist" WHEN `exportOrgArchiMate()` is called THEN `ObjectService::searchObjects` MUST be called with `_rbac: false`, `_multitenancy: false`, and `'deelnemers' => 'uuid-zeist'`
  - GIVEN `options['deelnames'] === false` WHEN `exportOrgArchiMate()` is called THEN NO deelname query MUST be executed
  - GIVEN the deelname query returns 0 results WHEN `exportOrgArchiMate()` is called THEN `$deelnamesData` MUST be an empty array and no error MUST be thrown
- **@spec**: `openspec/changes/org-archimate-export/tasks.md#task-3.1`
- [ ] Add `array $options = []` parameter to `exportOrgArchiMate()` if not already present
- [ ] Add conditional deelname query block: query gebruik schema in voorzieningen register with `'deelnemers' => $organizationUuid`, `_rbac: false`, `_multitenancy: false`, `_limit: 10000`
- [ ] Pass `$deelnamesData` alongside existing `$gebruikData` and `$modulesData` to `exportOrganizationArchiMateXml()`

### Task 3.2: Process deelnames in ArchiMateExportService
- **spec_ref**: `specs/org-archimate-export/spec.md#req-oae-011`
- **files**: `softwarecatalog/lib/Service/ArchiMateExportService.php`
- **acceptance_criteria**:
  - GIVEN `$deelnamesData` with 5 gebruik objects WHEN the export is assembled THEN the XML MUST contain 5 additional ApplicationComponent elements with `Bron=Softwarecatalogus`
  - GIVEN the same 5 gebruik objects WHEN the export is assembled THEN 5 SpecializationRelationship elements MUST appear linking each deelname app to its referentiecomponent
  - GIVEN a deelname module "Topdesk" with UUID "del-123" WHEN the element is generated THEN its identifier MUST be `id-swc-app-del-123` (distinct from any owned-module identifier)
- **@spec**: `openspec/changes/org-archimate-export/tasks.md#task-3.2`
- [ ] Add `array $deelnamesData = []` and `array $options = []` parameters to `exportOrganizationArchiMateXml()`
- [ ] Run `$deelnamesData` through `buildModuleLookupMaps()` to produce a separate deelnames lookup map
- [ ] Call `generateApplicationElements()` for deelnames lookup map (produces elements tagged as deelnames type)
- [ ] Call `generateSpecializationRelationships()` for deelnames elements
- [ ] Skip modules/gebruik processing when the respective option is `false`

---

## 4. Typed Organization Folders (REQ-OAE-006)

### Task 4.1: Refactor `buildSwcOrganizationFolders()` to produce typed subfolders
- **spec_ref**: `specs/org-archimate-export/spec.md#req-oae-006`
- **files**: `softwarecatalog/lib/Service/ArchiMateExportService.php`
- **acceptance_criteria**:
  - GIVEN modules and deelnames data WHEN folders are built THEN `Gebruikt (Softwarecatalogus)` AND `Deelnames (Softwarecatalogus)` MUST appear as separate subfolders under the org label
  - GIVEN only modules data WHEN folders are built THEN ONLY `Gebruikt (Softwarecatalogus)` MUST appear (no Deelnames folder)
  - GIVEN no data for a type WHEN folders are built THEN that type's folder MUST NOT appear (no empty folders)
  - GIVEN any export WHEN folders are built THEN `Relaties (Softwarecatalogus)` and `Views (Softwarecatalogus)` MUST always be present when relationships/views exist
  - GIVEN all folder items WHEN the XML is validated THEN each `identifierRef` MUST point to an existing element identifier
- **@spec**: `openspec/changes/org-archimate-export/tasks.md#task-4.1`
- [ ] Refactor `buildSwcOrganizationFolders()` to accept typed element arrays: `$gebruiktElements`, `$aangebodentElements`, `$deelnamesElements`, `$relationships`, `$views`
- [ ] Build each subfolder only when its corresponding array is non-empty
- [ ] Folder label mapping:
  - `$gebruiktElements` → `Gebruikt (Softwarecatalogus)`
  - `$aangebodentElements` → `Aangeboden (Softwarecatalogus)`
  - `$deelnamesElements` → `Deelnames (Softwarecatalogus)`
  - `$relationships` → `Relaties (Softwarecatalogus)`
  - `$views` → `Views (Softwarecatalogus)`
- [ ] Remove (or rename) the old flat `Applicaties (Softwarecatalogus)` folder
- [ ] Update `assembleOrganizationXml()` (or equivalent) to pass typed arrays to the refactored method

---

## 5. Frontend — Data Layer Checkboxes and Loading State (REQ-OAE-014)

### Task 5.1: Add data-layer checkboxes to ArchiMateImportExport.vue
- **spec_ref**: `specs/org-archimate-export/spec.md#req-oae-014`
- **files**: `softwarecatalog/src/views/settings/sections/ArchiMateImportExport.vue`
- **acceptance_criteria**:
  - GIVEN the settings page loads THEN "Modules" checkbox MUST be checked, "Deelnames" and "Gebruik" MUST be unchecked
  - GIVEN the user toggles "Deelnames" WHEN the export is triggered THEN `?deelnames=true` MUST appear in the request URL
  - GIVEN "Gebruik" is unchecked WHEN the export is triggered THEN `gebruik` parameter MUST NOT appear (or be `false`) in the URL
  - ALL user-visible labels MUST be wrapped in `t('softwarecatalog', '...')` — no hardcoded strings
- **@spec**: `openspec/changes/org-archimate-export/tasks.md#task-5.1`
- [ ] Add `includeModules: true`, `includeDeelnames: false`, `includeGebruik: false` to component `data()`
- [ ] Add three `NcCheckboxRadioSwitch` (or `NcCheckbox`) controls for the three flags, bound to data properties
- [ ] Add i18n keys: "Modules", "Deelnames", "Gebruik" via `l10n-ai.js add` (both `en` and `nl` values required — run `node scripts/l10n-ai.js list-locales` first)

### Task 5.2: Build query string from checkboxes and add loading state
- **spec_ref**: `specs/org-archimate-export/spec.md#req-oae-014`
- **files**: `softwarecatalog/src/views/settings/sections/ArchiMateImportExport.vue`
- **acceptance_criteria**:
  - GIVEN modules=true AND deelnames=true WHEN export is triggered THEN fetch URL MUST be `GET /api/archimate/export/organization/{uuid}?modules=true&deelnames=true`
  - GIVEN the download is in progress WHEN the button is inspected THEN it MUST have a loading/disabled state
  - GIVEN the download completes or fails THEN the button MUST return to its normal state (use `try/finally`)
  - GIVEN no organization is selected THEN the export button MUST be disabled
- **@spec**: `openspec/changes/org-archimate-export/tasks.md#task-5.2`
- [ ] Add `exportLoading: false` to `data()`
- [ ] Update `exportOrgArchiMateFile()` (or equivalent) to build `URLSearchParams` from checkbox states and use GET instead of POST
- [ ] Wrap the fetch in `try/finally` to toggle `exportLoading`
- [ ] Bind `:disabled="exportLoading || !selectedOrganization"` on the export button

---

## 6. i18n — New Strings (ADR-007)

### Task 6.1: Register new translation keys for data-layer labels
- **spec_ref**: `specs/org-archimate-export/spec.md#req-oae-014`
- **files**: `l10n/en.js`, `l10n/nl.js` (via `l10n-ai.js`)
- **acceptance_criteria**:
  - GIVEN `npm run check:l10n` is run THEN zero MISSING keys MUST be reported for new strings added in Task 5.1
- **@spec**: `openspec/changes/org-archimate-export/tasks.md#task-6.1`
- [ ] Run `node scripts/l10n-ai.js list-locales` to confirm available locales
- [ ] Run `node scripts/l10n-ai.js add "Modules" --value en="Modules" --value nl="Modules"`
- [ ] Run `node scripts/l10n-ai.js add "Deelnames" --value en="Participations" --value nl="Deelnames"`
- [ ] Run `node scripts/l10n-ai.js add "Gebruik" --value en="Usage" --value nl="Gebruik"`
- [ ] Run `npm run check:l10n` to confirm zero MISSING

---

## 7. Testing and Verification

### Task 7.1: Verify boolean parameter parsing
- **spec_ref**: `specs/org-archimate-export/spec.md#req-oae-013-d`
- **files**: manual/API testing
- **acceptance_criteria**:
  - GIVEN `?modules=1&deelnames=yes&gebruik=TRUE` WHEN the endpoint is called THEN all three data layers MUST appear in the XML
- [ ] Call `GET /api/archimate/export/organization/{uuid}?modules=1&deelnames=yes&gebruik=TRUE` and verify response contains typed folders for all three layers
- [ ] Call without params and verify default (`modules=true`) behavior is preserved

### Task 7.2: Verify file name sanitization
- **spec_ref**: `specs/org-archimate-export/spec.md#req-oae-007-c`
- **files**: manual/API testing
- **acceptance_criteria**:
  - GIVEN org "Gemeente 's-Hertogenbosch" WHEN export is requested THEN Content-Disposition filename MUST not contain `'` or spaces
  - GIVEN org "Zeist" WHEN export is requested THEN model `<name>` MUST be "Softwarecatalogus Zeist"
- [ ] Check Content-Disposition header for special-character org name
- [ ] Confirm `<model><name>` contains the original org name with `'` preserved

### Task 7.3: Verify deelnames export for organization with deelname gebruik
- **spec_ref**: `specs/org-archimate-export/spec.md#req-oae-011`
- **files**: manual/API testing
- **acceptance_criteria**:
  - GIVEN org "Zeist" with deelname gebruik WHEN export is requested with `?deelnames=true` THEN `Deelnames (Softwarecatalogus)` folder MUST appear in the XML
  - GIVEN `?deelnames=false` THEN the folder MUST NOT appear
- [ ] Call export with `?deelnames=true`, inspect XML for `<label>Deelnames (Softwarecatalogus)</label>` folder item
- [ ] Confirm deelname `ApplicationComponent` elements carry `Bron=Softwarecatalogus` property
- [ ] Confirm SpecializationRelationship elements link deelname apps to referentiecomponenten

### Task 7.4: Verify typed folder structure
- **spec_ref**: `specs/org-archimate-export/spec.md#req-oae-006`
- **files**: manual/API testing
- **acceptance_criteria**:
  - GIVEN org with modules ONLY WHEN export is generated THEN only `Gebruikt (Softwarecatalogus)`, `Relaties (Softwarecatalogus)`, and `Views (Softwarecatalogus)` MUST appear
  - GIVEN empty data for a type THEN that folder MUST NOT appear
- [ ] Inspect `<organizations>` section of output XML for correct typed subfolder presence/absence
- [ ] Confirm no orphaned `identifierRef` items (all refs resolve to real elements)

### Task 7.5: Verify frontend checkboxes and download flow
- **spec_ref**: `specs/org-archimate-export/spec.md#req-oae-014`
- **files**: browser testing
- **acceptance_criteria**:
  - GIVEN the settings page WHEN loaded THEN Modules is checked, Deelnames and Gebruik are unchecked
  - GIVEN Deelnames is checked and export is clicked THEN Network panel shows GET request with `?deelnames=true`
  - GIVEN download is in progress THEN button MUST show loading state
- [ ] Open ArchiMate settings, verify checkbox defaults
- [ ] Check "Deelnames", click export, inspect Network request URL
- [ ] Verify file downloads with correct filename (date + sanitized org name)
- [ ] Verify loading state appears on button during download

### Task 7.6: Import into Archi
- **spec_ref**: `specs/org-archimate-export/spec.md#req-oae-001-a`
- **files**: manual testing with Archi tool
- **acceptance_criteria**:
  - GIVEN the exported XML WHEN imported into Archi THEN no import errors MUST appear
  - AND application nodes MUST be visible inside referentiecomponent nodes in the copied views
- [ ] Open Archi → Import → Import Model from File → select the exported XML
- [ ] Verify no error dialog appears
- [ ] Navigate to a copied view (name contains org name) and confirm application nodes are nested inside referentiecomponent nodes

---

## 8. @spec Annotations (ADR-003)

### Task 8.1: Add @spec PHPDoc tags to all modified backend classes
- **spec_ref**: `specs/org-archimate-export/spec.md` (all requirements)
- **files**: `softwarecatalog/lib/Controller/SettingsController.php`, `softwarecatalog/lib/Service/ArchiMateService.php`, `softwarecatalog/lib/Service/ArchiMateExportService.php`
- **acceptance_criteria**:
  - Every modified public method MUST have `@spec openspec/changes/org-archimate-export/tasks.md#task-N` in its PHPDoc
- [ ] Add `@spec` tags to `exportOrgArchiMate()` in SettingsController
- [ ] Add `@spec` tags to `exportOrgArchiMate()` in ArchiMateService
- [ ] Add `@spec` tags to `exportOrganizationArchiMateXml()`, `buildSwcOrganizationFolders()` in ArchiMateExportService

---

## Verification Checklist

- [ ] `parseBool()` accepts `1`, `yes`, `true`, `TRUE`; rejects `false`, `0`, `no`
- [ ] File name sanitizes special characters; XML model name is unchanged
- [ ] Deelname query uses `_rbac: false` and `_multitenancy: false`
- [ ] Typed folders appear only when data exists for that type
- [ ] Frontend checkboxes default to Modules=on, Deelnames=off, Gebruik=off
- [ ] Export button shows loading state; GET URL includes correct query params
- [ ] `npm run check:l10n` reports zero MISSING keys
- [ ] All modified public PHP methods carry `@spec` tags
- [ ] Exported XML imports into Archi without errors
- [ ] Base (non-organization) export is unaffected
