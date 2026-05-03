# Tasks — opencatalogi-adopt-or-abstractions

> Spec-only change. Each phase below is broken into spec-authoring tasks.
> Code changes happen in follow-up implementation changes once the spec
> deltas are archived. Phases are ordered so later phases depend on
> earlier phases' contracts being agreed.

## Phase 1: Adopt `RegisterResolverService` across all controllers

- [ ] 1.1 Document the five canonical contexts (`publications`,
      `listings`, `catalogi`, `themes`, `pages`) plus the three already-
      relying-on-`getValueString` contexts (`glossary`, `menus`,
      `organisations`) in `specs/opencatalogi-adopt-or-abstractions/spec.md`.
- [ ] 1.2 Add a requirement that **every** controller in `lib/Controller/`
      that today calls `IAppConfig::getValueString($appName,
      '<context>_register' | '<context>_schema', '')` must instead call
      `RegisterResolverService::resolveRegister($context)` /
      `resolveSchema($context)` / `resolvePair($context)`.
- [ ] 1.3 Cross-reference each call site by file and line in the spec
      (matches `.claude/audit-2026-05-03/04-hardcoded.md`):
      `PublicationsController.php`, `ListingsController.php:99-159`,
      `CatalogiController.php:117`, `ThemesController.php:114`,
      `PagesController.php:126`.
- [ ] 1.4 Specify that the empty-string fallback (`getValueString(..., '')`)
      is forbidden going forward — `RegisterResolverService` must throw
      `RegisterNotConfiguredException` and the controller must surface
      a `503 Service Unavailable` with operator-actionable detail (which
      context, which key, where to set it).
- [ ] 1.5 Specify that the resolver result is request-scoped (no module
      static caching) so admin re-configuration is picked up on the next
      request without `apache2ctl graceful`.
- [ ] 1.6 Cite OpenRegister `register-resolver-service` change as the
      upstream dependency; capture the minimum `openregister` version
      (the version that archives `register-resolver-service`) in
      `appinfo/info.xml` `<dependencies>`.
- [ ] 1.7 Add a SHALL: opencatalogi MUST NOT re-implement
      `RegisterResolverService` locally even if OR is below the required
      version — the app must refuse to load via the dependency check.

## Phase 2: Migrate `src/store/modules/object.js` to `createObjectStore()`

- [ ] 2.1 Add a requirement to delete `src/store/modules/object.js`
      (currently 2 449 lines per audit) and replace with a thin wrapper
      around `createObjectStore('object', { plugins: [...] })` from
      `@conduction/nextcloud-vue`.
- [ ] 2.2 Document the required plugin list: `filesPlugin()`,
      `auditTrailsPlugin()`, `relationsPlugin()`, `searchPlugin()` —
      these collectively cover the `relatedData: { logs, files, uses }`
      shape that the bespoke store hand-rolls today.
- [ ] 2.3 Forbid custom Pinia modules per the store-pattern guidance
      in user memory (`feedback_store-pattern.md`): use the Options-API
      helpers and `createObjectStore`, no bespoke Pinia state for objects.
- [ ] 2.4 Specify the migration of every consumer component (lists +
      details + modals): consumer components call store actions through
      the standard helpers (`useObjectStore('object')`) — no direct
      access to internal store state.
- [ ] 2.5 Specify that pagination, filters, and faceted search behaviour
      MUST remain functionally identical from the user's perspective
      across the migration. Capture before/after smoke-test list in
      `design.md`.
- [ ] 2.6 Document the same migration path for `src/store/modules/search.js`:
      either consume `searchPlugin()` from nextcloud-vue or extract a
      shared search store. Decide in `design.md` and reference the
      decision here.
- [ ] 2.7 Capture the expected line-count reduction (≈2 250 lines deleted)
      as a non-functional acceptance criterion so reviewers can spot
      partial migrations that leave dead code behind.
