# Change: opencatalogi-adopt-or-abstractions

## Why

The OR-abstraction audit completed on 2026-05-03 (see
`.claude/audit-2026-05-03/00-executive-summary.md`) identified **opencatalogi
as the single largest cleanup target across the Conduction fleet**. It carries
the heaviest custom code load, the broadest set of NEEDS-REWRITE specs, the
most hardcoded register/schema lookups, and zero multi-language editing UI
despite shipping the publication catalogue product where translation matters
most.

This change is the per-app adoption ticket that lets opencatalogi consume the
shared OpenRegister, nextcloud-vue, and Hydra abstractions once those upstream
specs land. It is intentionally large — eight phases — because opencatalogi
sits at the apex of every audit stream:

- **Stream 1 (code cleanup):** `src/store/modules/object.js` is a 2 449-line
  bespoke object store reimplementing what `createObjectStore()` from
  `@conduction/nextcloud-vue` already provides. The audit estimates this is
  the **single biggest line-count reduction** available in the entire fleet
  (≈2 250 lines deleted).
- **Stream 2 (spec rewrite):** five capability specs — `file-management`,
  `admin-settings`, `search`, `dashboard`, `download-service` — are flagged
  NEEDS-REWRITE or MISSING-OR-DEP. Each describes bespoke functionality that
  OR or upstream Nextcloud already owns.
- **Stream 4 (hardcoded functionality):** five controllers each call
  `getValueString($appName, '<context>_register' | '<context>_schema', '')`
  with the empty-string fallback that hides misconfiguration. Three services
  hard-code retry/timeout/page-size constants. `SettingsService::MIN_OPENREGISTER_VERSION`
  duplicates `appinfo/info.xml` `<dependencies>`.
- **Stream R3 (i18n editing UI):** opencatalogi has **no** translation
  editing UI today. Pages, Menus, Publications, Themes, and Glossary all
  store user-facing text in a single language with no picker, no
  `Accept-Language` plumbing, and no `translatable: true` schema flags.
- **Stream R2 (multi-tenancy frontend):** once `nextcloud-vue@v0.x`
  ships `useTenantContext()` and `<CnTenantBadge>`, opencatalogi must wire
  them into `App.vue` and pass the organisation getter into every
  `createObjectStore()` instance.
- **Manifest adoption (ADR-024):** opencatalogi is the first **Tier 2-3**
  candidate — it has bespoke catalog/publication views that need
  `type: "custom"` in the manifest while the bulk of CRUD pages are
  generated. Ratifying the manifest convention here proves the manifest
  scales beyond the simple Tier 1 case.

Doing this cleanup as one large change (rather than eight small ones) is
deliberate: the controllers, store, specs, manifest, and i18n plumbing are
deeply coupled, and migrating them in one wave avoids partial states where
half the controllers consume `RegisterResolverService` while the other half
still call `getValueString()` directly.

## What Changes

### Phase 1: Adopt `RegisterResolverService` across all controllers

- Migrate **all five controllers** that today call
  `IAppConfig::getValueString($appName, '<context>_register' | '<context>_schema', '')`:
  - `lib/Controller/PublicationsController.php`
  - `lib/Controller/ListingsController.php` (lines 99-159)
  - `lib/Controller/CatalogiController.php` (line 117)
  - `lib/Controller/ThemesController.php` (line 114)
  - `lib/Controller/PagesController.php` (line 126)
- Replace each call site with
  `RegisterResolverService::resolveRegister($context)` /
  `resolveSchema($context)` / `resolvePair($context)`. Contexts:
  `publications`, `listings`, `catalogi`, `themes`, `pages` (plus the
  existing `glossary`, `menus`, `organisations` contexts that already use
  `getValueString` lookups).
- Removes the empty-string fallback that today hides misconfiguration: the
  resolver throws `RegisterNotConfiguredException` which the controller
  surfaces as a 503 with operator-actionable detail.
- Cite the OpenRegister change `register-resolver-service` for the contract.

### Phase 2: Migrate `src/store/modules/object.js` to `createObjectStore()`

- Delete the 2 449-line bespoke store and replace it with a thin wrapper
  around `createObjectStore('object', { plugins: [...] })` from
  `@conduction/nextcloud-vue`.
- Plugins: `filesPlugin()`, `auditTrailsPlugin()`, `relationsPlugin()`,
  `searchPlugin()` (covers the `relatedData: { logs, files, uses }` shape
  the current store hand-rolls).
- Net change: ~2 449 lines → ~200 lines (≈2 250 lines deleted, the largest
  single reduction in the audit).
- Update **every** consumer (`PublicationsList.vue`, `CatalogiList.vue`,
  `ThemesList.vue`, etc.) to call store actions through the standard
  Options-API helpers per the store-pattern guidance — no custom Pinia
  modules.

