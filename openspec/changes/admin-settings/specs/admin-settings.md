# Spec: admin-settings

## Overview

This spec defines the requirements for the OpenCatalogi admin settings module. The module maps the 7 OpenCatalogi object types (catalog, listing, organization, theme, page, menu, glossary) to their corresponding OpenRegister schemas and registers, manages configuration import and auto-configuration, tracks version state, exposes publishing options, and provides the Nextcloud admin settings page.

All requirements use the format `REQ-SET-NNN`. Scenarios use GIVEN/WHEN/THEN.

---

## REQ-SET-001: Retrieve current settings

**Priority:** Must  
**Status:** Implemented

The system MUST expose a read endpoint that returns the current object type configuration together with the list of available OpenRegister registers and their schemas.

**Scenario 1.1 — Full settings response when OpenRegister is available**

GIVEN OpenRegister is installed and at least one register exists  
WHEN a GET request is made to `/api/settings` by an authenticated user  
THEN the response status is 200  
AND the body includes `objectTypes` array with all 7 types: `["catalog", "listing", "organization", "theme", "page", "menu", "glossary"]`  
AND the body includes `openRegisters: true`  
AND the body includes `availableRegisters` as an array where each entry has `id`, `title`, `slug`, and a `schemas` array of full schema objects  
AND the body includes `configuration` with keys `{type}_source`, `{type}_schema`, `{type}_register` for each of the 7 types, plus the 3 publishing option keys

**Scenario 1.2 — Response when OpenRegister is not installed**

GIVEN OpenRegister is not installed or not enabled  
WHEN a GET request is made to `/api/settings`  
THEN the response status is 200  
AND `openRegisters` is `false`  
AND `availableRegisters` is an empty array  
AND `configuration` still returns any previously stored config key values

**Scenario 1.3 — Endpoint accessible to non-admin authenticated users**

GIVEN a user who is authenticated but NOT an admin  
WHEN a GET request is made to `/api/settings`  
THEN the response status is 200 (not 403)

---

## REQ-SET-002: Update settings via POST

**Priority:** Must  
**Status:** Implemented

The system MUST provide an admin-only endpoint to update schema/register mappings for any combination of the 7 object types.

**Scenario 2.1 — Successful settings update**

GIVEN an admin user  
AND a valid POST body with one or more `{type}_schema` and `{type}_register` keys  
WHEN a POST request is made to `/api/settings`  
THEN the response status is 200  
AND the response body reflects the updated configuration  
AND the updated values are persisted to `IAppConfig`

**Scenario 2.2 — Non-admin update is rejected**

GIVEN a user who is authenticated but NOT an admin  
WHEN a POST request is made to `/api/settings`  
THEN the response status is 403  
AND no configuration values are changed

**Scenario 2.3 — Partial update preserves existing values**

GIVEN an existing configuration with all 21 object type keys set  
WHEN a POST request is made with only `catalog_schema` and `catalog_register`  
THEN only those two keys are updated  
AND all other keys retain their previous values

---

## REQ-SET-003: Load configuration from publication_register.json

**Priority:** Must  
**Status:** Implemented

The system MUST import schema and register definitions from `lib/Settings/publication_register.json` using OpenRegister's `ConfigurationService::importFromApp()`.

**Scenario 3.1 — Successful load from JSON file**

GIVEN `lib/Settings/publication_register.json` exists and is valid JSON  
AND OpenRegister is installed  
WHEN a GET request is made to `/api/settings/load` by an admin  
THEN `x-openregister.sourceUrl` and `sourceType` are injected into the JSON if not already present  
AND `ConfigurationService::importFromApp()` is called with the parsed JSON  
AND `updateObjectTypeConfiguration()` is called to map imported schema slugs to IDs in `IAppConfig`  
AND the response reports the number of registers, schemas, and objects imported

**Scenario 3.2 — Slug-to-ID mapping after import**

GIVEN a successful import that creates schemas with known slugs (`catalog`, `listing`, etc.)  
WHEN `updateObjectTypeConfiguration()` runs  
THEN for each of the 7 object types whose schema slug matches an imported schema  
AND the `{type}_schema` config key is set to the new schema's ID  
AND the `{type}_register` config key is set to the corresponding register's ID

