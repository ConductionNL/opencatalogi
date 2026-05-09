# OpenCatalogi — manifest v1: adopt JSON manifest renderer (Tier 4)

## Why

Per ADR-024 (`hydra/openspec/architecture/adr-024-app-manifest.md`),
every Conduction app SHOULD ship a `src/manifest.json` validated
against the canonical schema and consume `CnAppRoot` /
`CnPageRenderer` from `@conduction/nextcloud-vue`. Decidesk landed
the reference Tier-4 adoption (PR
[#160](https://github.com/ConductionNL/decidesk/pull/160)) using
schema v1.2.0 / lib `1.0.0-beta.x`. The lib's
`1.0.0-beta.12` release ships the consolidated manifest renderer
plus the `Vue.extend` frozen-component fix; OpenCatalogi can now
adopt the pattern end-to-end.

Today, `opencatalogi/src/main.js` boots a standard `vue-router`
config with hand-imported per-page Vue files and `App.vue`
hand-mounts `MainMenu` + `<router-view>`. There is no
`src/manifest.json` and no consumer of the abstract renderer. That
means:

- The router table in `src/router/index.js` and the navigation in
  `src/navigation/MainMenu.vue` duplicate concerns that the manifest
  expresses once.
- App Builder, manifest-aware audits, and other cross-app tooling
  have nothing to plug into for OpenCatalogi.
- Routes and menu entries can drift independently (recent observed
  drift: navigation lists "Catalogs" / "Glossary" while routes
  expose `Catalogs`, `Glossary`, etc., already in sync, but
  nothing enforces this).

OpenCatalogi is a **production app** — the migration must:

- Preserve every existing route name and path so that bookmarks,
  external links, and the Nextcloud nav entry keep working.
- Keep heavy bespoke pages (catalog/publication CRUD, search with
  facets, glossary index, settings, dashboard with custom widgets)
  rendering through their existing Vue components — those map to
  `type: "custom"` for v1.
- Use the tenant-driven IAppConfig values for any built-in `index`
  / `detail` pages so multi-tenant deployments resolve the right
  register/schema at runtime (per project memory: `listing_register`,
  `listing_schema`, `listing_source`).

## What Changes

- **New `src/manifest.json`** declaring all 16 routes from
  `src/router/index.js`, mirroring the menu entries from
  `src/navigation/MainMenu.vue`. Most heavy pages keep
  `type: "custom"` and point at their existing Vue files via
  `customComponents`. The `Directory` route adopts `type: "index"`
  with `@resolve:listing_register` / `@resolve:listing_schema`
  sentinels so multi-tenant config drives slug resolution.
- **Bump `@conduction/nextcloud-vue`** from
  `^0.1.0-beta.18` to `^1.0.0-beta.12` in `package.json`.
- **Replace `src/main.js`** with manifest-driven bootstrap:
  `routesFromManifest()` builds vue-router from `manifest.pages[*]`,
  every route mounts `CnPageRenderer` (shallow-cloned to dodge the
  Vue 2 `Vue.extend` frozen-component issue), `bundledManifest` +
  `customComponents` flow into `CnAppRoot`. The mount-survivable
  bootstrap pattern from decidesk `50e4df7c` is reused.
- **Replace `src/App.vue`** to mount `<CnAppRoot>` with shallow-
  cloned `defaultPageTypes` and `customComponents` props. The
  existing `objectSidebarState` + `sidebarState` provide channels
  are preserved so `Modals`, `Dialogs`, `UserSettings`, and the
  `CnIndexSidebar` / `CnObjectSidebar` host components keep working
  through the transition.
- **Delete `src/router/index.js`** — folded into `main.js`'s
  `routesFromManifest()`. Catch-all redirect to `/` preserved.
- **Delete `src/navigation/MainMenu.vue`** — replaced by lib's
  `CnAppNav` driven from `manifest.menu`. Note: the dynamic
  per-catalog menu entries that MainMenu rendered cannot live in
  the static manifest; that capability is retained as a deferred
  enhancement (see "Deferred items / blockers" in `design.md`).
- **New `src/customComponents.js`** registry exporting every
  surviving custom-rendered page so `type: "custom"` entries
  resolve at runtime.
- **Add `tests/validate-manifest.js`** — Node script that
  schema-validates `src/manifest.json` against the canonical lib
  schema using Ajv (mirrors decidesk `tests/validate-manifest.js`).
- **Mirror `l10n/en.json` → `l10n/en_US.json`** so Apache rewrite
  collisions don't 404 the translation fetch under `en_US` locale.
- **Bump `appinfo/info.xml` `<version>`** from `0.7.33` to
  `0.7.34` to mark the FE shell migration.
- **Add `@nextcloud/axios$` exact-match alias** in
  `webpack.config.js` (the Nextcloud-vue lib's CJS bundle still
  uses `require('@nextcloud/axios')` and webpack 5's exports gate
  rejects without this; same fix as decidesk).

## Custom-fallback inventory (production app — retain MORE customs)

OpenCatalogi has 16 routes plus dynamic widget entrypoints. **15 of
the 16 main routes stay `type: "custom"` for v1**. Only `Directory`
adopts `type: "index"` because it is a thin schema-backed list with
no bespoke modal/dialog wiring beyond what `CnIndexPage` already
provides via the lib. Every other page either:

- Drives the global modal/dialog stack (`navigationStore.setModal()`
  / `setDialog()`) for create / edit / copy / delete flows that
  the manifest renderer's row actions don't yet replicate, OR
- Renders bespoke widget content (Dashboard's `CnChartWidget` +
  `CnStatsBlock` per-tile slot overrides), OR
- Runs a non-trivial route-param flow (Publications resolves
  `:catalogSlug` to the catalog-aware `/api/{catalogSlug}` endpoint,
  Search has an in-page `SearchSideBar` named-view), OR
- Is itself a settings/admin host (Settings.vue's 837-line nested
  config UI; UserSettings dialog).

See `design.md` "Per-page mapping" for the detailed reason for each
retention. Each is documented with a one-line justification per the
production-app reviewer expectations.

## Capabilities

### New Capabilities

- `opencatalogi-manifest-v1`: declares the manifest contract for
  OpenCatalogi (route name preservation, menu mirroring, multi-
  tenant slug resolution via `@resolve:` sentinels, dependency on
  `openregister`).

### Modified Capabilities

*(none — fresh capability.)*

## Impact

- **New files**:
  - `opencatalogi/src/manifest.json`
  - `opencatalogi/src/customComponents.js`
  - `opencatalogi/tests/validate-manifest.js`
  - `opencatalogi/l10n/en_US.json` (mirror of `en.json`)
- **Modified files**:
  - `opencatalogi/src/main.js` — manifest-driven router + CnAppRoot bootstrap
  - `opencatalogi/src/App.vue` — CnAppRoot mount, shallow-clone props
  - `opencatalogi/package.json` — `@conduction/nextcloud-vue` floor `^1.0.0-beta.12`
  - `opencatalogi/webpack.config.js` — `@nextcloud/axios$` exact-match alias
  - `opencatalogi/appinfo/info.xml` — `<version>` 0.7.33 → 0.7.34
- **Deleted files**:
  - `opencatalogi/src/router/index.js` (folded into `main.js`)
  - `opencatalogi/src/navigation/MainMenu.vue` (replaced by lib's
    `CnAppNav` driven from `manifest.menu`)
- **Validates against**:
  - `@conduction/nextcloud-vue@1.0.0-beta.12` ships
    `src/schemas/app-manifest.schema.json` v1.2.0.

## Risks

- **Production app — UX regressions cost users.** The migration
  preserves every route name + path, keeps every heavy page as
  `type: "custom"`, and re-uses the existing modal/dialog/sidebar
  channels. The risk is concentrated in `App.vue` + `main.js` where
  bootstrap order changes; the mount-survivable pattern from
  decidesk `50e4df7c` is reused to keep boot resilient to the
  `loadTranslations` 404 that some Nextcloud installs (including
  the standard dev container) hit.
- **Dynamic catalog menu entries** — MainMenu currently iterates
  `objectStore.getCollection('catalog')` to render one nav entry
  per catalog. The static manifest cannot express this. For v1, the
  static menu loses the per-catalog entries; users navigate to a
  catalog by selecting "Catalogs" then clicking a row. Deferred to
  a follow-up that wires the lib's runtime-merge hook (Backend
  endpoint `/api/manifest` per ADR-024 §4).
- **Settings page** — `Settings.vue` is 837 lines of nested
  configuration UI (registers, schemas, slugs, OAS, theming).
  Ported as `type: "custom"` for v1; full migration to
  `type: "settings"` with rich sections is a future change.
- **Search page** — uses a `SearchSideBar` named-view in vue-router.
  Manifest currently has no first-class concept of named views.
  Retained as `type: "custom"` for v1; the SearchSideBar loads
  inside the page component itself rather than as a router slot.
- **Publication routes** — `/publications/:catalogSlug` and
  `/publications/:catalogSlug/:id` use the dynamic `catalogSlug`
  param to route the API call. Retained as `type: "custom"` so
  the existing `PublicationIndex` resolves the right catalog.

## Out of scope

- Migrating heavy pages (Catalogs, Publications, Search, Glossary,
  Themes, Pages, Menus, Organizations, Dashboard, Settings) to
  built-in `type: "index"` / `"detail"` / `"dashboard"` /
  `"settings"`. Each page has bespoke modal/dialog wiring or custom
  widget content; migration requires the lib to grow per-page
  extension hooks (row actions config, dialog dispatch, per-slot
  custom components) AND a careful per-page UX regression check.
  Tracked as future work.
- Backend `/api/manifest` endpoint (App Builder / dynamic menu).
- Per-catalog dynamic menu entries (related to the above; needs
  the runtime-merge hook).
- Settings page decomposition into rich `type: "settings"` sections.
- i18n / multi-tenancy wiring beyond the manifest's
  `@resolve:listing_*` sentinels for the Directory page.

## See also

- `hydra/openspec/architecture/adr-024-app-manifest.md` — fleet-wide
  manifest convention.
- `decidesk/openspec/changes/decidesk-manifest-v1/` — Tier-4
  reference adoption.
- Decidesk PR
  [#160](https://github.com/ConductionNL/decidesk/pull/160) —
  merged reference for the renderer adoption shape.
- `@conduction/nextcloud-vue@1.0.0-beta.12` — published lib release
  with the consolidated manifest renderer + `Vue.extend`
  frozen-component fix.
