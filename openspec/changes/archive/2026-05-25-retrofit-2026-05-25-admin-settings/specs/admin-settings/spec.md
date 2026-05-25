---
retrofit_extensions:
  - SET-015
  - SET-016
  - SET-017
---

# Admin Settings

## ADDED Requirements

### Requirement: Admin settings page loads and saves configuration (SET-015)
The system SHALL provide a `Settings.vue` admin page that, on load, fetches the current
settings (`GET /api/settings`) and publishing options (`GET /api/settings/publishing`). It
SHALL persist configuration changes via `POST /api/settings`, publishing options via
`POST /api/settings/publishing`, trigger a server reload via `GET /api/settings/load`,
report the version via `GET /api/settings/version`, and run a manual import via
`POST /api/settings/import` — refreshing the loaded settings afterward.

**Priority:** Must **Status:** Implemented

#### Scenario: Load admin settings
- GIVEN the admin opens the settings page
- WHEN `Settings.vue` loads
- THEN it MUST fetch `GET /api/settings` and `GET /api/settings/publishing`

#### Scenario: Save admin settings
- GIVEN the admin edits configuration
- WHEN the settings are saved
- THEN a `POST /api/settings` request MUST be sent

#### Scenario: Run a manual import
- GIVEN the admin triggers a manual import
- WHEN the import runs
- THEN `POST /api/settings/import` MUST be called and the settings reloaded afterward

### Requirement: Admin settings bundle entry-point (SET-016)
The system SHALL provide a `settings.js` bundle entry-point that mounts the `Settings.vue`
admin component on the `#settings` element, registering the markdown editor
(`@kangc/v-md-editor` with the GitHub theme and English locale) and the FontAwesome icon
library + global `FontAwesomeIcon` component for use on the settings page.

**Priority:** Should **Status:** Implemented

#### Scenario: Mount the admin settings bundle
- GIVEN the Nextcloud admin settings section renders the opencatalogi panel
- WHEN `settings.js` runs
- THEN the `Settings.vue` component MUST be mounted on `#settings`
- AND the markdown editor and FontAwesome library MUST be registered

### Requirement: User settings dialog placeholder (SET-017)
The system SHALL provide a `UserSettings.vue` dialog (an `NcAppSettingsDialog` with a
single "General" section) that currently shows a "User preferences will appear here."
placeholder. The dialog's open state is controlled by an `open` prop and an
`update:open` event.

**Priority:** Could **Status:** Implemented

#### Scenario: Open the user settings dialog
- GIVEN the `open` prop is true
- WHEN `UserSettings.vue` renders
- THEN it MUST show the OpenCatalogi settings dialog with the General placeholder section

> **Notes:**
> The admin-settings spec previously referenced only the Admin settings surface. SET-017
> documents the observed `UserSettings.vue` placeholder dialog; it currently holds no real
> user preferences (literal placeholder text). Recorded as observed behavior.
