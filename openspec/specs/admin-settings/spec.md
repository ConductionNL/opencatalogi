---
status: reviewed
---

# Admin Settings

## Purpose

The admin settings module provides the configuration interface for OpenCatalogi. It handles the mapping between OpenCatalogi's content types (catalog, listing, organization, theme, page, menu, glossary) and their corresponding OpenRegister schemas and registers. It also manages the initial configuration import from `publication_register.json`, auto-configuration, version tracking, publishing options, and the Nextcloud admin settings page.

## Requirements

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| SET-001 | Retrieve current settings including object type configurations and available registers | Must | Implemented |
| SET-002 | Update settings (schema/register mappings) via POST | Must | Implemented |
| SET-003 | Load/import configuration from `publication_register.json` via OpenRegister's ConfigurationService | Must | Implemented |
| SET-004 | Auto-configure registers and schemas by matching slugs | Should | Implemented |
| SET-005 | Check and install/update OpenRegister dependency (minimum version 0.1.7) | Should | Implemented |
| SET-006 | Track configuration version and compare with app version for upgrade detection | Must | Implemented |
| SET-007 | Manual import trigger with optional force parameter | Must | Implemented |
| SET-008 | Publish options: auto_publish_attachments, auto_publish_objects, use_old_style_publishing_view | Should | Implemented |
| SET-009 | Get and update publishing options separately | Should | Implemented |
| SET-010 | Version info endpoint showing app version, configured version, and match status | Must | Implemented |
| SET-011 | Repair step to initialize settings on app install/upgrade | Must | Implemented |
| SET-012 | Nextcloud admin settings page with template rendering | Must | Implemented |
| SET-013 | Enrich register listings with full schema objects (not just IDs) | Should | Implemented |
| SET-014 | Database migration history tracked across 4 migration files | Must | Implemented |

## Data Model

### Configuration Keys (stored in IAppConfig)

For each object type (catalog, listing, organization, theme, page, menu, glossary):

| Key Pattern | Type | Description |
|-------------|------|-------------|
| `{type}_source` | string | Always "openregister" |
| `{type}_schema` | string | OpenRegister schema ID for this object type |
| `{type}_register` | string | OpenRegister register ID for this object type |

### Publishing Options

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| auto_publish_attachments | string (bool) | "false" | Auto-publish file attachments (see [auto-publishing spec](../auto-publishing/spec.md)) |
| auto_publish_objects | string (bool) | "false" | Auto-publish new objects (see [auto-publishing spec](../auto-publishing/spec.md)) |
| use_old_style_publishing_view | string (bool) | "false" | Use legacy publishing view |

### Object Types

The app manages 7 object types:
- catalog
- listing
- organization
- theme
- page
- menu
- glossary

## Database Migration History (Gap 13)

OpenCatalogi has 4 database migration files that track schema evolution:

| Migration | Date | Description |
|-----------|------|-------------|
| `Version6Date20241011085015` | 2024-10-11 | Initial migration |
| `Version6Date20241129151236` | 2024-11-29 | Second migration |
| `Version6Date20241208222530` | 2024-12-08 | Third migration |
| `Version6Date20250419123213` | 2025-04-19 | Fourth migration |

All migrations follow Nextcloud's versioned migration pattern (`Version{majorVersion}Date{YYYYMMDDHHMMSS}`). They are located in `lib/Migration/` and are executed automatically by Nextcloud's migration system during app install/upgrade.

Note: OpenCatalogi primarily stores data as OpenRegister objects (not in its own database tables), so these migrations may handle ancillary data structures, caching tables, or configuration storage rather than core content tables.

## Application Bootstrap Event Registrations (Gap 21)

The `Application` class (`lib/AppInfo/Application.php`) registers all event listeners and widgets during the `register()` phase. For full details, see the [dashboard spec](../dashboard/spec.md) section on "Application.php Bootstrap (Gap 21)".

