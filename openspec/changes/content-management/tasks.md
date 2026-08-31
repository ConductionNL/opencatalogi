# Tasks: Content Management

## 0. Deduplication Check

- [ ] 0.1 Search `openspec/specs/` and `lib/Service/` for existing pages, menus, themes, and glossary implementations — document findings in PR description (no overlap expected; controllers and service calls already exist)
- [ ] 0.2 Verify `ObjectService::searchObjectsPaginated` covers all query patterns needed (slug filter for pages, database source for glossary) — no custom query builder may be introduced

## 1. Backend — Controllers

- [ ] 1.1 `PagesController::index()` — list all pages via `searchObjectsPaginated` using `IAppConfig` keys `page_schema` / `page_register`; annotate `@PublicPage @NoCSRFRequired @CORS`; add `@spec` tag linking to this tasks.md
- [ ] 1.2 `PagesController::show($slug)` — retrieve single page by slug using `searchObjectsPaginated` with `slug` filter and `_limit=1`; return 404 with `message` if not found
- [ ] 1.3 `MenusController::index()` — list all menus; fall back to schema ID `7` / register ID `1` when IAppConfig keys are absent
- [ ] 1.4 `MenusController::show($id)` — retrieve single menu by ID
- [ ] 1.5 `ThemesController::index()` — list all themes with facet unwrapping (promote `facets` key from nested result to top-level response)
- [ ] 1.6 `ThemesController::show($id)` — retrieve single theme by ID
- [ ] 1.7 `GlossaryController::index()` — list all glossary terms with `_source: database` and `published=false` in every query; include facet unwrapping
- [ ] 1.8 `GlossaryController::show($id)` — retrieve single glossary term by ID
- [ ] 1.9 Add OPTIONS action to each controller for CORS preflight; annotate `@PublicPage @NoCSRFRequired`

## 2. Backend — Routes

- [ ] 2.1 Register page routes in `appinfo/routes.php`: `GET /api/pages`, `GET /api/pages/{slug}` (`.+` pattern), `OPTIONS /api/pages`, `OPTIONS /api/pages/{slug}`; specific slug routes MUST appear BEFORE any wildcard `{slug}` routes
- [ ] 2.2 Register menu routes: `GET /api/menus`, `GET /api/menus/{id}`, `OPTIONS /api/menus`, `OPTIONS /api/menus/{id}`
- [ ] 2.3 Register theme routes: `GET /api/themes`, `GET /api/themes/{id}`, `OPTIONS /api/themes`, `OPTIONS /api/themes/{id}`
- [ ] 2.4 Register glossary routes: `GET /api/glossary`, `GET /api/glossary/{id}`, `OPTIONS /api/glossary`, `OPTIONS /api/glossary/{id}`

## 3. Backend — Configuration

- [ ] 3.1 Verify IAppConfig keys are read (not written) by controllers: `page_schema`, `page_register`, `menu_schema`, `menu_register`, `theme_schema`, `theme_register`, `glossary_schema`, `glossary_register`
- [ ] 3.2 Add admin settings UI fields for the eight config keys in the existing settings section (or verify they already exist)

## 4. Seed Data

- [ ] 4.1 Add Page seed objects (4 objects: `home`, `over-ons`, `contact`, `inloggen`) to `lib/Settings/opencatalogi_register.json` using `@self` envelope with `register: cms`, `schema: page`
- [ ] 4.2 Add Menu seed objects (3 objects: `hoofdmenu`, `voettermenu`, `accountmenu`) with nested items
- [ ] 4.3 Add Theme seed objects (4 objects: `woo-verzoeken`, `vergunningen`, `beleidsstukken`, `open-data`)
- [ ] 4.4 Add Glossary seed objects (5 objects: `woo`, `bestuursorgaan`, `weigeringsgrond`, `inventarislijst`, `cors`)
- [ ] 4.5 Verify `importFromApp()` is idempotent for all new seed objects — re-import MUST NOT create duplicates

## 5. Frontend — Admin Index Views

