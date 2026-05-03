# Specification: opencatalogi-adopt-or-abstractions

## Purpose

Define the contract by which opencatalogi adopts the shared OpenRegister,
nextcloud-vue, and Hydra abstractions identified in the OR-abstraction
audit (`.claude/audit-2026-05-03/`). This spec is the per-app capability
that consumes — and never re-implements — the upstream specs:

- `openregister/openspec/changes/register-resolver-service/`
- `openregister/openspec/changes/pluggable-integration-registry/`
- `openregister/openspec/changes/i18n-source-of-truth/`
- `openregister/openspec/changes/i18n-api-language-negotiation/`
- `nextcloud-vue/openspec/changes/multi-tenancy-context/`
- `hydra/openspec/changes/adopt-app-manifest/`

Architectural decisions: **ADR-022** (pluggable integration registry),
**ADR-024** (app manifest convention), **ADR-025** (i18n source of
truth), all in `hydra/openspec/architecture/`.

## ADDED Requirements

### Requirement: opencatalogi controllers MUST resolve registers and schemas via `RegisterResolverService`

Every controller in `lib/Controller/` that today calls
`IAppConfig::getValueString($appName, '<context>_register' | '<context>_schema', '')`
MUST instead call `RegisterResolverService::resolveRegister($context)`,
`resolveSchema($context)`, or `resolvePair($context)` from the upstream
OpenRegister `register-resolver-service` capability.

The empty-string fallback pattern is forbidden — the resolver MUST throw
`RegisterNotConfiguredException` and the controller MUST surface a
`503 Service Unavailable` response with operator-actionable detail.

#### Scenario: PublicationsController resolves the publications context

- **GIVEN** the admin has configured `publications_register` and
  `publications_schema` via `IAppConfig`,
- **WHEN** any endpoint on `lib/Controller/PublicationsController.php`
  is invoked,
- **THEN** the controller calls
  `RegisterResolverService::resolvePair('publications')` and uses the
  resolved register/schema for the operation.

#### Scenario: ListingsController resolves the listings context

- **GIVEN** the admin has configured `listings_register` and
  `listings_schema`,
- **WHEN** an endpoint on `lib/Controller/ListingsController.php`
  (lines 99-159 in the audit) is invoked,
- **THEN** the controller calls
  `RegisterResolverService::resolvePair('listings')`.

#### Scenario: CatalogiController, ThemesController, PagesController resolve their contexts

- **WHEN** any endpoint on `lib/Controller/CatalogiController.php` (line
  117), `lib/Controller/ThemesController.php` (line 114), or
  `lib/Controller/PagesController.php` (line 126) is invoked,
- **THEN** the controller calls
  `RegisterResolverService::resolvePair('catalogi')`,
  `RegisterResolverService::resolvePair('themes')`, or
  `RegisterResolverService::resolvePair('pages')` respectively.

#### Scenario: missing configuration produces an actionable 503

- **GIVEN** an admin has not configured the register or schema for a
  given context,
- **WHEN** a request hits a controller that needs that context,
- **THEN** the resolver throws `RegisterNotConfiguredException`,
- **AND** the controller emits a `503 Service Unavailable` response
  whose body identifies the missing context and the configuration key
  the admin must set,
- **AND** the same response carries no register/schema data.

#### Scenario: resolver result is request-scoped

- **GIVEN** an admin updates `publications_register`,
- **WHEN** the next request arrives,
- **THEN** the controller resolves the new value without requiring an
  Apache restart, OPcache reset, or app reload.

### Requirement: opencatalogi MUST NOT re-implement `RegisterResolverService` locally

opencatalogi MUST treat `RegisterResolverService` as part of the
OpenRegister contract surface and MUST NOT re-implement, shim, or
locally cache the resolver — even when OR is below the minimum version.

#### Scenario: dependency check fails when OR is below minimum

- **GIVEN** the installed OpenRegister version is below the version
  that archives `register-resolver-service`,
- **WHEN** opencatalogi is loaded,
- **THEN** Nextcloud's app dependency check (driven by
  `appinfo/info.xml` `<dependencies>`) refuses to enable the app,
- **AND** the admin sees a clear message naming the required OR
  version.

