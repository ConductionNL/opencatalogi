# Design — OpenCatalogi manifest v1: JSON manifest renderer adoption

## Approach

OpenCatalogi today wires its UI shell with a hand-coded
`vue-router` config (`src/router/index.js`), a hand-coded
`MainMenu.vue`, and a `<router-view>` mounted by `App.vue`. The
adoption mirrors decidesk's PR #160:

1. Generate `src/manifest.json` from the existing router + nav
   tables, naming every route and menu entry exactly as today so
   bookmarks and external links keep working.
2. Bootstrap from manifest in `main.js` (`routesFromManifest()`)
   and mount `CnAppRoot` in `App.vue`. Shallow-clone
   `defaultPageTypes` and the `customComponents` map before
   passing them as props (Vue 2 `Vue.extend` mutates the
   component definition with an internal `_Ctor` cache; the lib
   exports are frozen module records, so cloning is mandatory).
3. Keep the heavy bespoke pages on `type: "custom"`. They retain
   their existing Vue files and continue to drive the global
   `navigationStore` modal/dialog stack and the host-rendered
   `CnIndexSidebar` / `CnObjectSidebar` channels.
4. Adopt `type: "index"` only where it's safe — Directory is a
   thin schema-backed list whose register/schema slugs come from
   tenant config (`listing_register` / `listing_schema` IAppConfig
   keys). The manifest declares those as `@resolve:listing_register`
   / `@resolve:listing_schema` sentinels; `useAppManifest` hydrates
   them from the runtime config endpoint per ADR-024 §3-4.

The migration is intentionally **conservative**: 15 of the 16
main routes stay `type: "custom"` for v1. Production-app reviewers
care more about preserving UX than about how many pages are
declarative; future migration changes can shrink the custom set
incrementally as the lib grows the matching extension points.

## Per-page mapping table

| Current route name | Path | New type | Component / config | Reason for retention |
|---|---|---|---|---|
| `Dashboard` | `/` | `custom` | `DashboardView` (`views/dashboard/Dashboard.vue`) | Heavy bespoke widgets — `CnChartWidget` (donut + area), `CnStatsBlock` per-tile slot overrides, custom layout-change persistence, schema-aware fetch logic against `/apps/openregister/api/dashboard/charts/...`. Migrating to `type: "dashboard"` requires the lib to express custom slot content per widget; out of scope for v1. |
| `Catalogs` | `/catalogi` | `custom` | `CatalogsView` (`views/catalogi/CatalogiIndex.vue`) | Drives `navigationStore.setModal('catalog')` for create/edit, `setDialog('copyObject')` / `setDialog('deleteObject')` for row actions, and a custom `column-organization` slot resolving organization names from a separate collection. Keep custom until the lib's `actions[]` config supports dispatching to a consumer-provided action handler. |
| `CatalogDetail` | `/catalogi/:id` | `custom` | `CatalogDetailView` (`views/catalogi/CatalogDetailPage.vue`) | Per-catalog admin surface (registers/schemas listing, OpenAPI link, sitemap test); not a single-object detail. |
| `Publications` | `/publications/:catalogSlug` | `custom` | `PublicationsView` (`views/publications/PublicationIndex.vue`) | `:catalogSlug` route param drives the `/api/{catalogSlug}` catalog-aware endpoint via `catalogStore.fetchPublications()`. Loads publishing settings to switch between `PublicationTable` and the legacy list+detail layout. The manifest-renderer's `index` page assumes a flat `register` + `schema` config and doesn't yet route through a per-catalog endpoint. |
| `PublicationDetail` | `/publications/:catalogSlug/:id` | `custom` | `PublicationDetailView` (`views/publications/PublicationDetailPage.vue`) | Same `:catalogSlug` parameter flow as `Publications`. |
| `Search` | `/search` | `custom` | `SearchView` (`views/search/SearchIndex.vue`) | 467-LOC bespoke page — federated search across multiple catalogs, facets, save-search dialogs, separate `useSearchStore`, and a router-named `sidebar` view (`SearchSideBar.vue`) that the manifest cannot express in v1. |
| `Organizations` | `/organizations` | `custom` | `OrganizationsView` (`views/organizations/OrganizationIndex.vue`) | 240 LOC; bespoke organization import/copy/delete flow via `navigationStore`. |
| `Themes` | `/themes` | `custom` | `ThemesView` (`views/themes/ThemeIndex.vue`) | Uses lib's `useListView` + `CnIndexPage` already, but every row action (`viewObject` / `copyObject` / `deleteObject`) dispatches through `navigationStore.setModal` / `setDialog`. Migrating to `type: "index"` would silently drop those flows. |
| `ThemeDetail` | `/themes/:id` | `custom` | `ThemeDetailView` (`views/themes/ThemeDetailPage.vue`) | Same custom dialog flow; uses `EntityDetailPage` for the body. |
| `Glossary` | `/glossary` | `custom` | `GlossaryView` (`views/glossary/GlossaryIndex.vue`) | 244 LOC; tree-style display of terms, custom import flow. |
| `GlossaryDetail` | `/glossary/:id` | `custom` | `GlossaryDetailView` (`views/glossary/GlossaryDetailPage.vue`) | Same custom dialog flow. |
| `Pages` | `/pages` | `custom` | `PagesView` (`views/pages/PageIndex.vue`) | Same `useListView` + custom `navigationStore` dialog flow as Themes. |
| `PageDetail` | `/pages/:id` | `custom` | `PageDetailView` (`views/pages/PageDetailPage.vue`) | Same. |
| `Menus` | `/menus` | `custom` | `MenusView` (`views/menus/MenuIndex.vue`) | Same. |
| `MenuDetail` | `/menus/:id` | `custom` | `MenuDetailView` (`views/menus/MenuDetailPage.vue`) | Same. |
| `Directory` | `/directory` | **`index`** | `config: { register: "@resolve:listing_register", schema: "@resolve:listing_schema", columns: [...], sidebar: { enabled: true, showMetadata: true } }` | The Directory route is the federation listing — schema-backed, no bespoke create/edit modal (the listing is read-mostly, populated via the cron-backed `DirectorySync`). Tenant configuration drives which register/schema holds the listing entries via `listing_register` / `listing_schema` IAppConfig keys; `@resolve:` sentinels per ADR-024 hydrate at runtime. Safe to migrate. |

