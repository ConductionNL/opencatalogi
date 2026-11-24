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

### Requirement: First-use walkthrough (REQ-SHELL-005)

The app MUST show the getting-started walkthrough on a user's first visit and
MUST record that the user has seen it, so it does not open again. The footer
MUST offer a menu item that replays the walkthrough on request.

#### Scenario: walkthrough opens once

- **GIVEN** a user who has not seen the walkthrough
- **WHEN** they open the app for the first time
- **THEN** the getting-started walkthrough MUST open
- **AND** it MUST NOT open again on their next visit

#### Scenario: walkthrough can be replayed

- **GIVEN** a user who has seen the walkthrough
- **WHEN** they choose the restart tutorial item in the footer menu
- **THEN** the walkthrough MUST open again

### Requirement: An administrator re-imports the shipped configuration after an update (REQ-CRR-004)

After an app update the app MUST import its shipped register configuration
when the shipped version is newer than the installed one. On the settings page
an administrator SHALL be able to start that import by hand with Reimport, and
with Force import even when the versions are equal.

#### Scenario: An update brings a newer configuration

- **GIVEN** an installation whose imported configuration is older than the shipped one
- **WHEN** the app is updated
- **THEN** the shipped configuration is imported without anyone pressing a button

#### Scenario: An administrator forces the import

- **GIVEN** an administrator on the settings page and a configuration that is already current
- **WHEN** they choose Force import
- **THEN** the shipped configuration is imported again and the page reports the result
