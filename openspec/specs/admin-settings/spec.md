---
status: done
or_dep: IAppConfig
audit_ref: .claude/audit-2026-05-03/02-spec-rewrite.md
---

# Admin Settings

> **NEEDS-REWRITE notice:** This spec was rewritten as part of
> `opencatalogi-adopt-or-abstractions` (Phase 7 + Phase 8). The
> duplicated IAppConfig patterns, the hardcoded version constant, and
> the bespoke configuration validation described in the previous version
> are replaced by citations of OR's `IAppConfig` conventions. The Phase
> 8 magic-number keys are added to the inventory table. See the REMOVED
> section and Breaking Changes.
>
> Upstream dependency: OR `IAppConfig` conventions.

## Purpose

@e2e exclude OR-abstraction-consumer spec — admin-config conventions delegated to OpenRegister's IAppConfig and verified by unit/Newman tests, not browser-UI observable.

The admin settings module provides the configuration interface for
opencatalogi. After the Phase 7 rewrite, this spec cites OR's `IAppConfig`
conventions as the authoritative source for key naming, validation,
secret handling, and default values. opencatalogi MUST NOT redefine these
conventions locally — the convention is owned upstream and consumed here.

Phase 8 promotes three hardcoded class constants to admin-config keys;
those keys are added to the inventory table below.

## Requirements

### Requirement: every admin-config key follows the OR `IAppConfig` naming convention (SET-OR-001)

Every configuration key opencatalogi reads or writes via `IAppConfig` MUST
follow OR's snake_case naming convention with a namespace prefix where
required. The inventory table in this spec is the single canonical list
operators read.

When a key is added, renamed, or removed, the inventory table MUST be
updated in the same spec change. A PR that adds a config key without an
inventory update MUST be rejected.

> @e2e exclude Code-contract / documentation-audit requirement (config keys follow OR snake_case naming + the inventory table is the canonical list) — no UI surface; verified by code review against the inventory table and a grep-based key audit, not a browser flow.

#### Scenario: reviewer audits the admin-settings spec

- **WHEN** a reviewer audits this spec,
- **THEN** they find every key opencatalogi reads or writes via
  `IAppConfig`, with default value, type, validation rule, and a sentence
  describing effect,
- **AND** there are no keys in code that are absent from this table.

### Requirement: secrets are stored per OR conventions (SET-OR-002)

Any configuration key that carries a secret (token, credential, password) MUST be marked sensitive per OR's `IAppConfig` convention so that it does not leak through generic settings dumps.

> @e2e exclude Backend secret-storage contract (sensitive flag on IAppConfig keys so secrets do not appear in generic dumps) — no UI surface; verified by PHPUnit asserting the sensitive marking and absence from a generic config dump.

#### Scenario: a secret key is stored

- **GIVEN** a setting carries a secret value,
- **WHEN** stored via `IAppConfig`,
- **THEN** the secret is marked sensitive per OR convention,
- **AND** it does NOT appear in plain-text generic dumps.

### Requirement: configuration defaults are declared in the inventory table (SET-OR-003)

Every key's default value MUST be declared in the inventory table below.
Hardcoded class constants that serve as defaults MUST be promoted to
admin-config keys with the same default values (see Phase 8 keys in the
inventory).

> @e2e exclude Backend default-resolution contract (unset IAppConfig key returns the inventory default, no class constant consulted at runtime) — no UI surface; verified by PHPUnit reading a never-set key and asserting the inventory default.

#### Scenario: reading a key that was never set

- **GIVEN** an admin has not configured a given key,
- **WHEN** the application reads that key via `IAppConfig`,
- **THEN** the default value from the inventory table is returned,
- **AND** no class constant is consulted at runtime.

### Requirement: `MIN_OPENREGISTER_VERSION` constant deleted (SET-OR-004)

`lib/Service/SettingsService.php` MUST NOT define a `MIN_OPENREGISTER_VERSION`
constant. The minimum OR version is enforced by Nextcloud's dependency check
driven by `appinfo/info.xml` `<dependencies>`. The constant is deleted in
Phase 8.

> @e2e exclude Source-code-structure contract (MIN_OPENREGISTER_VERSION constant removed; minimum version enforced by appinfo/info.xml dependency check) — no UI surface; verified by a grep assertion over SettingsService.php plus the NC install-time dependency check.

#### Scenario: minimum-version constant no longer exists

