# Design: admin-settings

## Architecture Overview

The admin settings module follows the strict Controller → Service → Mapper three-layer pattern (ADR-003). All 21 object-type config keys and 3 publishing option keys are stored exclusively in `IAppConfig`; no custom Mapper or Entity is needed for this module.

```
Nextcloud Admin Settings Framework
  └─ OpenCatalogiAdmin.php (ISettings)
       └─ settings.js entry point
            └─ AdminRoot.vue
                 └─ Settings.vue

HTTP Client (Vue/axios)
  └─ SettingsController.php
       └─ SettingsService.php
            ├─ IAppConfig          (config key read/write)
            ├─ RegisterMapper      (findAll — discover registers)
            ├─ SchemaMapper        (find — enrich with schema objects)
            ├─ ConfigurationService (importFromApp, getConfiguredAppVersion)
            └─ IAppManager         (version, install check)

Nextcloud Upgrade/Install
  └─ InitializeSettings.php (IRepairStep)
       └─ SettingsService.loadSettings(force: false)
```

The module is **read-heavy**: `GET /api/settings` is called on every page load of the admin settings UI and by `App.vue` on startup (to check `openRegisters`). Write operations are infrequent (manual import, settings save).

## API Design

### `GET /api/settings`

Returns the current configuration for all 7 object types, plus the list of available OpenRegister registers enriched with full schema objects.