### Phase 3: Rewrite `file-management` spec; consume OR file APIs

- Rewrite `openspec/specs/file-management/spec.md` to consume:
  - OR's File Attachments capability (`x-openregister-file` schema
    annotation, `IFileService::attach/list/delete`).
  - `OCP\Share\IShareManager` for share creation rather than a bespoke
    sharing implementation.
- Drop the bespoke file CRUD/sharing endpoints in
  `lib/Controller/FilesController.php`; thin wrapper that delegates to OR.
- Cite OR's `register-resolver-service` (registers/schemas resolved before
  attaching files) and the OR file capability spec.

### Phase 4: i18n editing UI for translatable content

Today opencatalogi has **zero** multi-language editing UI. This phase
implements the editorial flow per ADR-025 and consumes the upstream
OR `i18n-source-of-truth` and `i18n-api-language-negotiation` capabilities.

- **Backend wiring** — wire `OCA\OpenRegister\Service\TranslationHandler`
  (or its public-facing equivalent once shipped) into:
  - `PagesController`
  - `MenusController`
  - `PublicationsController`
  - `ThemesController`
  - `GlossaryController`
  - so `Accept-Language` and `?_lang=` resolve translatable fields per
    `i18n-api-language-negotiation`.
- **Schema markup** — set `translatable: true` on user-facing string
  properties (title, summary, body, navigation labels) and declare
  `sourceLanguage` per ADR-025 across the affected schemas.
- **Frontend language selector** — add a language-picker component
  (consumed from nextcloud-vue) to:
  - `PublicationDetailPage.vue`
  - `ViewPageModal.vue`
  - `ViewMenuModal.vue`
  - `ViewThemeModal.vue`
  - `ViewGlossaryModal.vue`
  - so editors can author each translation per locale and the active
    `<html lang>` is wired through `loadState`.
- **Public read path** — public catalogue routes resolve the visitor's
  `Accept-Language` server-side so the SSR/initial-state markup matches
  the negotiated locale (no client-side flicker).

### Phase 5: Adopt nextcloud-vue multi-tenancy primitives

Once `nextcloud-vue@v0.x` ships the `multi-tenancy-context` capability
(see `nextcloud-vue/openspec/changes/multi-tenancy-context/`):

- Wire `useTenantContext()` into `App.vue::setup()`; expose the active
  organisation UUID as a reactive getter.
- Pass that getter to every `createObjectStore()` invocation as
  `organisationUuidGetter` so list queries scope to the active tenant.
- Render `<CnTenantBadge>` in the top bar (left of the user menu).
- Invalidate stores on tenant switch (`watch(tenantId, () => store.reset())`).
- Make `CnFormDialog` auto-fill the `organisation` field on object
  creation when the schema declares `organisation` as a relation.

### Phase 6: Adopt the app manifest convention (Tier 2-3)

Per `hydra/openspec/changes/adopt-app-manifest/` and ADR-024:

- Generate `src/manifest.json` from the existing router config (and
  `appinfo/routes.php` for nav metadata).
- Tier this app as **Tier 2-3** because the bespoke catalog landing
  pages, the publication renderer, and the public CMS view need
  `type: "custom"`. The bulk of admin CRUD entries can be `type: "list"`
  or `type: "detail"` and rendered by the manifest interpreter.
- Declare `dependencies: ["openregister"]` so the registry refuses to
  load the app when OR is missing or below the minimum version.
- Delete the hand-rolled router/sidebar code (`src/router/index.js`,
  custom `<NcAppNavigation>` blocks) once the manifest interpreter
  takes over.

### Phase 7: Spec rewrites for `search`, `admin-settings`, `dashboard`, `download-service`

- **`search`** (P2, MISSING-OR-DEP) — rewrite to cite OR's `zoeken-filteren`
  capability rather than describing bespoke search. Federated/cross-catalog
  search becomes a thin orchestrator on top of the shared search plugin.
- **`admin-settings`** (P2, NEEDS-REWRITE) — rewrite to cite OR's
  `IAppConfig` conventions (key naming, validation, secret handling) and
  remove the duplicated patterns this spec re-derives.
- **`dashboard`** (P2) — confirm and add the citation to the OR
  aggregations annotation; remove the hand-rolled aggregation prose.
- **`download-service`** (P2, NEEDS-REWRITE) — rewrite to consume OR's
  File Attachments + versioning capability for ZIP generation; the
  download service becomes a streaming wrapper, not a file CRUD owner.

### Phase 8: Hardcoded magic-number cleanup

Per `.claude/audit-2026-05-03/04-hardcoded.md`:

- `lib/Service/BroadcastService.php:68,75` — promote `MAX_RETRIES = 3`
  and `REQUEST_TIMEOUT = 30` to admin-config keys
  (`broadcast_max_retries`, `broadcast_request_timeout`).
- `lib/Service/SitemapService.php:40` — promote `MAX_PER_PAGE = 1000`
  to admin-config (`sitemap_max_per_page`).
- `lib/Service/SettingsService.php:64` — **delete**
  `MIN_OPENREGISTER_VERSION = '0.1.7'`. It duplicates
  `appinfo/info.xml` `<dependencies>` which Nextcloud already enforces.
- `openspec/specs/auto-publishing/spec.md` — rewrite publication state
  transitions to consume `x-openregister-lifecycle` from the schema
  rather than encoding the state machine in PHP.
- `openspec/specs/federation/spec.md` — rewrite per-app retry/backoff
  to consume the OR-level outbound webhook policy (instead of every
  app re-deriving its own retry maths).

## Impact

- **Affected specs (this app):**
  - **MODIFIED:** `file-management` (full rewrite),
    `admin-settings` (full rewrite), `download-service` (full rewrite),
    `search` (cite zoeken-filteren), `dashboard` (cite OR aggregations),
    `auto-publishing` (cite x-openregister-lifecycle),
    `federation` (cite OR webhook policy),
    `publications` (translatable fields, language negotiation),
    `catalogs`, `content-management` (manifest tiering, multi-tenancy).
  - **ADDED:** `opencatalogi-adopt-or-abstractions` (this change's delta —
    the per-app contract for adopting all upstream abstractions).
- **Affected code (informational; spec-only change):**
  - `lib/Controller/PublicationsController.php`
  - `lib/Controller/ListingsController.php`
  - `lib/Controller/CatalogiController.php`
  - `lib/Controller/ThemesController.php`
  - `lib/Controller/PagesController.php`
  - `lib/Controller/MenusController.php`
  - `lib/Controller/GlossaryController.php`
  - `lib/Controller/FilesController.php`
  - `lib/Service/BroadcastService.php`
  - `lib/Service/SitemapService.php`
  - `lib/Service/SettingsService.php`
  - `src/store/modules/object.js` (≈2 250 lines deleted)
  - `src/store/modules/search.js`
  - `src/App.vue`
  - `src/router/index.js` (deleted post-Phase 6)
  - `src/views/PublicationDetailPage.vue`
  - `src/modals/ViewPageModal.vue`, `ViewMenuModal.vue`,
    `ViewThemeModal.vue`, `ViewGlossaryModal.vue`
  - `src/manifest.json` (new)
- **Upstream dependencies (must land before opencatalogi can consume):**
  - `openregister/openspec/changes/register-resolver-service/`
  - `openregister/openspec/changes/pluggable-integration-registry/`
  - `openregister/openspec/changes/i18n-source-of-truth/`
  - `openregister/openspec/changes/i18n-api-language-negotiation/`
  - `nextcloud-vue/openspec/changes/multi-tenancy-context/`
  - `hydra/openspec/changes/adopt-app-manifest/`
- **ADRs (in `hydra/openspec/architecture/`):**
  - **ADR-022** — Pluggable integration registry
  - **ADR-024** — App manifest convention
  - **ADR-025** — i18n source of truth
- **Risk:** HIGH-IMPACT but spec-only at this stage. The risks live in the
  implementation phase tracked in `tasks.md`. Each phase is independently
  verifiable; the eight-phase ordering is chosen so each phase deletes
  code that the previous phase replaces, never the other way round.
- **Rollout:** sequential phase-by-phase. No phase ships until the
  upstream change it depends on is archived. Phase 1 ships first because
  it unblocks Phase 2 (the store needs the resolver to be present in
  controllers it calls). Phase 8 ships last because the magic-number
  cleanup is independent and has the lowest blast radius.
- **Audit trail:**
  - `.claude/audit-2026-05-03/01-code-cleanup.md` — Stream 1 line-count
    reductions; opencatalogi tops the list.
  - `.claude/audit-2026-05-03/02-spec-rewrite.md` — Stream 2 NEEDS-REWRITE
    list including the five opencatalogi specs.
  - `.claude/audit-2026-05-03/04-hardcoded.md` — Stream 4 hardcoded
    constants and `getValueString` empty-string fallbacks.
  - `.claude/audit-2026-05-03/research/R2-nc-vue-multitenancy.md` —
    Stream R2 multi-tenancy frontend gaps.
  - `.claude/audit-2026-05-03/research/R3-opencatalogi-i18n-editing.md` —
    Stream R3 i18n editing UI gaps (this app is the canonical example).
  - `.claude/audit-2026-05-03/research/R6-manifest-json.md` — manifest
    convention; opencatalogi is the named Tier 2-3 pilot.