- **WHEN** a developer greps `lib/Service/SettingsService.php` for
  `MIN_OPENREGISTER_VERSION`,
- **THEN** the constant is not found,
- **AND** the install-time dependency check in `appinfo/info.xml` enforces
  the minimum OR version instead.

### Requirement: auto-configuration cites OR configuration service (SET-OR-005)

The auto-configuration path (`autoConfigure()`) MUST use OR's
`ConfigurationService` for register/schema discovery. It MUST NOT
implement its own register-slug-matching logic if OR's service provides
equivalent discovery.

#### Scenario: auto-configuration discovers registers via OR

- **GIVEN** the auto-configuration path runs,
- **WHEN** `autoConfigure()` resolves registers and schemas,
- **THEN** it MUST call OR's `ConfigurationService` for discovery,
- **AND** it MUST NOT run a bespoke register-slug-matching routine.

### Requirement: admin settings page loads and saves configuration (SET-OR-006)

The `Settings.vue` admin page MUST, on load, fetch the current settings
(`GET /api/settings`) and publishing options (`GET /api/settings/publishing`).
It MUST persist configuration changes via `POST /api/settings` and
publishing options via `POST /api/settings/publishing`.

Configuration values MUST be sourced from `IAppConfig`; display values
MUST follow OR's data types.

#### Scenario: load admin settings

- **GIVEN** the admin opens the settings page,
- **WHEN** `Settings.vue` loads,
- **THEN** it MUST fetch `GET /api/settings` and
  `GET /api/settings/publishing`.

#### Scenario: save admin settings

- **GIVEN** the admin edits configuration,
- **WHEN** the settings are saved,
- **THEN** a `POST /api/settings` request MUST be sent.

## REMOVED Requirements

The following requirements described patterns that duplicated OR's `IAppConfig`
conventions. They are retained for traceability; implementation MUST NOT
re-introduce them.

| ID | Title | Reason removed |
|----|-------|----------------|
| SET-005 | Check and install/update OpenRegister dependency (minimum version 0.1.7) | REMOVED — re-implements Nextcloud's native dependency check; `appinfo/info.xml` `<dependencies>` is the only enforcement mechanism. The PHP `MIN_OPENREGISTER_VERSION` constant and any runtime version check in `SettingsService` are deleted. Superseded by SET-OR-004. |

SET-001 through SET-004, SET-006 through SET-017 are superseded by SET-OR-001
through SET-OR-006. Observable behaviours are preserved; the convention
ownership is now explicitly cited to OR's `IAppConfig`.

## Admin-Config Key Inventory

This table is the **single canonical list** of all keys opencatalogi reads or
writes via `IAppConfig`. All keys use snake_case. Operators MUST set the
required keys before first use.

### Register / Schema Mappings (required)

| Key | Type | Default | Required | Description |
|-----|------|---------|----------|-------------|
| `catalog_source` | string | `"openregister"` | Yes | Always "openregister" |
| `catalog_register` | string | `""` | Yes | OpenRegister register ID for catalog objects |
| `catalog_schema` | string | `""` | Yes | OpenRegister schema ID for catalog objects |
| `listing_source` | string | `"openregister"` | Yes | Always "openregister" |
| `listing_register` | string | `""` | Yes | OpenRegister register ID for listing objects |
| `listing_schema` | string | `""` | Yes | OpenRegister schema ID for listing objects |
| `organization_source` | string | `"openregister"` | Yes | Always "openregister" |
| `organization_register` | string | `""` | Yes | OpenRegister register ID for organization objects |
| `organization_schema` | string | `""` | Yes | OpenRegister schema ID for organization objects |
| `theme_source` | string | `"openregister"` | Yes | Always "openregister" |
| `theme_register` | string | `""` | Yes | OpenRegister register ID for theme objects |
| `theme_schema` | string | `""` | Yes | OpenRegister schema ID for theme objects |
| `page_source` | string | `"openregister"` | Yes | Always "openregister" |
| `page_register` | string | `""` | Yes | OpenRegister register ID for page objects |
| `page_schema` | string | `""` | Yes | OpenRegister schema ID for page objects |
| `menu_source` | string | `"openregister"` | Yes | Always "openregister" |
| `menu_register` | string | `""` | Yes | OpenRegister register ID for menu objects |
| `menu_schema` | string | `""` | Yes | OpenRegister schema ID for menu objects |
| `glossary_source` | string | `"openregister"` | Yes | Always "openregister" |
| `glossary_register` | string | `""` | Yes | OpenRegister register ID for glossary objects |
| `glossary_schema` | string | `""` | Yes | OpenRegister schema ID for glossary objects |
| `publications_register` | string | `""` | Yes | OpenRegister register ID for publication objects |
| `publications_schema` | string | `""` | Yes | OpenRegister schema ID for publication objects |

