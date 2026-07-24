---
kind: code
depends_on: []
---

# Proposal: admin-settings

## Summary

Implement the admin settings module for OpenCatalogi: the Nextcloud admin settings page, configuration API, schema/register mapping, auto-configuration, version tracking, publishing options, and the `InitializeSettings` repair step that bootstraps the app on first install or upgrade.

## Motivation

OpenCatalogi stores all content as OpenRegister objects. Every OpenRegister object type (catalog, listing, organization, theme, page, menu, glossary) must be mapped to the correct register and schema ID before the app can read or write data. Without a reliable configuration layer:

- The app cannot resolve which OpenRegister register/schema to use per object type.
- Auto-configuration on fresh installs is impossible — admins must manually set 21 config keys (`{type}_source`, `{type}_schema`, `{type}_register` × 7 types).
- There is no version-based upgrade detection, so stale configurations persist across app upgrades.
- Publishing options (`auto_publish_attachments`, `auto_publish_objects`, `use_old_style_publishing_view`) have no API surface and cannot be toggled without direct database access.

The admin settings module is the single source of truth for every piece of configuration that drives OpenCatalogi's runtime behaviour.

## Affected Projects

- [x] Project: `opencatalogi` — New SettingsController, SettingsService, OpenCatalogiAdmin panel, Settings.vue, InitializeSettings repair step, 4 database migrations

## Scope

### In Scope

**Configuration API (SET-001, SET-002)**
- `GET /api/settings` — returns current configuration including object type mappings, available registers (enriched with full schema objects), and publishing options
- `POST /api/settings` — updates schema/register mappings for all 7 object types (admin-only)

**Configuration Import (SET-003, SET-007)**
- `GET /api/settings/load` — reads `lib/Settings/publication_register.json`, injects `x-openregister.sourceUrl` and `sourceType` if absent, calls `ConfigurationService::importFromApp()`, then maps imported schema slugs to IDs in `IAppConfig`
- `POST /api/settings/import` — manually triggers configuration import with optional `force: true` parameter, bypasses version-compare guard when forced

**Auto-configuration (SET-004)**
- `autoConfigure()` — searches OpenRegister registers for one whose slug contains "publication"; for each of the 7 object types finds a matching schema by title; populates all `{type}_register` and `{type}_schema` config keys automatically

**OpenRegister Dependency Check (SET-005)**
- On settings load, check whether OpenRegister is installed and meets minimum version 0.1.7
- Include `openRegisters: true/false` in GET `/api/settings` response so the frontend can show an empty state when OR is missing

**Version Tracking (SET-006, SET-010)**
- `GET /api/settings/version` — returns app version, stored config version, and a boolean `match` flag
- `shouldLoadSettings()` — uses `version_compare` to decide whether an import is needed; returns true when app version exceeds stored config version

**Publishing Options (SET-008, SET-009)**
- `GET /api/settings/publishing` — returns the three publishing flags as booleans
- `POST /api/settings/publishing` — updates any combination of the three flags (admin-only)
- Default values: `auto_publish_attachments=false`, `auto_publish_objects=false`, `use_old_style_publishing_view=false`

**Repair Step (SET-011)**
- `InitializeSettings` implements `IRepairStep`; runs on app install and upgrade
- Checks if OpenRegister is installed; if yes, calls `SettingsService::loadSettings(force: false)`
- Reports number of registers, schemas, and objects imported; logs a warning and skips if OpenRegister is absent

**Nextcloud Admin Settings Page (SET-012)**
- `OpenCatalogiAdmin` implements `ISettings`; registered as admin settings panel in `info.xml`
- Renders `settings/admin` template with the settings.js entry point
- `AdminRoot.vue` loaded via `settings.js` — NOT added to the vue-router (per ADR-004)

**Register Enrichment (SET-013)**
- `GET /api/settings` enriches each entry in `availableRegisters` with full schema objects (not just schema IDs); the frontend register-mapping component requires the full schema list to render its dropdowns

