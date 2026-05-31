# retrofit-2026-05-26-app-shell-settings Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-26-app-shell-settings. Update Purpose after archive.
## Requirements
### Requirement: Settings configuration (REQ-SHELL-001)
The settings view MUST load the current configuration, settings, and version info, MUST resolve register and schema options, MUST auto-select the OpenCatalogi register and matching schemas, and MUST react to register changes by updating the schema options.

#### Scenario: Schema options follow register
- **GIVEN** the settings view with a register selected
- **WHEN** the register selection changes
- **THEN** the schema options MUST be updated for the new register

### Requirement: Settings persistence and import (REQ-SHELL-002)
The settings view MUST save publishing options and the full configuration, and MUST run a manual import on request.

#### Scenario: Save all persists configuration
- **GIVEN** modified settings
- **WHEN** save-all runs
- **THEN** the configuration and publishing options MUST be persisted

### Requirement: Admin-aware navigation permissions (REQ-SHELL-003)
The app shell MUST compute the user's permission set and MUST inject an `admin` permission for admin users so manifest navigation entries gated on `permission: "admin"` resolve correctly, and MUST preload the catalog collection on creation so nav and publication routes can resolve the active catalog slug.

#### Scenario: Admin flag injected
- **GIVEN** the current user is an administrator
- **WHEN** the permission set is computed
- **THEN** the set MUST include `admin`

### Requirement: Catalog-driven main menu (REQ-SHELL-004)
The main menu MUST present catalog-driven navigation items resolved from the catalog collection and MUST open external links.

#### Scenario: Nav items follow catalogs
- **GIVEN** a loaded catalog collection
- **WHEN** the main menu renders
- **THEN** its navigation items MUST correspond to the catalogs

