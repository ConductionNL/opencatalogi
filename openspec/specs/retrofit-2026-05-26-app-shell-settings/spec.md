---
status: done
---

# retrofit-2026-05-26-app-shell-settings Specification

## Purpose
Provides the OpenCatalogi application shell and settings configuration, letting administrators load and persist the app configuration, select a register, and auto-resolve its matching schemas. Computes the user's permission set, injecting an admin permission so manifest navigation gated on admin resolves, and presents a catalog-driven main menu built from the loaded catalog collection.

> @e2e exclude Whole-spec reverse-engineered settings/app-shell component-logic capability — every scenario asserts component/config wiring (schema options follow the selected register, save-all persists the configuration, admin flag injected into navigation permissions, main-menu nav items follow the configured catalogs). These are deterministic computed-property / IAppConfig-round-trip assertions verified by vitest over the settings component plus PHPUnit for the config persistence; the settings surface is already real-UI covered under admin-settings::load-admin-settings and ::save-admin-settings.

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