**Database Migrations (SET-014)**
- 4 migration files under `lib/Migration/`:
  - `Version6Date20241011085015` (2024-10-11) — initial migration
  - `Version6Date20241129151236` (2024-11-29) — second migration
  - `Version6Date20241208222530` (2024-12-08) — third migration
  - `Version6Date20250419123213` (2025-04-19) — fourth migration
- All follow Nextcloud's `Version{majorVersion}Date{YYYYMMDDHHMMSS}` naming pattern
- Executed automatically by Nextcloud's migration system on install/upgrade

**Frontend Admin Settings Page**
- `Settings.vue` at `/views/settings/` — displayed in the Nextcloud admin settings section
- `AdminRoot.vue` as the settings.js entry root component
- Uses `CnVersionInfoCard` (first on page, per ADR-004), `CnRegisterMapping`, and `CnSettingsSection` components
- Loads via `GET /api/settings`; saves via `POST /api/settings`

### Out of Scope

- Changes to `publication_register.json` content (managed separately)
- UI for creating or editing OpenRegister schemas/registers (provided by OpenRegister itself)
- Per-user settings (this module is admin-only configuration)
- Deelnames/gebruik object type configuration (those object types are managed by separate changes)

## Approach

1. **SettingsController** — thin controller with 7 methods; delegates all logic to `SettingsService`. Methods annotated `#[AuthorizedAdminSetting]` for write endpoints and unannotated (admin default) for read endpoints where admin auth is also correct; `GET /api/settings` and `GET /api/settings/publishing` and `GET /api/settings/version` carry `#[NoAdminRequired]` since they are read-only informational endpoints any authenticated user may call.
2. **SettingsService** — stateless service handling: config read/write via `IAppConfig`, register discovery via `RegisterMapper::findAll()`, schema enrichment via `SchemaMapper::find()`, version comparison, auto-configuration by slug matching, and import orchestration via OpenRegister's `ConfigurationService`.
3. **InitializeSettings** — repair step wired in `AppInfo/Application.php` via `$context->registerRepairStep(InitializeSettings::class)`.
4. **OpenCatalogiAdmin** — implements `ISettings`; uses `IInitialState` to inject the app ID for the settings.js bundle.
5. **Settings.vue / AdminRoot.vue** — Vue 2 Options API components. `AdminRoot.vue` is the entry root for `settings.js`; it is not a router view. `Settings.vue` is the main body component using `CnVersionInfoCard`, `CnRegisterMapping` for the 7 type mappings, and `CnSettingsSection` for publishing toggles.

## Cross-Project Dependencies

- **OpenRegister `ConfigurationService`** — `importFromApp()` and `getConfiguredAppVersion()` are the primary integration points
- **OpenRegister `RegisterMapper`** — `findAll()` for listing available registers
- **OpenRegister `SchemaMapper`** — `find()` for enriching each register with full schema objects
- **Nextcloud `IAppConfig`** — all 21 configuration keys plus 3 publishing option keys and the stored config version key
- **Nextcloud `IAppManager`** — app version retrieval and install/enable operations

## Rollback Strategy

- All configuration keys live in `IAppConfig`; removing them is a single `deleteAppValue()` call per key — no data loss
- Migrations are additive; any new database tables can be dropped without affecting core content (which lives in OpenRegister)
- Repair step is idempotent; re-running is safe
- Frontend components are isolated to the admin settings page — no impact on catalog browsing or publication workflows

## Open Questions

- Should `GET /api/settings` be `#[NoAdminRequired]` or admin-only? The current implementation is authenticated (non-admin). The `openRegisters` boolean and `availableRegisters` list are needed by the frontend to show the correct empty state — making this admin-only would break the empty state for non-admin users who land on the app before configuration. Keep `#[NoAdminRequired]` with read-only data only.
- The minimum OpenRegister version check (0.1.7) — should this be a hard block or a warning? Current implementation is a warning; admin can still use the settings page with an older OR version at their own risk.