**Auth:** `#[NoAdminRequired]` — any authenticated user (needed for the app's empty-state check)

**Response (200):**
```json
{
  "objectTypes": ["catalog", "listing", "organization", "theme", "page", "menu", "glossary"],
  "openRegisters": true,
  "availableRegisters": [
    {
      "id": "1",
      "title": "Publication Register",
      "slug": "publication-register",
      "schemas": [
        { "id": "10", "title": "Catalog", "slug": "catalog" },
        { "id": "11", "title": "Listing", "slug": "listing" }
      ]
    }
  ],
  "configuration": {
    "catalog_source": "openregister",
    "catalog_schema": "10",
    "catalog_register": "1",
    "listing_source": "openregister",
    "listing_schema": "11",
    "listing_register": "1",
    "organization_source": "openregister",
    "organization_schema": "12",
    "organization_register": "1",
    "theme_source": "openregister",
    "theme_schema": "13",
    "theme_register": "1",
    "page_source": "openregister",
    "page_schema": "14",
    "page_register": "1",
    "menu_source": "openregister",
    "menu_schema": "15",
    "menu_register": "1",
    "glossary_source": "openregister",
    "glossary_schema": "16",
    "glossary_register": "1",
    "auto_publish_attachments": "false",
    "auto_publish_objects": "false",
    "use_old_style_publishing_view": "false"
  }
}
```

### `POST /api/settings`

Updates schema/register mappings. Accepts a partial or full configuration object.

**Auth:** `#[AuthorizedAdminSetting(Application::APP_ID)]`

**Request:**
```json
{
  "catalog_schema": "10",
  "catalog_register": "1",
  "listing_schema": "11",
  "listing_register": "1"
}
```

**Response (200):** Updated configuration (same shape as GET)

### `GET /api/settings/load`

Triggers a configuration load from `lib/Settings/publication_register.json`.

**Auth:** `#[AuthorizedAdminSetting(Application::APP_ID)]`

**Response (200):**
```json
{
  "success": true,
  "registers": 1,
  "schemas": 7,
  "objects": 0
}
```

### `GET /api/settings/publishing`

Returns the three publishing option flags.

**Auth:** `#[NoAdminRequired]`

**Response (200):**
```json
{
  "auto_publish_attachments": false,
  "auto_publish_objects": false,
  "use_old_style_publishing_view": false
}
```

### `POST /api/settings/publishing`

Updates one or more publishing flags.

**Auth:** `#[AuthorizedAdminSetting(Application::APP_ID)]`

**Request:**
```json
{
  "auto_publish_attachments": true
}
```

**Response (200):** Updated publishing options (same shape as GET)

### `GET /api/settings/version`

Returns version information.

**Auth:** `#[NoAdminRequired]`

**Response (200):**
```json
{
  "appVersion": "0.7.9",
  "configuredVersion": "0.7.8",
  "match": false
}
```

### `POST /api/settings/import`

Manually triggers configuration import.

**Auth:** `#[AuthorizedAdminSetting(Application::APP_ID)]`

**Request:**
```json
{
  "force": true
}
```

**Response (200):**
```json
{
  "success": true,
  "registers": 1,
  "schemas": 7,
  "objects": 0,
  "version": {
    "appVersion": "0.7.9",
    "configuredVersion": "0.7.9",
    "match": true
  }
}
```

## Database Changes

### Migrations

Four migrations under `lib/Migration/`, all following Nextcloud's `Version{majorVersion}Date{YYYYMMDDHHMMSS}` naming pattern:

| Class | Date | Purpose |
|-------|------|---------|
| `Version6Date20241011085015` | 2024-10-11 | Initial migration |
| `Version6Date20241129151236` | 2024-11-29 | Second migration |
| `Version6Date20241208222530` | 2024-12-08 | Third migration |
| `Version6Date20250419123213` | 2025-04-19 | Fourth migration |

OpenCatalogi primarily stores content as OpenRegister objects; these migrations handle ancillary structures (caching tables, configuration storage) rather than core content tables.

### IAppConfig Key Inventory

**Object type mappings (21 keys):**
For each type in `[catalog, listing, organization, theme, page, menu, glossary]`:
- `{type}_source` — always `"openregister"`
- `{type}_schema` — OpenRegister schema ID
- `{type}_register` — OpenRegister register ID

**Publishing options (3 keys):**
- `auto_publish_attachments` — default `"false"`
- `auto_publish_objects` — default `"false"`
- `use_old_style_publishing_view` — default `"false"`

**Version tracking (1 key):**
- `configuredVersion` — set to app version after successful import

## Nextcloud Integration

### PHP Classes

| Class | Location | Role |
|-------|----------|------|
| `SettingsController` | `lib/Controller/SettingsController.php` | 7-method thin controller |
| `SettingsService` | `lib/Service/SettingsService.php` | All business logic |
| `OpenCatalogiAdmin` | `lib/Settings/OpenCatalogiAdmin.php` | `ISettings` panel registration |
| `InitializeSettings` | `lib/Migration/InitializeSettings.php` | `IRepairStep` bootstrap |
| `Version6Date20241011085015` | `lib/Migration/` | DB migration 1 |
| `Version6Date20241129151236` | `lib/Migration/` | DB migration 2 |
| `Version6Date20241208222530` | `lib/Migration/` | DB migration 3 |
| `Version6Date20250419123213` | `lib/Migration/` | DB migration 4 |

### Vue Components

| Component | Location | Role |
|-----------|----------|------|
| `AdminRoot.vue` | `src/views/settings/` | `settings.js` entry root (NOT a router view) |
| `Settings.vue` | `src/views/settings/` | Admin settings body (CnVersionInfoCard → CnRegisterMapping → publishing toggles) |

### Routes (`appinfo/routes.php`)

```php
['name' => 'settings#index',       'url' => '/api/settings',            'verb' => 'GET'],
['name' => 'settings#update',      'url' => '/api/settings',            'verb' => 'POST'],
['name' => 'settings#load',        'url' => '/api/settings/load',       'verb' => 'GET'],
['name' => 'settings#publishing',  'url' => '/api/settings/publishing', 'verb' => 'GET'],
['name' => 'settings#savePublishing', 'url' => '/api/settings/publishing', 'verb' => 'POST'],
['name' => 'settings#version',     'url' => '/api/settings/version',    'verb' => 'GET'],
['name' => 'settings#import',      'url' => '/api/settings/import',     'verb' => 'POST'],
```

### DI

`SettingsService` is injected into `SettingsController` and `InitializeSettings` via constructor injection with `private readonly`. No static locators or `\OC::$server`.

## File Structure

```
opencatalogi/
  appinfo/
    routes.php                                # 7 settings routes (see above)
    info.xml                                  # admin settings section registration
  lib/
    Controller/
      SettingsController.php                  # 7 methods, delegates to SettingsService
    Service/
      SettingsService.php                     # all settings business logic
    Settings/
      OpenCatalogiAdmin.php                   # ISettings implementation
      publication_register.json               # source of truth for schema definitions
    Migration/
      InitializeSettings.php                  # IRepairStep — bootstraps on install/upgrade
      Version6Date20241011085015.php          # DB migration 1
      Version6Date20241129151236.php          # DB migration 2
      Version6Date20241208222530.php          # DB migration 3
      Version6Date20250419123213.php          # DB migration 4
  src/
    views/settings/
      AdminRoot.vue                           # settings.js entry root
      Settings.vue                            # admin settings page body
  webpack.config.js                           # settings.js entry point
```

## Seed Data

Not applicable. Per ADR-001 (data layer), changes that modify only non-schema backend logic (settings, configuration) do not require seed data. The admin settings module stores configuration in `IAppConfig`, not as OpenRegister objects.

## Reuse Analysis

Per ADR-001, the following OpenRegister services are consumed directly — no duplication:

| OpenRegister Service | Usage |
|---------------------|-------|
| `ConfigurationService::importFromApp()` | Import `publication_register.json` into OpenRegister |
| `ConfigurationService::getConfiguredAppVersion()` | Retrieve the last successfully imported version |
| `RegisterMapper::findAll()` | Discover all available registers for the mapping UI |
| `SchemaMapper::find()` | Enrich each register with its full schema objects |

Frontend components consumed from `@conduction/nextcloud-vue`:
- `CnVersionInfoCard` — version display (required as first element on admin pages)
- `CnRegisterMapping` — register/schema mapping dropdowns for all 7 object types
- `CnSettingsSection` — section wrapper for publishing options

No custom search, CRUD, file management, or dashboard components needed for this module.

## Key Design Decisions

### Decision 1: `IAppConfig` for all settings, no custom tables

**Choice:** Store all 25 configuration keys in Nextcloud's `IAppConfig`.

**Rationale:** Per ADR-001 ("App config → `IAppConfig`. NOT OpenRegister."), settings are not domain objects and must not be stored in OpenRegister. `IAppConfig` provides typed get/set, scoped to the app ID, with built-in encryption support for future sensitive values. No migration risk when adding or removing keys.

### Decision 2: Admin settings page is NOT a vue-router route

**Choice:** `AdminRoot.vue` is the `settings.js` bundle entry point, registered via `OpenCatalogiAdmin.php` and rendered by Nextcloud's settings framework at `/settings/admin/opencatalogi`.

**Rationale:** Per ADR-004, adding admin settings components to the vue-router makes them accessible as frontend routes, bypassing all server-side access checks. Nextcloud's settings framework handles authorization at the server level.

### Decision 3: `#[NoAdminRequired]` on read endpoints

**Choice:** `GET /api/settings`, `GET /api/settings/publishing`, and `GET /api/settings/version` carry `#[NoAdminRequired]`.

**Rationale:** `App.vue` checks `openRegisters` on every page load to decide whether to show an empty state. Requiring admin privileges on this read-only endpoint would break the empty state UX for non-admin users who open the app before it is configured. The response contains no sensitive data — only boolean flags and public register metadata.

### Decision 4: Version-based import guard with `force` override

**Choice:** `shouldLoadSettings()` uses `version_compare` to skip import when the stored config version matches the app version. `POST /api/settings/import` with `{force: true}` bypasses this guard.

**Rationale:** Prevents redundant reimports on every page load while still supporting manual re-import when the admin suspects the configuration is stale. The force flag is admin-only.

### Decision 5: Register enrichment on GET /api/settings

**Choice:** `availableRegisters` in the GET response includes full schema objects (not just IDs).

**Rationale:** `CnRegisterMapping` needs the full schema list to populate its dropdowns. Fetching schemas in a second API call from the frontend would require a public OpenRegister API, which may not be available on all installations. Enriching on the backend with `SchemaMapper::find()` keeps the frontend stateless.

## Security Considerations

- All write endpoints (`POST /api/settings`, `GET /api/settings/load`, `POST /api/settings/publishing`, `POST /api/settings/import`) use `#[AuthorizedAdminSetting(Application::APP_ID)]` — enforced at the middleware layer.
- Read endpoints carry `#[NoAdminRequired]` but return only public configuration data (register IDs, boolean flags).
- No PII is stored in or returned by any settings endpoint.
- Error responses use static generic messages; `$e->getMessage()` is never returned to clients.

## NL Design System / Accessibility

- `Settings.vue` uses Nextcloud CSS variables exclusively (`var(--color-primary-element)`, etc.).
- All user-visible strings use `t('opencatalogi', '...')` wrappers (ADR-007).
- Labels for all `NcSelect`/`NcCheckbox` elements use `inputLabel` prop (not manual `<label>` elements).
- `CnVersionInfoCard` appears first on the admin page as required by ADR-004.

## Trade-offs

### Considered: Fetch registers and schemas as separate API calls from frontend

**Rejected:** Requires the frontend to know OpenRegister API URLs, which vary by installation. The backend enrichment approach is simpler and avoids CORS complexity.

### Considered: Store publishing options in OpenRegister

**Rejected:** Publishing options are app configuration, not domain objects. Per ADR-001, app config must live in `IAppConfig`.

### Considered: Single combined `GET /api/settings` response including version and publishing

**Rejected:** Separating the endpoints provides finer-grained authorization (`version` and `publishing` GET are non-admin; `settings` POST is admin). Polling frequency may differ between consumers. Kept separate per the existing REST conventions.