### Publishing Options

| Key | Type | Default | Required | Description |
|-----|------|---------|----------|-------------|
| `auto_publish_attachments` | string (bool) | `"false"` | No | When `"true"`, auto-create public share links for attachments on published objects. See [auto-publishing spec](../auto-publishing/spec.md). |
| `auto_publish_objects` | string (bool) | `"false"` | No | When `"true"`, auto-publish objects matching a catalog on creation. See [auto-publishing spec](../auto-publishing/spec.md). |
| `use_old_style_publishing_view` | string (bool) | `"false"` | No | Use legacy publishing view layout. |

### Broadcast Configuration (Phase 8 — promoted from class constants)

| Key | Type | Default | Required | Description |
|-----|------|---------|----------|-------------|
| `broadcast_max_retries` | int | `3` | No | Maximum retry attempts for outbound broadcast HTTP calls. Previously `BroadcastService::MAX_RETRIES = 3`. |
| `broadcast_request_timeout` | int | `30` | No | Timeout in seconds for outbound broadcast HTTP calls. Previously `BroadcastService::REQUEST_TIMEOUT = 30`. |

### Sitemap Configuration (Phase 8 — promoted from class constants)

| Key | Type | Default | Required | Description |
|-----|------|---------|----------|-------------|
| `sitemap_max_per_page` | int | `1000` | No | Maximum entries per sitemap page. Previously `SitemapService::MAX_PER_PAGE = 1000`. |

### Metadata-Quality Scoring (MQA/FAIR)

| Key | Type | Default | Required | Description |
|-----|------|---------|----------|-------------|
| `quality_weight_findability` | int | `25` | No | MQA dimension weight for findability (PQM-001). |
| `quality_weight_accessibility` | int | `25` | No | MQA dimension weight for accessibility (PQM-001). |
| `quality_weight_interoperability` | int | `20` | No | MQA dimension weight for interoperability (PQM-001). |
| `quality_weight_reusability` | int | `20` | No | MQA dimension weight for reusability (PQM-001). |
| `quality_weight_contextuality` | int | `10` | No | MQA dimension weight for contextuality (PQM-001). |

> The per-catalog DQV exposure toggle (PQM-002) is a `dqvExposure` field on the
> `catalog` object (not an `IAppConfig` key), mirroring `hasDcat` — it defaults off.

### Version Tracking

| Key | Type | Default | Required | Description |
|-----|------|---------|----------|-------------|
| `installed_version` | string | `""` | No | Last-configured app version. Used to detect upgrades and trigger settings reload. |

## Breaking Changes

| Breaking change | Old behaviour | New behaviour |
|---|---|---|
| `SettingsService::MIN_OPENREGISTER_VERSION = '0.1.7'` removed | PHP runtime version check called in SettingsService | Constant deleted; Nextcloud's `appinfo/info.xml` `<dependencies>` enforces the minimum OR version at install time. Code that reads the constant will throw a `ClassConstant not found` error. |
| `BroadcastService::MAX_RETRIES` / `REQUEST_TIMEOUT` promoted | Class constants 3 / 30 hardcoded | Read from `IAppConfig` keys `broadcast_max_retries` / `broadcast_request_timeout` (defaults unchanged). Code that reads the constants directly will throw. |
| `SitemapService::MAX_PER_PAGE` promoted | Class constant 1000 hardcoded | Read from `IAppConfig` key `sitemap_max_per_page` (default unchanged). |

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

## References

- OR `IAppConfig` conventions (upstream dependency)
- `.claude/audit-2026-05-03/02-spec-rewrite.md` (Stream 2 NEEDS-REWRITE rationale)
- `.claude/audit-2026-05-03/04-hardcoded.md` (Stream 4 — Phase 8 magic-number cleanup)
- `openspec/changes/opencatalogi-adopt-or-abstractions/` (Phase 7 + Phase 8 implementation change)
- ADR-022 — Apps consume OR abstractions