- [ ] 2.8 Add a regression-test requirement: the existing object E2E
      flows (create publication, edit, delete, restore from trash) must
      continue to pass.

## Phase 3: Rewrite `file-management` spec; consume OR file APIs

- [ ] 3.1 Rewrite `openspec/specs/file-management/spec.md` end-to-end —
      mark the existing requirements as REMOVED and replace with a
      single requirement: opencatalogi MUST consume OR's File
      Attachments capability via `x-openregister-file` schema annotations
      and `IFileService` (or its post-resolver-service equivalent).
- [ ] 3.2 Specify that `OCP\Share\IShareManager` is the sharing surface;
      opencatalogi MUST NOT re-implement share creation, share
      enumeration, or share revocation locally.
- [ ] 3.3 Specify that `lib/Controller/FilesController.php` becomes a
      thin wrapper that delegates to OR's file controller; removing
      bespoke endpoints. Capture each removed endpoint in the spec
      so the API consumers know the migration path.
- [ ] 3.4 Cross-reference the upstream OR file capability spec by name
      and the `register-resolver-service` change for context resolution
      before file operations.
- [ ] 3.5 Document the impact on existing public catalogue downloads
      (Phase 7 picks up `download-service`) so reviewers can see the
      two-phase ordering is intentional.

## Phase 4: i18n editing UI for translatable content

- [ ] 4.1 List the five controllers that MUST consume the
      `TranslationHandler` (or the post-`i18n-source-of-truth` public
      handler):
      `PagesController`, `MenusController`, `PublicationsController`,
      `ThemesController`, `GlossaryController`.
- [ ] 4.2 Specify that `Accept-Language` and `?_lang=` MUST resolve
      translatable fields per `i18n-api-language-negotiation` — list the
      negotiation order (query param > header > user preference >
      app default).
- [ ] 4.3 Add schema-marking requirements: every user-facing string
      property (titles, summaries, body content, navigation labels)
      MUST set `translatable: true` and the schema MUST declare
      `sourceLanguage` per ADR-025.
- [ ] 4.4 Catalogue every schema/property that needs `translatable: true`
      in a table in `specs/.../spec.md` so the implementation cannot
      miss a field. Source: `.claude/audit-2026-05-03/research/R3-opencatalogi-i18n-editing.md`.
- [ ] 4.5 Specify the language-selector UI consumed from
      `@conduction/nextcloud-vue`. List target screens:
      `PublicationDetailPage.vue`, `ViewPageModal.vue`,
      `ViewMenuModal.vue`, `ViewThemeModal.vue`, `ViewGlossaryModal.vue`.
- [ ] 4.6 Specify the editorial rules (per ADR-025): the source
      language is required and immutable per object; translations are
      optional; missing translations fall back to the source per the
      negotiation rules.
- [ ] 4.7 Specify the public read path: catalogue routes must resolve
      the visitor's `Accept-Language` server-side so the SSR/initial-state
      markup matches the negotiated locale (no client-side flicker).
- [ ] 4.8 Cite `openregister/openspec/changes/i18n-source-of-truth/`
      and `openregister/openspec/changes/i18n-api-language-negotiation/`
      as the upstream dependencies.
- [ ] 4.9 Note that `Accept-Language` quality factors and
      RFC 4647 lookup matching are OWNED by the OR upstream spec —
      this app MUST NOT re-derive the negotiation algorithm.

## Phase 5: Adopt nextcloud-vue multi-tenancy primitives

- [ ] 5.1 Specify that `App.vue::setup()` MUST call `useTenantContext()`
      from `@conduction/nextcloud-vue` once `multi-tenancy-context` is
      archived; expose the active organisation UUID as a reactive
      getter (`organisationUuidGetter`).
- [ ] 5.2 Specify that **every** `createObjectStore()` invocation MUST
      receive the `organisationUuidGetter` so list queries scope to
      the active tenant. Audit covers existing stores in
      `src/store/modules/`.