Final tally: **15 `custom` + 1 `index` = 16 main routes**.

The dashboard catch-all (`*` → `/`) preserved through the
`routesFromManifest()` helper.

## Menu mapping

`manifest.menu` mirrors the entries that `MainMenu.vue` renders
top-to-bottom. The dynamic per-catalog entries that MainMenu
generates from `objectStore.getCollection('catalog')` are NOT
expressible in the static manifest; they fall out of the v1 menu.
A follow-up change can wire the runtime-merge hook
(ADR-024 §4) so the backend `/api/manifest` endpoint returns
per-catalog menu items.

| Menu id | Label | Icon | Route | Section |
|---|---|---|---|---|
| `Dashboard` | "Dashboard" | `icon-category-dashboard` | `Dashboard` | main |
| `Catalogs` | "Catalogs" | `icon-folder` | `Catalogs` | main |
| `Search` | "Search" | `icon-search` | `Search` | main |
| `Documentation` | "Documentation" | `icon-info` | (href) | main |
| `Organizations` | "Organizations" | `icon-user` | `Organizations` | settings |
| `CatalogsSettings` | "Catalogs" | `icon-folder` | `Catalogs` | settings |
| `Glossary` | "Glossary" | `icon-quota` | `Glossary` | settings |
| `Themes` | "Themes" | `icon-category-customization` | `Themes` | settings |
| `Pages` | "Pages" | `icon-files` | `Pages` | settings |
| `Menus` | "Menus" | `icon-toggle-pictures` | `Menus` | settings |
| `Directory` | "Directory" | `icon-folder-shared` | `Directory` | settings |