---

## REQ-SET-004: Auto-configure registers and schemas by slug matching

**Priority:** Should  
**Status:** Implemented

The system SHOULD provide an `autoConfigure()` function that discovers the publication register and matches schemas by title without requiring manual mapping.

**Scenario 4.1 — Auto-configuration finds publication register**

GIVEN OpenRegister has one or more registers installed  
AND at least one register's slug contains the substring `"publication"`  
WHEN `autoConfigure()` is called  
THEN that register is selected as the publication register  
AND for each of the 7 object types, a schema whose title matches the type name is located  
AND `{type}_register` and `{type}_schema` config keys are populated with the matched IDs

**Scenario 4.2 — Auto-configuration skips types with no matching schema**

GIVEN no schema exists whose title matches a particular object type  
WHEN `autoConfigure()` is called  
THEN that object type's config keys are left unchanged  
AND the other types that did match are still configured correctly

---

## REQ-SET-005: Check and install OpenRegister dependency

**Priority:** Should  
**Status:** Implemented

The system SHOULD verify that OpenRegister is installed and meets the minimum version requirement (0.1.7).

**Scenario 5.1 — OpenRegister present at minimum version**

GIVEN OpenRegister version 0.1.7 or higher is installed  
WHEN `GET /api/settings` is called  
THEN `openRegisters: true` is returned  
AND `availableRegisters` is populated

**Scenario 5.2 — OpenRegister absent**

GIVEN OpenRegister is not installed  
WHEN `GET /api/settings` is called  
THEN `openRegisters: false` is returned  
AND `availableRegisters` is an empty array  
AND no error is thrown

**Scenario 5.3 — OpenRegister below minimum version**

GIVEN OpenRegister version below 0.1.7 is installed  
WHEN `GET /api/settings` is called  
THEN `openRegisters: false` is returned (treated as unavailable)  
AND an admin warning is logged server-side

---

## REQ-SET-006: Track configuration version

**Priority:** Must  
**Status:** Implemented

The system MUST persist the app version at the time of a successful import and expose a comparison endpoint so the admin UI can detect when a re-import is needed.

**Scenario 6.1 — Version stored after successful import**

GIVEN a successful call to `loadSettings()`  
WHEN the import completes without error  
THEN the current app version is stored as `configuredVersion` in `IAppConfig`

**Scenario 6.2 — Version comparison detects upgrade**

GIVEN app version is `"0.7.9"` and stored `configuredVersion` is `"0.7.8"`  
WHEN `shouldLoadSettings()` is called  
THEN `version_compare("0.7.9", "0.7.8", ">")` returns `true`  
AND the function returns `true` (import needed)

**Scenario 6.3 — Version comparison on identical versions**

GIVEN app version equals stored `configuredVersion`  
WHEN `shouldLoadSettings()` is called  
THEN the function returns `false` (no import needed)

---

## REQ-SET-007: Manual import trigger with optional force parameter

**Priority:** Must  
**Status:** Implemented

The system MUST expose an admin-only endpoint to manually trigger configuration import, with a `force` flag that bypasses the version-compare guard.

**Scenario 7.1 — Manual import when versions match without force**

GIVEN app version equals stored `configuredVersion`  
WHEN a POST to `/api/settings/import` is made with `{"force": false}` (or force omitted)  
THEN `shouldLoadSettings()` returns `false`  
AND the import is skipped  
AND the response indicates the import was not needed

**Scenario 7.2 — Manual import with force=true**

GIVEN app version equals stored `configuredVersion`  
WHEN a POST to `/api/settings/import` is made with `{"force": true}`  
THEN the import proceeds regardless of version match  
AND the response includes `success: true`, import counts, and updated version info

**Scenario 7.3 — Manual import requires admin**

GIVEN a non-admin authenticated user  
WHEN a POST to `/api/settings/import` is made  
THEN the response status is 403

---

## REQ-SET-008: Publishing options

**Priority:** Should  
**Status:** Implemented