- [ ] 5.3 Specify the `<CnTenantBadge>` placement: top bar, left of
      the user menu, visible on every route.
- [ ] 5.4 Specify cache-invalidation behaviour: switching tenant must
      reset all `createObjectStore` instances (`watch(tenantId, () =>
      store.reset())`); pagination resets to page 1; active filters are
      cleared (or scoped per tenant — decide in `design.md`).
- [ ] 5.5 Specify `CnFormDialog` auto-fill: when a schema declares an
      `organisation` relation, the dialog MUST pre-fill the active
      tenant's UUID and disable the field unless the user has
      cross-tenant edit rights.
- [ ] 5.6 Cite `nextcloud-vue/openspec/changes/multi-tenancy-context/`
      as the upstream dependency. Cite
      `.claude/audit-2026-05-03/research/R2-nc-vue-multitenancy.md`.

## Phase 6: Adopt the app manifest convention (Tier 2-3)

- [ ] 6.1 Specify that opencatalogi MUST ship `src/manifest.json` per
      ADR-024 and `hydra/openspec/changes/adopt-app-manifest/`.
- [ ] 6.2 Tier the app as **Tier 2-3**: bespoke catalog landing pages,
      the publication renderer, and the public CMS view declare
      `type: "custom"`; all admin CRUD entries declare `type: "list"`
      / `type: "detail"` and are rendered by the manifest interpreter.
- [ ] 6.3 Catalogue every existing route in
      `src/router/index.js` and tag it as `list` / `detail` /
      `custom` in a table in `specs/.../spec.md`. The implementation
      cannot ship if a route is missing from the table.
- [ ] 6.4 Specify `dependencies: ["openregister"]` in the manifest so
      the registry refuses to load the app when OR is missing or below
      the minimum version.
- [ ] 6.5 Specify the deletion of hand-rolled router/sidebar code
      (`src/router/index.js`, custom `<NcAppNavigation>` blocks)
      once the manifest interpreter takes over. Capture this as a
      "MUST NOT exist" requirement so the cleanup isn't forgotten.
- [ ] 6.6 Specify that `appinfo/routes.php` is the source of truth for
      backend routes; the manifest references controller names, not
      paths, so changing a backend route doesn't require a manifest
      bump.
- [ ] 6.7 Cite `hydra/openspec/changes/adopt-app-manifest/`,
      ADR-024, and `.claude/audit-2026-05-03/research/R6-manifest-json.md`
      (which names opencatalogi as the Tier 2-3 pilot).

## Phase 7: Spec rewrites for `search`, `admin-settings`, `dashboard`, `download-service`

- [ ] 7.1 **`search`** (P2, MISSING-OR-DEP) — rewrite
      `openspec/specs/search/spec.md` to cite OR's `zoeken-filteren`
      capability as the primary surface. Federated/cross-catalog search
      becomes a thin orchestrator that fans out to multiple
      `zoeken-filteren` calls and merges results — opencatalogi MUST NOT
      re-implement query parsing, faceting, or ranking.
- [ ] 7.2 **`admin-settings`** (P2, NEEDS-REWRITE) — rewrite
      `openspec/specs/admin-settings/spec.md` to cite OR's `IAppConfig`
      conventions: key naming, validation, secret handling, default
      values. Remove the duplicated patterns this spec re-derives.
- [ ] 7.3 **`dashboard`** (P2) — confirm and add the citation to OR's
      aggregations annotation. Remove hand-rolled aggregation prose
      and reference the OR capability by name.
- [ ] 7.4 **`download-service`** (P2, NEEDS-REWRITE) — rewrite to
      consume OR's File Attachments + versioning capability for ZIP
      generation. The download service becomes a streaming wrapper
      that pipes OR file streams into a ZIP — no local file CRUD,
      no bespoke versioning logic.