- [ ] 5.1 `PageIndex.vue` — list view at `/pages` using `CnIndexPage` + `useListView`; row click opens `ViewPageModal`
- [ ] 5.2 `MenuIndex.vue` — list view at `/menus`; row click opens `ViewMenuModal`
- [ ] 5.3 `ThemeIndex.vue` — list view at `/themes`; row click opens `ThemeModal` (edit) or `ViewThemeModal` (view)
- [ ] 5.4 `GlossaryIndex.vue` — list view at `/glossary`; row click opens `GlossaryModal` or `ViewGlossaryModal`
- [ ] 5.5 Register all four routes in `router/index.js`; add navigation items to `MainMenu.vue`

## 6. Frontend — Modals and Forms

- [ ] 6.1 `ViewPageModal.vue` — read-only view of page contents blocks (render `type` + `data` fields)
- [ ] 6.2 `PageContentForm.vue` — edit page content blocks; use `CnAdvancedFormDialog` or `CnFormDialog`
- [ ] 6.3 `ViewMenuModal.vue` — read-only hierarchical tree of menu items
- [ ] 6.4 `MenuItemForm.vue` — edit/add menu items with nested items support
- [ ] 6.5 `ThemeModal.vue` — create/edit theme; use `CnFormDialog` with theme schema fields
- [ ] 6.6 `ViewThemeModal.vue` — read-only theme detail with image, icon, and link preview
- [ ] 6.7 `GlossaryModal.vue` — create/edit glossary term
- [ ] 6.8 `ViewGlossaryModal.vue` — read-only glossary term detail
- [ ] 6.9 Each modal MUST live in its own `.vue` file under `src/modals/` — NO inline modal markup in parent components (ADR-004)

## 7. Frontend — Stores

- [ ] 7.1 Create `src/store/modules/pages.js` using `createObjectStore('pages')` with lifecycle and files plugins
- [ ] 7.2 Create `src/store/modules/menus.js` using `createObjectStore('menus')`
- [ ] 7.3 Create `src/store/modules/themes.js` using `createObjectStore('themes')`
- [ ] 7.4 Create `src/store/modules/glossary.js` using `createObjectStore('glossary')`
- [ ] 7.5 Register all four stores in `store/store.js`; call `objectStore.registerObjectType()` for each in `initializeStores()`

## 8. Internationalization (ADR-007 / CLAUDE.md)

- [ ] 8.1 Run `node scripts/l10n-ai.js list-locales` to confirm active locales (expect `en`, `nl`)
- [ ] 8.2 Add l10n keys for all new UI labels in Pages, Menus, Themes, and Glossary views/modals using `node scripts/l10n-ai.js add` with values for both `en` and `nl`
- [ ] 8.3 Wrap all user-visible strings in `t('opencatalogi', '...')` — no hardcoded strings in Vue templates (ADR-004)
- [ ] 8.4 Run `npm run check:l10n` — MUST report zero MISSING and zero UNWRAPPED

## 9. Unit Tests (ADR-008)

- [ ] 9.1 Test `PagesController::show()` — page found by slug, page not found (404), CORS headers present
- [ ] 9.2 Test `MenusController::index()` — IAppConfig keys present, IAppConfig keys absent (fallback to schema 7 / register 1)
- [ ] 9.3 Test `ThemesController::index()` — facet unwrapping when `facets` key is nested vs. absent
- [ ] 9.4 Test `GlossaryController::index()` — `_source: database` and `published=false` always present in query

## 10. Documentation (ADR-009)

- [ ] 10.1 Feature documentation at `docs/features/content-management.md` — describe each content type, its API, and IAppConfig keys
- [ ] 10.2 Update `docs/api/` with endpoint reference for pages, menus, themes, and glossary endpoints

## 11. Verification

- [ ] 11.1 Test all public endpoints return CORS headers: `curl -I -X OPTIONS http://localhost/api/pages`
- [ ] 11.2 Verify `GET /api/glossary` includes `_source: database` in the ObjectService call (check logs or unit test)
- [ ] 11.3 Verify `GET /api/menus` falls back correctly when IAppConfig keys are unset
- [ ] 11.4 Verify tilburg-woo-ui can consume all four endpoints without CORS errors
- [ ] 11.5 `npm run check:l10n` — MUST be clean