Summary of registrations:
- **Vendor autoload**: Loads Composer dependencies
- **Dashboard widgets**: CatalogWidget, UnpublishedPublicationsWidget, UnpublishedAttachmentsWidget
- **Event listeners**: ObjectCreatedEvent, ObjectUpdatedEvent, ObjectDeletedEvent handlers for auto-publishing and cache management
- **Tool registration**: ToolRegistrationEvent listener for AI agent CMS tool

The `boot()` method is intentionally empty -- initialization is handled by the `InitializeSettings` repair step.

## User Interface

- **Settings.vue** (`/views/settings/`) - Admin settings page within the Nextcloud app
- **OpenCatalogiAdmin.php** - Nextcloud admin settings panel (renders `settings/admin` template)
- **OpenCatalogiAdmin section** - Registered in `info.xml` as admin settings section

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/settings` | Get current settings (authenticated) |
| POST | `/api/settings` | Update settings (admin only) |
| GET | `/api/settings/load` | Load settings from publication_register.json (admin only) |
| GET | `/api/settings/publishing` | Get publishing options (authenticated) |
| POST | `/api/settings/publishing` | Update publishing options (admin only) |
| GET | `/api/settings/version` | Get version info (authenticated) |
| POST | `/api/settings/import` | Manually trigger configuration import (admin only) |

## Scenarios

### Scenario: Get current settings
- GIVEN OpenRegister is installed and the app is configured
- WHEN a GET request is made to `/api/settings`
- THEN the response includes:
  - `objectTypes`: ["catalog", "listing", "organization", "theme", "page", "menu", "glossary"]
  - `openRegisters`: true (if OpenRegister is available)
  - `availableRegisters`: Array of registers with enriched schema data (full schema objects, not just IDs)
  - `configuration`: Object with `{type}_source`, `{type}_schema`, `{type}_register` for each type, plus publishing options

### Scenario: Load settings from JSON
- GIVEN `publication_register.json` exists at `lib/Settings/publication_register.json`
- WHEN the load endpoint is called
- THEN the JSON is read and parsed
- AND `x-openregister.sourceUrl` and `sourceType` are injected if not present
- AND ConfigurationService.importFromApp() is called with the data
- AND updateObjectTypeConfiguration() maps imported schema slugs to IDs in IAppConfig
- AND the publication register's schemas are matched to config keys by slug

### Scenario: Auto-configuration
- GIVEN OpenRegister has registers installed
- WHEN autoConfigure() is called
- THEN registers are searched for one with slug containing "publication"
- AND for each object type, a matching schema is found by title
- AND configuration keys are populated with matching register/schema IDs

### Scenario: Version-based import decision
- GIVEN app version is "0.7.9" and stored config version is "0.7.8"
- WHEN shouldLoadSettings() is called
- THEN version_compare determines "0.7.9" > "0.7.8"
- AND returns true (import needed)

### Scenario: Repair step on install
- GIVEN the app is being installed or upgraded
- WHEN the InitializeSettings repair step runs
- THEN it checks if OpenRegister is installed
- AND if available, calls SettingsService.loadSettings(force: false)
- AND reports the number of registers, schemas, and objects imported
- AND if OpenRegister is not installed, logs a warning and skips

### Scenario: Manual import with force
- GIVEN configuration is up to date (versions match)
- WHEN POST `/api/settings/import` is called with `{force: true}`
- THEN the import proceeds regardless of version match
- AND returns success with import results and updated version info

## Dependencies

- **OpenRegister ConfigurationService** - importFromApp(), getConfiguredAppVersion()
- **OpenRegister RegisterMapper** - findAll() for discovering available registers
- **OpenRegister SchemaMapper** - find() for enriching registers with full schema objects
- **Nextcloud IAppConfig** - All configuration key storage
- **Nextcloud IAppManager** - App version checking, install/enable operations
- **publication_register.json** - Source of truth for schema definitions and seed data