### Requirement: opencatalogi MUST consume `createObjectStore()` for object state management

`src/store/modules/object.js` (currently 2 449 lines per audit) MUST
be replaced by a thin wrapper around `createObjectStore('object', {
plugins: [filesPlugin(), auditTrailsPlugin(), relationsPlugin(),
searchPlugin()] })` from `@conduction/nextcloud-vue`. opencatalogi MUST
NOT introduce custom Pinia modules for object state; the Options-API
helpers from nextcloud-vue are the only sanctioned access pattern.

#### Scenario: every list view consumes the shared store

- **GIVEN** any list component (`PublicationsList.vue`,
  `CatalogiList.vue`, `ThemesList.vue`, etc.),
- **WHEN** the component mounts,
- **THEN** it calls `useObjectStore('object')` from the wrapper,
- **AND** does NOT directly access bespoke Pinia state for object data.

#### Scenario: related-data plugins replace bespoke `relatedData` blocks

- **GIVEN** a publication is loaded via `useObjectStore('object').fetchOne(...)`,
- **WHEN** the consumer accesses logs, files, or "uses" relations,
- **THEN** those values come from the registered plugins
  (`auditTrailsPlugin`, `filesPlugin`, `relationsPlugin`),
- **AND** opencatalogi-side code does NOT hand-roll the corresponding
  fetch logic.

#### Scenario: pagination, filters, and search behave identically post-migration

- **GIVEN** a list view with pagination, filters, and a search query
  applied,
- **WHEN** the migrated `createObjectStore`-backed implementation
  replaces the bespoke store,
- **THEN** the user sees identical results, identical pagination
  metadata, and identical facet counts as before the migration,
- **AND** the existing E2E flows (create publication, edit, delete,
  restore from trash) continue to pass.

### Requirement: opencatalogi MUST consume the OR file APIs for file management

The `file-management` spec is rewritten to declare that opencatalogi
consumes OR's File Attachments capability via `x-openregister-file`
schema annotations and the OR-provided file service. Sharing is delegated
to `OCP\Share\IShareManager`. opencatalogi MUST NOT re-implement file
CRUD, share creation, share enumeration, or share revocation.

#### Scenario: file attachment goes through OR

- **GIVEN** a schema declares a property with `x-openregister-file`,
- **WHEN** a user uploads a file against an object backed by that schema,
- **THEN** opencatalogi calls into the OR file service
  (resolved via DI, not via a bespoke implementation),
- **AND** the resulting attachment is visible through OR's file APIs.

#### Scenario: share creation goes through `IShareManager`

- **GIVEN** a user requests a share on an attached file,
- **WHEN** opencatalogi handles the request,
- **THEN** it delegates to `OCP\Share\IShareManager::createShare()`,
- **AND** does NOT persist share rows into a local table.

#### Scenario: thin FilesController wrapper

- **WHEN** any endpoint on `lib/Controller/FilesController.php` is
  invoked,
- **THEN** it resolves the register/schema via
  `RegisterResolverService` and delegates the file operation to OR's
  file controller or service,
- **AND** does NOT contain bespoke storage, sharing, or versioning
  logic.

### Requirement: opencatalogi MUST honour API language negotiation for translatable content

Every controller serving translatable resources — `PagesController`,
`MenusController`, `PublicationsController`, `ThemesController`,
`GlossaryController` — MUST consume the OR `TranslationHandler` (or
its post-`i18n-source-of-truth` public equivalent) so that
`Accept-Language` and `?_lang=` resolve translatable fields per the
`i18n-api-language-negotiation` capability.

opencatalogi MUST NOT re-derive the negotiation algorithm; the
quality-factor parsing and RFC 4647 lookup matching are owned upstream.

#### Scenario: query parameter overrides Accept-Language

- **GIVEN** a request to `GET /api/publications/:id?_lang=fr`,
- **AND** the request also carries `Accept-Language: nl,en;q=0.9`,
- **WHEN** the controller resolves the response,
- **THEN** translatable fields are returned in `fr` (or fall back per
  the negotiation rules if `fr` is missing),
- **AND** the `Content-Language` header reflects the chosen locale.

#### Scenario: missing translation falls back to source language