(Vue-material-design icons referenced by `MainMenu.vue` map to the
nearest equivalent Nextcloud `icon-*` CSS class to stay vanilla
under `CnAppNav`. The lib's nav doesn't yet support a vue-material-
design icon registry; that's a future enhancement.)

## `@resolve:` usage

Per project memory + ADR-024 §3-4, multi-tenant deployments drive
register/schema slugs from `IAppConfig`. The manifest exposes these
via `@resolve:` sentinels that the lib hydrates at runtime from a
backend config endpoint (default
`/index.php/apps/{appId}/api/manifest` per ADR-024 §4) or from a
client-side fallback set by the consumer.

| Manifest path | Sentinel | Backing IAppConfig key |
|---|---|---|
| `pages[id=Directory].config.register` | `@resolve:listing_register` | `opencatalogi.listing_register` |
| `pages[id=Directory].config.schema` | `@resolve:listing_schema` | `opencatalogi.listing_schema` |

`listing_source` is **not** referenced from the manifest — the
Directory view doesn't surface it as a column; it's a backend-only
config value used by `DirectoryService` for federation.

When the runtime-merge hook is wired in a follow-up change, the
backend `/api/manifest` endpoint resolves the sentinels server-side
before the manifest is shipped to the client. Until then, the
`@resolve:` sentinels remain as opaque strings that the renderer
treats as the resolved register/schema slugs at runtime — the
fallback behaviour is benign because the Directory page renders an
empty list when the slugs don't resolve to a real schema (the
`useObjectStore` `fetchCollection` returns `{ results: [] }`).

## Custom-component registry

`src/customComponents.js` exports every page that retains
`type: "custom"`:

```javascript
import DashboardView from './views/dashboard/Dashboard.vue'
import CatalogsView from './views/catalogi/CatalogiIndex.vue'
import CatalogDetailView from './views/catalogi/CatalogDetailPage.vue'
import PublicationsView from './views/publications/PublicationIndex.vue'
import PublicationDetailView from './views/publications/PublicationDetailPage.vue'
import SearchView from './views/search/SearchIndex.vue'
import OrganizationsView from './views/organizations/OrganizationIndex.vue'
import ThemesView from './views/themes/ThemeIndex.vue'
import ThemeDetailView from './views/themes/ThemeDetailPage.vue'
import GlossaryView from './views/glossary/GlossaryIndex.vue'
import GlossaryDetailView from './views/glossary/GlossaryDetailPage.vue'
import PagesView from './views/pages/PageIndex.vue'
import PageDetailView from './views/pages/PageDetailPage.vue'
import MenusView from './views/menus/MenuIndex.vue'
import MenuDetailView from './views/menus/MenuDetailPage.vue'

export default {
  DashboardView, CatalogsView, CatalogDetailView,
  PublicationsView, PublicationDetailView, SearchView,
  OrganizationsView, ThemesView, ThemeDetailView,
  GlossaryView, GlossaryDetailView, PagesView,
  PageDetailView, MenusView, MenuDetailView,
}
```

Each entry has a one-line retention reason in the
"Per-page mapping" table above.

## Bootstrap shape

`main.js` after the rewrite mirrors decidesk's pattern:

```javascript
import {
  CnPageRenderer,
  defaultPageTypes,
  registerIcons,
  registerTranslations,
} from '@conduction/nextcloud-vue'
import bundledManifest from './manifest.json'
import customComponents from './customComponents.js'

// Shallow-clone — Vue.extend mutates the component def with `_Ctor`,
// which throws against frozen module-record exports.
const RoutePageRenderer = { ...CnPageRenderer }

function routesFromManifest(manifest) {
  return [
    ...manifest.pages.map((p) => ({
      name: p.id,
      path: p.route,
      component: RoutePageRenderer,
      props: p.route.includes(':'),
    })),
    { path: '*', redirect: '/' },
  ]
}

const router = new VueRouter({
  mode: 'history',
  base: '/index.php/apps/opencatalogi/',
  routes: routesFromManifest(bundledManifest),
})

new Vue({
  pinia, router,
  render: (h) => h(App, {
    props: {
      manifest: bundledManifest,
      customComponents: { ...customComponents },
      pageTypes: { ...defaultPageTypes },
    },
  }),
}).$mount('#content')
```

