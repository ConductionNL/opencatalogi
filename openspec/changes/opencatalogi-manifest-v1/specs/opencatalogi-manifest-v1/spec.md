---
status: draft
---
# OpenCatalogi manifest v1 — JSON manifest renderer adoption

## Purpose

Adopt the JSON manifest renderer pattern from
`@conduction/nextcloud-vue@1.0.0-beta.12` in OpenCatalogi (Tier 4 per
ADR-024). Ship `src/manifest.json`, mount `<CnAppRoot>` from
`src/App.vue`, and drive vue-router from
`manifest.pages[*]` in `src/main.js`. Keep heavy bespoke pages on
`type: "custom"` for v1; only `Directory` migrates to
`type: "index"` because it has no bespoke modal/dialog wiring.

## ADDED Requirements

### Requirement: REQ-OCMV1-1 Manifest validates against the canonical schema

`src/manifest.json` MUST validate against
`@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json` v1.2.0
with zero errors when run through Ajv (draft 2020-12) with
`ajv-formats`.

#### Scenario: validate-manifest.js passes
- GIVEN `src/manifest.json` and the canonical schema
- WHEN `node tests/validate-manifest.js` runs
- THEN the script MUST exit 0 with `Ajv validation: PASS (0 errors)`

### Requirement: REQ-OCMV1-2 Every existing route is preserved by name and path

Every entry in the previous `src/router/index.js` MUST appear as a
`pages[]` entry in `src/manifest.json` with the same `id` (= route
`name`) and the same `route` (= path).

#### Scenario: Catalogs route preserved
- GIVEN the previous router declared `{ path: '/catalogi', name: 'Catalogs', ... }`
- WHEN `src/manifest.json` is consumed
- THEN `pages[]` MUST contain an entry with `id: "Catalogs"` and `route: "/catalogi"`

#### Scenario: PublicationDetail catalog-aware path preserved
- GIVEN the previous router declared `{ path: '/publications/:catalogSlug/:id', name: 'PublicationDetail', ... }`
- WHEN `src/manifest.json` is consumed
- THEN `pages[]` MUST contain an entry with `id: "PublicationDetail"` and `route: "/publications/:catalogSlug/:id"`

### Requirement: REQ-OCMV1-3 Directory adopts type=index with @resolve sentinels

The `Directory` page MUST declare `type: "index"` with
`config.register: "@resolve:listing_register"` and
`config.schema: "@resolve:listing_schema"`. The `@resolve:` prefix
opts the value into ADR-024 §3-4 runtime hydration.

#### Scenario: Directory uses tenant-driven slugs
- GIVEN the manifest entry `{ id: "Directory", route: "/directory", type: "index", config: { register: "@resolve:listing_register", schema: "@resolve:listing_schema", columns: [...], sidebar: { enabled: true } } }`
- WHEN `validateManifest()` runs
- THEN it MUST return `{ valid: true, errors: [] }`
- AND the Directory page MUST NOT hard-code a register or schema slug

### Requirement: REQ-OCMV1-4 Heavy bespoke pages stay type=custom

The 15 bespoke pages (Dashboard, Catalogs, CatalogDetail,
Publications, PublicationDetail, Search, Organizations, Themes,
ThemeDetail, Glossary, GlossaryDetail, Pages, PageDetail, Menus,
MenuDetail) MUST declare `type: "custom"` and a `component` field
naming the registry export.

#### Scenario: Search page resolves through customComponents
- GIVEN `pages[]` contains `{ id: "Search", route: "/search", type: "custom", component: "SearchView" }`
- WHEN `<CnAppRoot>` resolves the Search route
- THEN it MUST mount `customComponents.SearchView` (which imports `views/search/SearchIndex.vue`)

#### Scenario: Dashboard custom retention reason documented
- GIVEN the design doc lists Dashboard's retention reason
- WHEN a reviewer reads `design.md` "Per-page mapping"
- THEN the reason MUST cite the bespoke widget content (`CnChartWidget`, `CnStatsBlock` slot overrides) as the blocker