- [ ] 7.5 In each rewrite, mark the original requirements as REMOVED
      with a brief rationale ("re-implements OR's <capability>; consume
      OR instead per ADR-022").
- [ ] 7.6 Cite `.claude/audit-2026-05-03/02-spec-rewrite.md` as the
      audit basis for each rewrite.

## Phase 8: Hardcoded magic-number cleanup

- [ ] 8.1 `lib/Service/BroadcastService.php:68,75` — promote
      `MAX_RETRIES = 3` and `REQUEST_TIMEOUT = 30` to admin-config keys
      (`broadcast_max_retries`, `broadcast_request_timeout`). Specify
      defaults equal to the current values so behaviour is unchanged.
- [ ] 8.2 `lib/Service/SitemapService.php:40` — promote
      `MAX_PER_PAGE = 1000` to admin-config (`sitemap_max_per_page`).
- [ ] 8.3 `lib/Service/SettingsService.php:64` — **delete**
      `MIN_OPENREGISTER_VERSION = '0.1.7'`. Specify that
      `appinfo/info.xml` `<dependencies>` is the only source of truth
      for the minimum OR version; Nextcloud's app dependency check
      enforces it at install time.
- [ ] 8.4 `openspec/specs/auto-publishing/spec.md` — rewrite the
      publication state-transition section to consume
      `x-openregister-lifecycle` from the schema rather than encoding
      the state machine in PHP.
- [ ] 8.5 `openspec/specs/federation/spec.md` — rewrite per-app
      retry/backoff to consume OR's outbound webhook policy.
      opencatalogi MUST NOT re-derive retry maths.
- [ ] 8.6 Specify that any new admin-config keys appear in the
      `admin-settings` spec table from Phase 7.2 (single inventory).
- [ ] 8.7 Cite `.claude/audit-2026-05-03/04-hardcoded.md` as the audit
      basis.

## Phase order rationale

- Phase 1 lands first because Phase 2's `createObjectStore` invocations
  call controllers that must already use `RegisterResolverService` —
  otherwise the store sees inconsistent error shapes.
- Phase 2 lands before Phase 3 because file-attachment migration is
  cleaner once the store is the canonical object surface.
- Phase 3 lands before Phase 7 because `download-service` (Phase 7.4)
  builds on top of the file APIs adopted in Phase 3.
- Phase 4 (i18n editing) is independent of 1-3 but lands before 5
  because the language-picker UI must coexist with the tenant badge
  in the top bar and we want one design pass on the bar layout.
- Phase 5 lands before Phase 6 because the manifest interpreter must
  understand `organisationUuidGetter` to generate Tier 2 list views
  correctly.
- Phase 6 lands before Phase 7 because spec rewrites can reference
  manifest-tier semantics ("custom view" vs "list view").
- Phase 8 lands last — it is independent and has the lowest blast
  radius. Bundling it earlier risks scope creep on the higher-impact
  phases.

## Cross-cutting acceptance criteria

- [ ] X.1 Every requirement in `specs/opencatalogi-adopt-or-abstractions/spec.md`
      is traceable back to either an audit file
      (`.claude/audit-2026-05-03/*.md`) or an upstream openspec change
      slug. No floating requirements.
- [ ] X.2 No phase ships before its upstream dependency is archived.
      Capture the dependency graph in `design.md`.
- [ ] X.3 The `breaking-changes` section of each affected spec lists
      the API surface that becomes a hard error (e.g., empty-string
      fallback in `getValueString` returning a misconfigured register
      now throws 503).
- [ ] X.4 The line-count reduction targets in `proposal.md` (≈2 250
      lines for Phase 2) are tracked as KPIs in the implementation
      change, not in this spec change.
- [ ] X.5 Each affected spec under `openspec/specs/` is updated in
      lockstep with its phase landing — partial updates (e.g.,
      `admin-settings` rewritten but `download-service` still bespoke)
      are explicitly forbidden by the cross-cutting validation.