`App.vue` mounts `<CnAppRoot>` with the bundled manifest + the
shallow-cloned registry maps. The existing `objectSidebarState` +
`sidebarState` provide channels stay so `CnIndexSidebar` and
`CnObjectSidebar` keep functioning host-side; the `Modals` /
`Dialogs` / `UserSettings` host components live alongside
`<CnAppRoot>` rather than inside it.

## Mount-survivable boot

Decidesk's `50e4df7c` documents the issue: the standard Nextcloud
dev container's Apache config rewrites everything except the
JS/CSS allowlist to `index.php`, so `loadTranslations(...)` 404s
on the missing `/custom_apps/<app>/l10n/<locale>.json` request.
Wrapping the Vue mount inside the translation `.then()` callback
silently fails boot. The fix is to fire-and-forget the translation
load:

```javascript
function tryLoadTranslations() {
  try {
    const result = loadTranslations('opencatalogi', () => {})
    if (result && typeof result.then === 'function') {
      result.then(() => {}, () => {})
    }
  } catch { /* no-op */ }
}
```

`tryLoadTranslations()` runs unconditionally before `new Vue(...)`;
strings fall back to their English source on a translation miss.

## Files affected

New:
- `opencatalogi/src/manifest.json`
- `opencatalogi/src/customComponents.js`
- `opencatalogi/tests/validate-manifest.js`
- `opencatalogi/l10n/en_US.json` (mirror of `l10n/en.json`)

Modified:
- `opencatalogi/src/main.js` — manifest-driven router + shell
- `opencatalogi/src/App.vue` — `<CnAppRoot>` mount
- `opencatalogi/package.json` — `@conduction/nextcloud-vue`
  bumped from `^0.1.0-beta.18` to `^1.0.0-beta.12`
- `opencatalogi/webpack.config.js` — add `@nextcloud/axios$`
  exact-match alias
- `opencatalogi/appinfo/info.xml` — `<version>` 0.7.33 → 0.7.34

Deleted:
- `opencatalogi/src/router/index.js` — folded into `main.js`
- `opencatalogi/src/navigation/MainMenu.vue` — replaced by lib's
  `CnAppNav` driven from `manifest.menu`

Untouched (intentional):
- All 25 view files under `src/views/` — every retained custom
  page keeps its existing Vue file. Renaming or relocating any of
  them would risk breaking lazy imports / IDE indexing for the
  reviewer.
- `src/store/`, `src/services/`, `src/components/`,
  `src/dialogs/`, `src/modals/`, `src/sidebars/`, `src/composables/`,
  `src/entities/` — no schema changes, no API changes, no FE
  regression.
- `lib/` — backend untouched.
- `appinfo/routes.php` — backend routes unchanged.

## Cleanup follow-up

Deferred to subsequent changes (one per page family or one per
lib extension landing):

- **Migrate Themes / Pages / Menus / Glossary / Organizations to
  `type: "index"`** — these already use `useListView` + `CnIndexPage`
  internally. The blocker is the row-action dispatch into
  `navigationStore`. Once the lib's `actions[]` config supports
  dispatching to a consumer-provided handler, drop the custom
  components and let the renderer drive these pages.
- **Migrate Dashboard to `type: "dashboard"`** — Dashboard already
  uses `CnDashboardPage`; the bespoke widget content (donut chart,
  stats blocks, concept-publications list) is the blocker. Express
  each as a `widgetDef` once the lib's widget registry covers
  `chart-donut` / `chart-area` / `stats-block` / `entity-list`
  widget types.
- **Migrate Settings to `type: "settings"` with rich sections** —
  the 837-line nested admin UI maps to multiple `widgets[]` /
  `component` sections via `manifest-settings-rich-sections`.
  Defer until the per-section custom-component slot is exercised
  end-to-end on a smaller app.
- **Catalogs + Publications + CatalogDetail + PublicationDetail** —
  these have the most bespoke domain logic (catalog-aware endpoints,
  per-catalog menu, multi-stage publication flow). Defer to a
  dedicated change.
- **Backend `/api/manifest` endpoint** — drives runtime-merge of
  `@resolve:` sentinels and dynamic per-catalog menu entries.
- **Per-catalog menu entries** — paired with the runtime-merge hook;
  the backend endpoint emits one menu entry per catalog returned by
  `catalogStore.getCollection('catalog')`.