### Requirement: REQ-OCMV1-5 main.js builds router from manifest

`src/main.js` MUST derive the vue-router config from
`bundledManifest.pages[*]` via a `routesFromManifest()` helper.
Per-page imports of view files in `src/main.js` MUST NOT exist
(view files are imported by `src/customComponents.js`, not by
`main.js`).

#### Scenario: routes built from manifest
- GIVEN `src/main.js` after the rewrite
- WHEN inspecting the file
- THEN it MUST contain a `routesFromManifest(bundledManifest)` call that returns an array of route objects with `name`, `path`, `component: RoutePageRenderer`, `props`
- AND the route array MUST end with `{ path: '*', redirect: '/' }`

### Requirement: REQ-OCMV1-6 App.vue mounts CnAppRoot with shallow-cloned registry props

`src/App.vue` MUST mount `<CnAppRoot>` and accept `manifest`,
`customComponents`, `pageTypes` as props from `main.js`. The values
passed by `main.js` MUST be shallow-cloned (`{ ...defaultPageTypes }`,
`{ ...customComponents }`) before reaching `<CnAppRoot>` so Vue 2's
`Vue.extend` doesn't throw "Cannot add property `_Ctor`, object is
not extensible" against the lib's frozen module-record exports.

#### Scenario: shallow-clone before passing as props
- GIVEN `main.js` rendering `App` via `render: (h) => h(App, { props: { ... } })`
- WHEN the props are constructed
- THEN `customComponents` and `pageTypes` MUST be passed as `{ ...customComponents }` and `{ ...defaultPageTypes }` (NEW objects, not the lib's frozen export)

### Requirement: REQ-OCMV1-7 dependencies declares openregister

`manifest.dependencies` MUST be `["openregister"]` per the existing
backend dependency on the OpenRegister app's APIs.

#### Scenario: dependencies array
- GIVEN `src/manifest.json`
- WHEN inspecting `dependencies`
- THEN the value MUST be exactly `["openregister"]`

### Requirement: REQ-OCMV1-8 Webpack alias for @nextcloud/axios

`webpack.config.js` MUST add an `@nextcloud/axios$` exact-match
alias resolving to `node_modules/@nextcloud/axios/dist/index.js`.
This bypasses the package's `exports`-field gate which blocks the
nextcloud-vue lib's CJS bundle from `require('@nextcloud/axios')`.

#### Scenario: alias entry present
- GIVEN `webpack.config.js`
- WHEN inspecting `resolve.alias`
- THEN it MUST include `'@nextcloud/axios$': path.resolve(__dirname, 'node_modules/@nextcloud/axios/dist/index.js')`

### Requirement: REQ-OCMV1-9 Mount-survivable boot

`src/main.js` MUST NOT block the Vue mount on `loadTranslations()`'s
promise. Translation load is fire-and-forget; failure to load a
translation file (404 under the dev-container Apache rewrite) MUST
NOT prevent boot. Strings fall back to their English source on miss.

#### Scenario: tryLoadTranslations does not block
- GIVEN `main.js` after the rewrite
- WHEN inspecting the boot sequence
- THEN `loadTranslations(...)` MUST be called from a `tryLoadTranslations()` helper that swallows promise rejections
- AND `new Vue(...).mount('#content')` MUST execute regardless of the translation outcome

### Requirement: REQ-OCMV1-10 l10n en_US mirror

`l10n/en_US.json` MUST exist and mirror `l10n/en.json` so Apache's
locale resolution under the standard NC dev container serves the
file directly instead of rewriting to `index.php`.

#### Scenario: en_US mirror exists
- GIVEN the post-rewrite repo state
- WHEN inspecting `l10n/`
- THEN `l10n/en_US.json` MUST exist and have the same translation keys as `l10n/en.json`