The system SHOULD expose three boolean publishing flags: `auto_publish_attachments`, `auto_publish_objects`, and `use_old_style_publishing_view`. All default to `false`.

**Scenario 8.1 — Default publishing options**

GIVEN a fresh installation with no previously stored publishing options  
WHEN `GET /api/settings/publishing` is called  
THEN the response is `{"auto_publish_attachments": false, "auto_publish_objects": false, "use_old_style_publishing_view": false}`

**Scenario 8.2 — Publishing options included in main settings response**

GIVEN publishing options are configured  
WHEN `GET /api/settings` is called  
THEN `configuration` includes all three publishing keys with their current values

---

## REQ-SET-009: Get and update publishing options separately

**Priority:** Should  
**Status:** Implemented

The system SHOULD provide dedicated endpoints for reading and updating publishing options independently of the main settings.

**Scenario 9.1 — Successful publishing options update**

GIVEN an admin user  
WHEN a POST to `/api/settings/publishing` is made with `{"auto_publish_attachments": true}`  
THEN the response status is 200  
AND `auto_publish_attachments` is now `"true"` in `IAppConfig`  
AND the other two flags retain their previous values

**Scenario 9.2 — Non-admin update rejected**

GIVEN a non-admin authenticated user  
WHEN a POST to `/api/settings/publishing` is made  
THEN the response status is 403

**Scenario 9.3 — Publishing options readable by non-admin**

GIVEN a non-admin authenticated user  
WHEN a GET to `/api/settings/publishing` is made  
THEN the response status is 200  
AND the current flags are returned

---

## REQ-SET-010: Version info endpoint

**Priority:** Must  
**Status:** Implemented

The system MUST expose a version info endpoint showing the current app version, the stored configured version, and whether they match.

**Scenario 10.1 — Version info when import is up to date**

GIVEN app version `"0.7.9"` and `configuredVersion` = `"0.7.9"`  
WHEN `GET /api/settings/version` is called  
THEN the response is `{"appVersion": "0.7.9", "configuredVersion": "0.7.9", "match": true}`

**Scenario 10.2 — Version info when import is needed**

GIVEN app version `"0.7.9"` and `configuredVersion` = `"0.7.8"`  
WHEN `GET /api/settings/version` is called  
THEN `match` is `false`  
AND the admin UI can display a "Re-import recommended" notice

---

## REQ-SET-011: Repair step for install/upgrade

**Priority:** Must  
**Status:** Implemented

The system MUST execute the `InitializeSettings` repair step on app install and upgrade to bootstrap the configuration without manual admin intervention.

**Scenario 11.1 — Repair step when OpenRegister is available**

GIVEN the app is installed or upgraded  
AND OpenRegister is installed  
WHEN the Nextcloud repair pipeline runs `InitializeSettings`  
THEN `SettingsService::loadSettings(force: false)` is called  
AND the repair step output reports the number of registers, schemas, and objects imported

**Scenario 11.2 — Repair step when OpenRegister is absent**

GIVEN the app is installed or upgraded  
AND OpenRegister is NOT installed  
WHEN the Nextcloud repair pipeline runs `InitializeSettings`  
THEN a warning is logged indicating OpenRegister is missing  
AND the repair step exits cleanly without throwing an exception

**Scenario 11.3 — Repair step is idempotent**

GIVEN the app is already configured with the current version  
WHEN `InitializeSettings` runs again (e.g., on a second repair pass)  
THEN `shouldLoadSettings()` returns `false`  
AND no duplicate import is performed

---

## REQ-SET-012: Nextcloud admin settings page

**Priority:** Must  
**Status:** Implemented

The system MUST provide a Nextcloud admin settings panel, accessible from `/settings/admin/{appid}`, rendered by `AdminRoot.vue` loaded via the `settings.js` webpack entry point.

**Scenario 12.1 — Admin settings page renders for admin user**

GIVEN an admin user  
WHEN they navigate to the Nextcloud admin settings and open the OpenCatalogi section  
THEN the settings page renders without errors  
AND `CnVersionInfoCard` is the first element  
AND the register/schema mapping UI for all 7 object types is visible  
AND the publishing options toggles are visible