- **GIVEN** a publication has `sourceLanguage: "nl"` and no `fr`
  translation,
- **WHEN** the request asks for `fr`,
- **THEN** the response returns the `nl` source content,
- **AND** the `Content-Language` header reflects `nl`,
- **AND** a `Vary: Accept-Language` header is present.

#### Scenario: public catalogue routes negotiate server-side

- **GIVEN** an unauthenticated visitor navigates to
  `/catalogs/<slug>`,
- **WHEN** the server renders the page,
- **THEN** the active locale is resolved server-side from the visitor's
  `Accept-Language` header,
- **AND** the SSR/initial-state markup reflects the chosen locale,
- **AND** there is no client-side flicker switching languages after
  hydration.

### Requirement: translatable schema properties MUST declare `translatable: true` and `sourceLanguage`

Every schema owning user-facing string content (publications, pages,
menus, themes, glossary entries, navigation labels) MUST mark the
relevant properties `translatable: true` and MUST declare
`sourceLanguage` per ADR-025.

#### Scenario: publication schema marks title and body translatable

- **GIVEN** the publication schema definition,
- **WHEN** the schema is loaded,
- **THEN** `title`, `summary`, and `body` are declared
  `translatable: true`,
- **AND** the schema declares `sourceLanguage: "nl"` (or the
  catalogue's configured source language).

#### Scenario: page schema and menu schema mark navigation labels translatable

- **GIVEN** the page schema and the menu schema,
- **WHEN** the schemas are loaded,
- **THEN** all navigation label and slug-display fields are declared
  `translatable: true`,
- **AND** each schema declares `sourceLanguage`.

#### Scenario: source language is immutable per object

- **GIVEN** an object exists with `sourceLanguage: "nl"`,
- **WHEN** an editor attempts to change `sourceLanguage` via PUT/PATCH,
- **THEN** the change is rejected with a 400 response,
- **AND** the object's source language remains `nl`.

### Requirement: editorial UI MUST allow translation authoring on every translatable detail/edit screen

opencatalogi MUST render a language-picker (consumed from
`@conduction/nextcloud-vue`) on every detail or edit screen that owns
translatable content: `PublicationDetailPage.vue`, `ViewPageModal.vue`,
`ViewMenuModal.vue`, `ViewThemeModal.vue`, `ViewGlossaryModal.vue`.

#### Scenario: editor switches language on a publication

- **GIVEN** an editor opens a publication detail page,
- **WHEN** the editor selects a different language from the picker,
- **THEN** the form fields refresh with that language's translation,
- **AND** an unsaved-changes warning fires if the editor switches mid-edit.

#### Scenario: editor adds a new translation

- **GIVEN** a publication has no `fr` translation,
- **WHEN** the editor selects `fr` from the picker,
- **THEN** the form shows empty translatable fields with a "+ add"
  affordance,
- **AND** saving creates the `fr` translation alongside the existing
  source.

#### Scenario: missing translation indicator on the picker

- **GIVEN** a publication has only `nl` (source) and `en` translations,
- **WHEN** the language picker is rendered,
- **THEN** other supported locales appear with a visual indicator
  showing the translation is missing.

### Requirement: opencatalogi MUST consume `useTenantContext()` from nextcloud-vue

Once `nextcloud-vue/openspec/changes/multi-tenancy-context/` is archived,
opencatalogi MUST call `useTenantContext()` from
`@conduction/nextcloud-vue` in `App.vue::setup()`, expose the active
organisation UUID as a reactive `organisationUuidGetter`, and pass that
getter into every `createObjectStore` invocation.

#### Scenario: store queries scope to the active tenant

- **GIVEN** the active tenant is `org-A`,
- **WHEN** any `createObjectStore`-backed list is fetched,
- **THEN** the request is scoped to `org-A` (the upstream nc-vue
  capability owns the wire format),
- **AND** results from other tenants are excluded.

#### Scenario: tenant badge is visible on every route

- **WHEN** any route in opencatalogi renders the top bar,
- **THEN** `<CnTenantBadge>` is present immediately to the left of the
  user menu.

#### Scenario: tenant switch resets stores

- **GIVEN** the user is viewing a list scoped to `org-A` on page 3 with
  filters applied,
- **WHEN** the user switches the active tenant to `org-B`,
- **THEN** every `createObjectStore` instance resets,
- **AND** pagination returns to page 1,
- **AND** the resulting list shows only `org-B` data.

#### Scenario: form dialog auto-fills the organisation field

- **GIVEN** a schema declares an `organisation` relation,
- **WHEN** the user opens a `CnFormDialog` to create a new object,
- **THEN** the `organisation` field is pre-filled with the active
  tenant UUID,
- **AND** the field is disabled unless the user has cross-tenant edit
  rights.

### Requirement: opencatalogi MUST ship `src/manifest.json` per the manifest convention (Tier 2-3)

Per ADR-024 and `hydra/openspec/changes/adopt-app-manifest/`,
opencatalogi MUST ship `src/manifest.json` as the single source of
truth for routes, navigation, and view types. opencatalogi declares
itself **Tier 2-3**: bespoke catalog landing pages, the publication
renderer, and the public CMS edit experience use `type: "custom"`;
all admin CRUD views use `type: "list"` or `type: "detail"` and are
rendered by the manifest interpreter.

The manifest MUST declare `dependencies: ["openregister"]` so the
registry refuses to load opencatalogi when OR is missing or below
the minimum version.

#### Scenario: hand-rolled router is removed

- **WHEN** the manifest interpreter is in place,
- **THEN** `src/router/index.js` no longer exists,
- **AND** custom `<NcAppNavigation>` blocks have been deleted,
- **AND** all navigation is rendered from the manifest.

#### Scenario: list and detail views are interpreted from the manifest

- **GIVEN** a manifest entry `{ "path": "/publications", "type":
  "list", "schema": "publications" }`,
- **WHEN** the route is visited,
- **THEN** the manifest interpreter renders the list view without any
  bespoke component code in opencatalogi.

#### Scenario: custom views remain in the codebase

- **GIVEN** a manifest entry `{ "path": "/catalogs/:slug", "type":
  "custom", "component": "CatalogLandingPage" }`,
- **WHEN** the route is visited,
- **THEN** the bespoke `CatalogLandingPage.vue` component renders,
- **AND** the manifest interpreter delegates layout responsibility to
  the component.

#### Scenario: manifest dependency check refuses load when OR is missing

- **GIVEN** OR is not installed (or is below the required version),
- **WHEN** Nextcloud attempts to load opencatalogi,
- **THEN** the manifest registry refuses with a clear error naming the
  missing dependency,
- **AND** the user sees an admin-actionable message.

### Requirement: search consumes OR `zoeken-filteren`

The rewritten `search` capability MUST cite OR's `zoeken-filteren` as
the primary search surface. Federated/cross-catalog search becomes a
thin orchestrator that fans out to multiple `zoeken-filteren` calls and
merges results. opencatalogi MUST NOT re-implement query parsing,
faceting, or ranking.

#### Scenario: single-catalog search delegates to OR

- **WHEN** a user issues a search query within a single catalog,
- **THEN** opencatalogi delegates to OR's `zoeken-filteren` API
  unmodified.

#### Scenario: cross-catalog search merges OR results

- **WHEN** a user issues a federated search across N catalogs,
- **THEN** opencatalogi makes N parallel `zoeken-filteren` calls,
- **AND** merges the results in a stable, documented order,
- **AND** does NOT alter individual ranking scores.

### Requirement: admin-settings cites OR's `IAppConfig` conventions

The rewritten `admin-settings` capability MUST cite OR's `IAppConfig`
conventions for key naming, validation, secret handling, and default
values. opencatalogi MUST NOT redefine these conventions locally.

#### Scenario: every admin-config key follows the OR naming convention

- **GIVEN** the admin-settings spec lists every config key opencatalogi
  reads or writes,
- **WHEN** any key is added or renamed,
- **THEN** the name follows the OR convention (snake_case, namespace
  prefix where applicable),
- **AND** the spec updates the inventory table in lockstep.

#### Scenario: secrets are stored per OR conventions

- **GIVEN** a setting carries a secret (token, credential, password),
- **WHEN** stored via `IAppConfig`,
- **THEN** the secret is marked sensitive per OR's convention so that
  it does not leak through generic settings dumps.

### Requirement: dashboard cites OR aggregations annotation

The `dashboard` spec MUST cite OR's aggregations annotation
(`x-openregister-aggregations` or its successor) as the source of
metrics. opencatalogi MUST NOT re-derive aggregation semantics in PHP.

#### Scenario: a dashboard widget is backed by an OR aggregation

- **GIVEN** a widget shows "publications by status",
- **WHEN** the widget loads,
- **THEN** it consumes the corresponding OR aggregation declared on the
  publications schema,
- **AND** does NOT compute the histogram in opencatalogi PHP.

### Requirement: download-service consumes OR file attachments + versioning

The rewritten `download-service` capability MUST consume OR's File
Attachments + versioning capability for ZIP generation. The download
service becomes a streaming wrapper that pipes OR file streams into a
ZIP — no local file CRUD, no bespoke versioning logic.

#### Scenario: ZIP generation streams from OR

- **GIVEN** a user requests a ZIP of all attachments on a publication,
- **WHEN** the download service handles the request,
- **THEN** it opens streams from OR's file service,
- **AND** pipes each stream into the ZIP without buffering the full
  contents in memory.

#### Scenario: versioned downloads honour OR's version selectors

- **GIVEN** a request for a specific version of an attached file,
- **WHEN** the download service handles the request,
- **THEN** it passes the version selector through to OR,
- **AND** does NOT maintain a separate version history.

### Requirement: `auto-publishing` consumes `x-openregister-lifecycle`

The rewritten `auto-publishing` capability MUST consume
`x-openregister-lifecycle` from the schema as the source of truth for
publication state transitions. opencatalogi MUST NOT encode the state
machine in PHP.

#### Scenario: publishing transitions read from the schema

- **GIVEN** a publication schema declares
  `x-openregister-lifecycle: { states: [draft, review, published,
  archived], transitions: [...] }`,
- **WHEN** opencatalogi processes a state change,
- **THEN** the allowed transitions and guards come from the schema
  declaration,
- **AND** PHP code in opencatalogi does NOT hold a duplicate state
  machine.

### Requirement: `federation` consumes the OR-level outbound webhook policy

The rewritten `federation` capability MUST consume the OR-level
outbound webhook policy for retries, backoff, and dead-letter
behaviour. opencatalogi MUST NOT re-derive retry maths.

#### Scenario: federation outbound calls follow OR's retry policy

- **GIVEN** a federation push fails transiently,
- **WHEN** the retry behaviour fires,
- **THEN** the schedule (count, delay, jitter, dead-letter) matches
  the OR policy,
- **AND** opencatalogi does NOT carry app-local retry constants.

### Requirement: hardcoded magic numbers MUST be promoted to admin-config or deleted

Per `.claude/audit-2026-05-03/04-hardcoded.md`:

- `lib/Service/BroadcastService.php:68,75` — `MAX_RETRIES = 3` and
  `REQUEST_TIMEOUT = 30` MUST be promoted to admin-config keys
  (`broadcast_max_retries`, `broadcast_request_timeout`); defaults
  preserved.
- `lib/Service/SitemapService.php:40` — `MAX_PER_PAGE = 1000` MUST
  be promoted to admin-config (`sitemap_max_per_page`); default
  preserved.
- `lib/Service/SettingsService.php:64` — `MIN_OPENREGISTER_VERSION
  = '0.1.7'` MUST be deleted; `appinfo/info.xml` `<dependencies>` is
  the only source of truth.

#### Scenario: broadcast retries are admin-tunable

- **GIVEN** an admin sets `broadcast_max_retries = 5`,
- **WHEN** a broadcast fails,
- **THEN** the service attempts up to 5 retries,
- **AND** does NOT rely on a PHP class constant.

#### Scenario: sitemap page size is admin-tunable

- **GIVEN** an admin sets `sitemap_max_per_page = 500`,
- **WHEN** the sitemap is generated,
- **THEN** each page contains at most 500 entries.

#### Scenario: minimum-OR-version constant no longer exists

- **WHEN** anyone greps `lib/Service/SettingsService.php` for
  `MIN_OPENREGISTER_VERSION`,
- **THEN** the constant is not found,
- **AND** the install-time dependency check (driven by `info.xml`)
  enforces the minimum version instead.

### Requirement: every new admin-config key MUST appear in the `admin-settings` inventory

Whenever Phase 8 (or any other phase) introduces an admin-config key,
the `admin-settings` spec inventory table MUST be updated in the same
spec change. The inventory is the single canonical list operators read.

#### Scenario: admin-settings inventory is the source of truth

- **WHEN** a reviewer audits the admin-settings spec,
- **THEN** they find every key opencatalogi reads or writes via
  `IAppConfig`, with default value, type, and a sentence describing
  effect,
- **AND** there are no keys in code that are missing from the table.

### Requirement: this change's phases MUST NOT ship before their upstream dependencies are archived

Each phase declares its upstream dependency in `tasks.md`. A phase
MUST NOT be implemented until the corresponding upstream openspec
change is archived in its source repository.

#### Scenario: Phase 1 waits for OR resolver

- **GIVEN** `openregister/openspec/changes/register-resolver-service/`
  is in proposal/draft,
- **WHEN** a contributor attempts to ship Phase 1 of this change,
- **THEN** the change MUST NOT land,
- **AND** the contributor waits for the upstream change to archive.

#### Scenario: Phase 8 has no upstream blocker

- **GIVEN** Phases 1-7 have upstream blockers,
- **WHEN** Phase 8 (magic-number cleanup) is ready,
- **THEN** Phase 8 MAY be implemented and shipped independently,
- **AND** does NOT block on the other phases.

## REMOVED Requirements

(none — this spec is purely additive within opencatalogi; the
**MODIFIED** specs that this change rewrites — `file-management`,
`admin-settings`, `download-service`, `search`, `dashboard`,
`auto-publishing`, `federation` — declare their own REMOVED
requirements in their respective spec deltas.)

## Glossary

- **Tier 2-3 (manifest tiering)** — per ADR-024: an app whose admin
  CRUD views are manifest-driven (`type: "list"` / `type: "detail"`)
  but which retains bespoke `type: "custom"` views for product-
  specific experiences. opencatalogi is the canonical Tier 2-3 pilot.
- **Translatable property** — a schema property declared
  `translatable: true` per ADR-025 whose value is resolved per-locale
  by the OR `TranslationHandler` on read.
- **Source language** — the locale stored as the canonical value of a
  translatable property; declared once per object via `sourceLanguage`
  and immutable thereafter.
- **organisationUuidGetter** — the reactive getter exposed by
  `useTenantContext()` (nextcloud-vue) that every `createObjectStore`
  consumes to scope queries to the active tenant.
- **RegisterResolverService** — the OpenRegister service exposed by
  `openregister/openspec/changes/register-resolver-service/` that
  replaces every `IAppConfig::getValueString($appName,
  '<context>_register' | '<context>_schema', '')` call site.

## References

- `proposal.md`, `tasks.md`, `design.md` (this change)
- `.claude/audit-2026-05-03/00-executive-summary.md`
- `.claude/audit-2026-05-03/01-code-cleanup.md` (Stream 1)
- `.claude/audit-2026-05-03/02-spec-rewrite.md` (Stream 2)
- `.claude/audit-2026-05-03/04-hardcoded.md` (Stream 4)
- `.claude/audit-2026-05-03/research/R2-nc-vue-multitenancy.md`
- `.claude/audit-2026-05-03/research/R3-opencatalogi-i18n-editing.md`
- `.claude/audit-2026-05-03/research/R6-manifest-json.md`
- `openregister/openspec/changes/register-resolver-service/`
- `openregister/openspec/changes/pluggable-integration-registry/`
- `openregister/openspec/changes/i18n-source-of-truth/`
- `openregister/openspec/changes/i18n-api-language-negotiation/`
- `nextcloud-vue/openspec/changes/multi-tenancy-context/`
- `hydra/openspec/changes/adopt-app-manifest/`
- `hydra/openspec/architecture/ADR-022-pluggable-integration-registry.md`
- `hydra/openspec/architecture/ADR-024-app-manifest-convention.md`
- `hydra/openspec/architecture/ADR-025-i18n-source-of-truth.md`