- **i18n translation key migration** — manifest entries currently
  use English strings as labels; per ADR-024 §6 they SHOULD be
  translation keys consumed by `t()`. The `App.vue` `translateForApp`
  prop is wired and ready to consume keys; switching the manifest's
  literal labels to `t('opencatalogi', '...')`-resolvable keys can
  happen incrementally without breaking renders.

## Risks (production-app reviewer focus)

| Risk | Mitigation |
|---|---|
| Boot fails when `loadTranslations` 404s under the dev-container Apache config | Fire-and-forget `tryLoadTranslations()` per decidesk `50e4df7c`; strings fall back to English source on miss. |
| `Vue.extend` throws "Cannot add property `_Ctor`" on the lib's frozen module-record `CnPageRenderer` / `defaultPageTypes` | Shallow-clone before passing to `vue-router` and `<CnAppRoot>` props (decidesk `866ff132`). |
| Webpack 5 fails to resolve `@nextcloud/axios` from the lib's CJS bundle (the package's `exports` field omits the `require` condition) | Add `@nextcloud/axios$` exact-match alias (decidesk `webpack.config.js` lines 79-81). |
| Per-catalog dynamic menu entries lost (MainMenu's `v-for catalogus in catalogs`) | Documented as deferred follow-up; users navigate via Catalogs → row click. No data loss; Catalogs index lists every catalog. |
| Search's named `sidebar` router view lost | The `SearchSideBar` is mounted inside `SearchView` itself (the `type: "custom"` component owns its own layout). v1 retains functional parity. |
| `defaultPageTypes` doesn't ship a `custom` dispatcher — every page uses `type: "custom"` and the renderer might reject the manifest at validation | The lib's spec explicitly notes `type: "custom"` resolves against the `customComponents` registry (per the v1.2.0 schema description); 15-of-16 customs is supported. |
| `CnAppNav` doesn't render vue-material-design icons; current MainMenu uses `<Plus>`, `<Finance>`, etc. | Switch the manifest menu to Nextcloud's `icon-*` CSS classes (vanilla under `NcAppNavigationItem`). Visual change but functional parity. |
| Settings page's 837-line nested UI behaves differently when wrapped by `CnPageRenderer` `custom` dispatcher than under `<router-view>` directly | Same Vue component, same mount tree depth (CnPageRenderer is a thin dispatcher); no template changes. Smoke test the Settings page after the bootstrap rewrite. |
| Bookmarks pointing at `/catalogi/<id>` etc. break if route names change | Every route name preserved exactly (Catalogs, CatalogDetail, Publications, PublicationDetail, Search, Organizations, Themes, ThemeDetail, Glossary, GlossaryDetail, Pages, PageDetail, Menus, MenuDetail, Directory, Dashboard). |

## Citations

- **Library schema (canonical)**:
  `@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`
  v1.2.0 (shipped in `1.0.0-beta.12`).
- **ADR-024**: `hydra/openspec/architecture/adr-024-app-manifest.md`
  — fleet-wide manifest convention.
- **Decidesk reference**:
  `decidesk/openspec/changes/decidesk-manifest-v1/` (Tier-4
  adoption); merged at PR
  [#160](https://github.com/ConductionNL/decidesk/pull/160).
- **Frozen-component fix**: decidesk commit `866ff132` (shallow-
  clone `defaultPageTypes` + `customComponents` before passing as
  props).
- **Mount-survivable boot**: decidesk commit `50e4df7c`
  (fire-and-forget `loadTranslations`).
- **Per-tenant slug resolution**: project memory entry
  `feedback_listing_register-schema-source.md` (IAppConfig keys
  `listing_register`, `listing_schema`, `listing_source`).

## Out of scope

- Migrating heavy bespoke pages to declarative built-ins (separate
  follow-up changes).
- Dynamic per-catalog menu entries (needs `/api/manifest`).
- Settings rich-sections rewrite (separate change).
- i18n key migration (incremental, deferred).
- Multi-tenancy / resolver consumer wiring beyond the Directory
  page's `@resolve:` sentinels.
