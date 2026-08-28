# retrofit-2026-05-26-app-shell-settings

## Why

OpenCatalogi's settings view configures the register/schema bindings, publishing options, and runs manual imports, while the app shell exposes a permission-aware navigation (the `permissions` computed injects the admin flag for manifest entries gated on `permission: "admin"`) and the main menu resolves catalog-driven nav items. These are real capabilities lacking specs; this change reverse-specs them. Pure framework plumbing (the `provide()` DI channel and the `translateForApp` i18n wrapper) is excluded with a reason.

## What Changes

- Document the settings view (load configuration/settings/version, register/schema option resolution, auto-select matching schemas, publishing-options save, manual import, save-all).
- Document the app shell's admin-aware permission computation and the catalog-preload on created.
- Document the main menu's catalog-driven nav items and open-link.
- Exclude `App.vue::provide` (DI channel) and `App.vue::translateForApp` (i18n wrapper) as framework plumbing.
- No code changes — annotation-only retrofit.

## Impact

- **Affected specs**: new capability `retrofit-2026-05-26-app-shell-settings`
- **Affected code**: `src/views/settings/Settings.vue`, `src/App.vue`, `src/navigation/MainMenu.vue` (docblock `@spec` annotations only)
- **Risk**: none — comment-only; production app, no admin merge.
