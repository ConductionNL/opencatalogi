# Tasks: Content Management

> **Superseded, struck 24 Nov 2026.** Pages and menus left this app in #1421 (4 Sep 2026, "opencatalogi no longer has CMS functionality"): Portaliq serves them at `/api/content/pages` and `/api/content/menus`, and the controllers, routes, schemas, manifest pages and frontend files are gone. Themes and glossary stayed and were rebuilt under later changes with their own requirements and tests (`ThemesControllerTest`, `GlossaryControllerTest`, `FeaturedThemesTest`, `ThemePublicationsTest`): they answer 503 when unconfigured instead of falling back to schema 7 / register 1, and scope every read through RBAC instead of `_source: database` with `published=false`. Every open task below either builds what #1421 removed or restates behaviour that later changes replaced, so none is built. Archived 24 Nov 2026 without a spec sync (decision: a superseded change leaves openspec/changes, its delta spec is not folded in): syncing this delta spec would restore requirements #1421 overruled.

## 0. Deduplication Check

- [x] ~~0.1 Search `openspec/specs/` and `lib/Service/` for existing pages, menus, themes, and glossary implementations — document findings in PR description (no overlap expected; controllers and service calls already exist)~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~0.2 Verify `ObjectService::searchObjectsPaginated` covers all query patterns needed (slug filter for pages, database source for glossary) — no custom query builder may be introduced~~ Struck 24 Nov 2026: superseded, see the note at the top.

## 1. Backend — Controllers

- [x] ~~1.1 `PagesController::index()` — list all pages via `searchObjectsPaginated` using `IAppConfig` keys `page_schema` / `page_register`; annotate `@PublicPage @NoCSRFRequired @CORS`; add `@spec` tag linking to this tasks.md~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~1.2 `PagesController::show($slug)` — retrieve single page by slug using `searchObjectsPaginated` with `slug` filter and `_limit=1`; return 404 with `message` if not found~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~1.3 `MenusController::index()` — list all menus; fall back to schema ID `7` / register ID `1` when IAppConfig keys are absent~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~1.4 `MenusController::show($id)` — retrieve single menu by ID~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~1.5 `ThemesController::index()` — list all themes with facet unwrapping (promote `facets` key from nested result to top-level response)~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~1.6 `ThemesController::show($id)` — retrieve single theme by ID~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~1.7 `GlossaryController::index()` — list all glossary terms with `_source: database` and `published=false` in every query; include facet unwrapping~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~1.8 `GlossaryController::show($id)` — retrieve single glossary term by ID~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~1.9 Add OPTIONS action to each controller for CORS preflight; annotate `@PublicPage @NoCSRFRequired`~~ Struck 24 Nov 2026: superseded, see the note at the top.

## 2. Backend — Routes

- [x] ~~2.1 Register page routes in `appinfo/routes.php`: `GET /api/pages`, `GET /api/pages/{slug}` (`.+` pattern), `OPTIONS /api/pages`, `OPTIONS /api/pages/{slug}`; specific slug routes MUST appear BEFORE any wildcard `{slug}` routes~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~2.2 Register menu routes: `GET /api/menus`, `GET /api/menus/{id}`, `OPTIONS /api/menus`, `OPTIONS /api/menus/{id}`~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~2.3 Register theme routes: `GET /api/themes`, `GET /api/themes/{id}`, `OPTIONS /api/themes`, `OPTIONS /api/themes/{id}`~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~2.4 Register glossary routes: `GET /api/glossary`, `GET /api/glossary/{id}`, `OPTIONS /api/glossary`, `OPTIONS /api/glossary/{id}`~~ Struck 24 Nov 2026: superseded, see the note at the top.

## 3. Backend — Configuration

- [x] ~~3.1 Verify IAppConfig keys are read (not written) by controllers: `page_schema`, `page_register`, `menu_schema`, `menu_register`, `theme_schema`, `theme_register`, `glossary_schema`, `glossary_register`~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~3.2 Add admin settings UI fields for the eight config keys in the existing settings section (or verify they already exist)~~ Struck 24 Nov 2026: superseded, see the note at the top.

## 4. Seed Data

- [x] ~~4.1 Add Page seed objects (4 objects: `home`, `over-ons`, `contact`, `inloggen`) to `lib/Settings/opencatalogi_register.json` using `@self` envelope with `register: cms`, `schema: page`~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~4.2 Add Menu seed objects (3 objects: `hoofdmenu`, `voettermenu`, `accountmenu`) with nested items~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~4.3 Add Theme seed objects (4 objects: `woo-verzoeken`, `vergunningen`, `beleidsstukken`, `open-data`)~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~4.4 Add Glossary seed objects (5 objects: `woo`, `bestuursorgaan`, `weigeringsgrond`, `inventarislijst`, `cors`)~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~4.5 Verify `importFromApp()` is idempotent for all new seed objects — re-import MUST NOT create duplicates~~ Struck 24 Nov 2026: superseded, see the note at the top.