**Scenario 12.2 — Admin settings page is NOT accessible as a frontend route**

GIVEN `AdminRoot.vue` is the `settings.js` entry root  
WHEN the vue-router is initialized  
THEN `AdminRoot.vue` has no route entry in `src/router/`  
AND navigating to any vue-router URL does not render `AdminRoot.vue`

**Scenario 12.3 — Settings page loads configuration on mount**

GIVEN the admin settings page is opened  
WHEN `Settings.vue` is mounted  
THEN it calls `GET /api/settings` via `@nextcloud/axios`  
AND populates the register/schema dropdowns with the returned `availableRegisters`  
AND displays the current mapping for each of the 7 object types

---

## REQ-SET-013: Enrich register listings with full schema objects

**Priority:** Should  
**Status:** Implemented

The system SHOULD enrich each entry in `availableRegisters` with the full schema objects (not just schema IDs) so the frontend mapping component can render schema dropdowns without a second API call.

**Scenario 13.1 — Schema enrichment on GET /api/settings**

GIVEN a register with ID `"1"` that owns schemas with IDs `"10"` and `"11"`  
WHEN `GET /api/settings` is called  
THEN `availableRegisters[0].schemas` contains two entries  
AND each entry has at minimum `id`, `title`, and `slug` fields (full schema objects from `SchemaMapper::find()`)

**Scenario 13.2 — Empty schemas array for register with no schemas**

GIVEN a register that has no schemas  
WHEN `GET /api/settings` is called  
THEN that register's `schemas` key is an empty array (not absent, not null)

---

## REQ-SET-014: Database migration history

**Priority:** Must  
**Status:** Implemented

The system MUST maintain all four database migrations in `lib/Migration/`. These are executed automatically by Nextcloud's migration system during app install or upgrade and MUST NOT be modified after they have been released.

**Scenario 14.1 — Migrations run in order on fresh install**

GIVEN a fresh Nextcloud installation with no prior OpenCatalogi data  
WHEN OpenCatalogi is installed  
THEN Nextcloud's migration system runs all 4 migrations in date order  
AND the resulting database state is consistent with the current app version

**Scenario 14.2 — Migrations are idempotent on upgrade**

GIVEN OpenCatalogi is already installed and migrations 1–3 have been applied  
WHEN the app is upgraded to a version that includes migration 4  
THEN only migration 4 runs  
AND migrations 1–3 are skipped (already applied)

**Scenario 14.3 — No existing migration files are modified**

GIVEN any of the 4 migration classes  
WHEN a code review is performed  
THEN none of the existing migration files have been modified after their initial release  
AND any schema changes in future versions introduce NEW migration files

---

## Non-Functional Requirements

### Security

- All write endpoints (`POST /api/settings`, `GET /api/settings/load`, `POST /api/settings/publishing`, `POST /api/settings/import`) MUST use `#[AuthorizedAdminSetting(Application::APP_ID)]`
- Read-only endpoints (`GET /api/settings`, `GET /api/settings/publishing`, `GET /api/settings/version`) carry `#[NoAdminRequired]` — they return no sensitive data
- Error responses MUST use static generic messages; `$e->getMessage()` MUST NOT be returned to clients
- All exceptions MUST be logged server-side with `$this->logger->error('Context', ['exception' => $e])`

### Translations

- All user-visible strings in `Settings.vue` and `AdminRoot.vue` MUST use `t('opencatalogi', '...')` (ADR-007)
- Both `l10n/en.js` and `l10n/nl.js` MUST contain all keys used in the settings UI
- Translation keys MUST be in sentence case and English (ADR-007)

### Frontend Patterns

- `AdminRoot.vue` MUST NOT be registered in the vue-router (ADR-004)
- All `await store.action()` calls MUST be wrapped in `try/catch` with user-facing error feedback (ADR-004)
- All components used in templates MUST be imported AND registered in `components: {}` (ADR-004)
- Labels for all `NcSelect` elements MUST use the `inputLabel` prop — no manual `<label>` elements (ADR-004)
- Import from `@conduction/nextcloud-vue`, never directly from `@nextcloud/vue` (ADR-004)