## 5. Frontend — Admin Index Views

- [x] ~~5.1 `PageIndex.vue` — list view at `/pages` using `CnIndexPage` + `useListView`; row click opens `ViewPageModal`~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~5.2 `MenuIndex.vue` — list view at `/menus`; row click opens `ViewMenuModal`~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~5.3 `ThemeIndex.vue` — list view at `/themes`; row click opens `ThemeModal` (edit) or `ViewThemeModal` (view)~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~5.4 `GlossaryIndex.vue` — list view at `/glossary`; row click opens `GlossaryModal` or `ViewGlossaryModal`~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~5.5 Register all four routes in `router/index.js`; add navigation items to `MainMenu.vue`~~ Struck 24 Nov 2026: superseded, see the note at the top.

## 6. Frontend — Modals and Forms

- [x] ~~6.1 `ViewPageModal.vue` — read-only view of page contents blocks (render `type` + `data` fields)~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~6.2 `PageContentForm.vue` — edit page content blocks; use `CnAdvancedFormDialog` or `CnFormDialog`~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~6.3 `ViewMenuModal.vue` — read-only hierarchical tree of menu items~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~6.4 `MenuItemForm.vue` — edit/add menu items with nested items support~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~6.5 `ThemeModal.vue` — create/edit theme; use `CnFormDialog` with theme schema fields~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~6.6 `ViewThemeModal.vue` — read-only theme detail with image, icon, and link preview~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~6.7 `GlossaryModal.vue` — create/edit glossary term~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~6.8 `ViewGlossaryModal.vue` — read-only glossary term detail~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~6.9 Each modal MUST live in its own `.vue` file under `src/modals/` — NO inline modal markup in parent components (ADR-004)~~ Struck 24 Nov 2026: superseded, see the note at the top.

## 7. Frontend — Stores

- [x] ~~7.1 Create `src/store/modules/pages.js` using `createObjectStore('pages')` with lifecycle and files plugins~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~7.2 Create `src/store/modules/menus.js` using `createObjectStore('menus')`~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~7.3 Create `src/store/modules/themes.js` using `createObjectStore('themes')`~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~7.4 Create `src/store/modules/glossary.js` using `createObjectStore('glossary')`~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~7.5 Register all four stores in `store/store.js`; call `objectStore.registerObjectType()` for each in `initializeStores()`~~ Struck 24 Nov 2026: superseded, see the note at the top.

## 8. Internationalization (ADR-007 / CLAUDE.md)

- [x] ~~8.1 Run `node scripts/l10n-ai.js list-locales` to confirm active locales (expect `en`, `nl`)~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~8.2 Add l10n keys for all new UI labels in Pages, Menus, Themes, and Glossary views/modals using `node scripts/l10n-ai.js add` with values for both `en` and `nl`~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~8.3 Wrap all user-visible strings in `t('opencatalogi', '...')` — no hardcoded strings in Vue templates (ADR-004)~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~8.4 Run `npm run check:l10n` — MUST report zero MISSING and zero UNWRAPPED~~ Struck 24 Nov 2026: superseded, see the note at the top.

## 9. Unit Tests (ADR-008)

- [x] ~~9.1 Test `PagesController::show()` — page found by slug, page not found (404), CORS headers present~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~9.2 Test `MenusController::index()` — IAppConfig keys present, IAppConfig keys absent (fallback to schema 7 / register 1)~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~9.3 Test `ThemesController::index()` — facet unwrapping when `facets` key is nested vs. absent~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~9.4 Test `GlossaryController::index()` — `_source: database` and `published=false` always present in query~~ Struck 24 Nov 2026: superseded, see the note at the top.

## 10. Documentation (ADR-009)

- [x] ~~10.1 Feature documentation at `docs/features/content-management.md` — describe each content type, its API, and IAppConfig keys~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~10.2 Update `docs/api/` with endpoint reference for pages, menus, themes, and glossary endpoints~~ Struck 24 Nov 2026: superseded, see the note at the top.

## 11. Verification

- [x] ~~11.1 Test all public endpoints return CORS headers: `curl -I -X OPTIONS http://localhost/api/pages`~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~11.2 Verify `GET /api/glossary` includes `_source: database` in the ObjectService call (check logs or unit test)~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~11.3 Verify `GET /api/menus` falls back correctly when IAppConfig keys are unset~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~11.4 Verify tilburg-woo-ui can consume all four endpoints without CORS errors~~ Struck 24 Nov 2026: superseded, see the note at the top.
- [x] ~~11.5 `npm run check:l10n` — MUST be clean~~ Struck 24 Nov 2026: superseded, see the note at the top.
