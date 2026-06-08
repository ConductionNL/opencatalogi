# Tasks — opencatalogi-adopt-or-abstractions

> Spec-only change. Each task below authors a slice of
> `specs/opencatalogi-adopt-or-abstractions/spec.md` (and adjacent specs
> in `openspec/specs/`). Code changes happen in follow-up implementation
> changes. Phase order encodes upstream-dependency order — see
> "Phase order rationale" at the bottom for the why.

## Phase 1 — Adopt `RegisterResolverService` across all controllers

- [x] 1. Spec the five canonical contexts (`publications`, `listings`,
      `catalogi`, `themes`, `pages`) + the three legacy `getValueString`
      contexts (`glossary`, `menus`, `organisations`); require every
      controller call site (per `.claude/audit-2026-05-03/04-hardcoded.md`)
      to use `RegisterResolverService::resolve*`; forbid empty-string
      fallbacks (throw `RegisterNotConfiguredException` → 503 with
      operator-actionable detail); resolver result is request-scoped (no
      static caching); cite OR `register-resolver-service` as upstream and
      pin minimum OR version in `appinfo/info.xml`; opencatalogi MUST NOT
      re-implement the resolver locally.

## Phase 2 — Migrate object store to `createObjectStore()`

- [x] 2. Spec deletion of `src/store/modules/object.js` (≈2 449 lines)
      and replacement with `createObjectStore('object', { plugins: [
      filesPlugin, auditTrailsPlugin, relationsPlugin, searchPlugin ] })`
      from `@conduction/nextcloud-vue`; forbid bespoke Pinia modules per
      `feedback_store-pattern.md`; consumer components MUST use
      `useObjectStore('object')`; pagination/filters/faceted-search MUST
      stay functionally identical (smoke-test list in `design.md`);
      document the same migration path for `src/store/modules/search.js`;
      track ≈2 250-line reduction as a non-functional acceptance criterion;
      object E2E flows MUST continue to pass.

## Phase 3 — Rewrite `file-management`; consume OR file APIs

- [x] 3. Rewrite `openspec/specs/file-management/spec.md`: mark legacy
      requirements REMOVED; require consumption of OR File Attachments via
      `x-openregister-file` schema annotations + `IFileService` (or its
      post-resolver-service equivalent); sharing goes through
      `OCP\Share\IShareManager` (no local re-implementation);
      `FilesController.php` becomes a thin delegate (list every removed
      endpoint + migration path); cite OR file capability +
      `register-resolver-service`; flag the Phase 7 `download-service`
      dependency.

## Phase 4 — i18n editing UI for translatable content

- [x] 4. List the five controllers consuming `TranslationHandler`
      (`Pages`, `Menus`, `Publications`, `Themes`, `Glossary`); spec
      `Accept-Language` + `?_lang=` negotiation order (query > header >
      user pref > app default) per `i18n-api-language-negotiation`;
      require `translatable: true` + `sourceLanguage` on every
      user-facing string property per ADR-025 (catalogue in a table in
      the spec — implementation cannot ship with missing rows); spec the
      language-selector UI from `@conduction/nextcloud-vue` on the five
      detail/modal screens; spec server-side `Accept-Language` resolution
      on public catalogue routes (no client flicker); cite
      `i18n-source-of-truth` + `i18n-api-language-negotiation` as upstream
      (negotiation algorithm is OWNED upstream — MUST NOT re-derive).

## Phase 5 — Adopt nextcloud-vue multi-tenancy primitives

- [x] 5. Spec `App.vue::setup()` calling `useTenantContext()` once
      `multi-tenancy-context` archives; every `createObjectStore()` MUST
      receive `organisationUuidGetter`; `<CnTenantBadge>` lives top-bar
      left of the user menu on every route; switching tenant MUST reset
      all stores + pagination + filters (or scope filters per tenant —
      decide in `design.md`); `CnFormDialog` MUST auto-fill + lock the
      active tenant on schemas with an `organisation` relation unless
      the user has cross-tenant edit rights; cite
      `nextcloud-vue/.../multi-tenancy-context/` +
      `.claude/audit-2026-05-03/research/R2-nc-vue-multitenancy.md`.

## Phase 6 — Adopt the app manifest convention (Tier 2-3)

- [x] 6. Spec `src/manifest.json` per ADR-024 + `adopt-app-manifest`;
      tier opencatalogi as Tier 2-3 (custom catalog/publication/CMS views
      = `type: "custom"`, all admin CRUD = `type: "list" | "detail"`);
      catalogue every `src/router/index.js` route in a table tagged
      list/detail/custom (implementation cannot ship with missing rows);
      manifest declares `dependencies: ["openregister"]`; spec the
      "MUST NOT exist" deletion of hand-rolled router + custom
      `<NcAppNavigation>` blocks once the interpreter takes over;
      `appinfo/routes.php` is the backend source of truth (manifest
      references controller names, not paths); cite
      `adopt-app-manifest` + ADR-024 + R6-manifest-json audit.

## Phase 7 — Spec rewrites: `search`, `admin-settings`, `dashboard`, `download-service`

- [x] 7. Rewrite the four specs per `.claude/audit-2026-05-03/02-spec-rewrite.md`:
      `search` cites OR `zoeken-filteren` (federated = thin
      orchestrator, no local query parsing/faceting/ranking);
      `admin-settings` cites OR `IAppConfig` conventions (key naming,
      validation, secrets, defaults — remove duplicated patterns);
      `dashboard` cites OR aggregations annotation; `download-service`
      becomes a streaming wrapper over OR File Attachments + versioning
      (no local file CRUD, no bespoke versioning). In each rewrite, mark
      replaced requirements REMOVED with rationale ("re-implements OR's
      <capability>; consume OR per ADR-022").

## Phase 8 — Hardcoded magic-number cleanup

- [x] 8. Promote `BroadcastService::MAX_RETRIES = 3` +
      `REQUEST_TIMEOUT = 30` to `broadcast_max_retries` /
      `broadcast_request_timeout` (defaults unchanged); promote
      `SitemapService::MAX_PER_PAGE = 1000` to `sitemap_max_per_page`;
      DELETE `SettingsService::MIN_OPENREGISTER_VERSION` —
      `appinfo/info.xml` `<dependencies>` is the sole source of truth;
      rewrite `auto-publishing` spec to consume `x-openregister-lifecycle`
      (no PHP state machine); rewrite `federation` spec to consume OR's
      outbound webhook retry policy (no re-derived backoff maths); all
      new admin-config keys appear in the Phase 7 `admin-settings`
      inventory table. Cite `.claude/audit-2026-05-03/04-hardcoded.md`.

## Cross-cutting acceptance criteria

- [x] X.1 Every requirement in
      `specs/opencatalogi-adopt-or-abstractions/spec.md` traces back to an
      audit file or an upstream openspec change slug — no floating
      requirements. Dependency graph captured in `design.md`; no phase
      ships before its upstream change is archived.
- [x] X.2 The `breaking-changes` section of each affected spec lists the
      API surface that becomes a hard error (e.g., empty-string
      `getValueString` fallback for a misconfigured register now throws
      503). Line-count reduction targets (≈2 250 lines for Phase 2) live
      as KPIs in the implementation change, not in this spec change.
- [x] X.3 Each affected spec under `openspec/specs/` updates in lockstep
      with its phase landing — partial updates (e.g., `admin-settings`
      rewritten but `download-service` still bespoke) are explicitly
      forbidden by cross-cutting validation.

## Phase order rationale

- Phase 1 first: Phase 2's `createObjectStore` calls hit controllers that
  must already use `RegisterResolverService` — otherwise the store sees
  inconsistent error shapes.
- Phase 2 before Phase 3: file-attachment migration is cleaner once the
  store is the canonical object surface.
- Phase 3 before Phase 7: `download-service` (Phase 7) builds on the file
  APIs adopted in Phase 3.
- Phase 4 independent of 1-3 but before Phase 5: the language picker must
  coexist with the tenant badge in the top bar; one design pass on the
  bar layout.
- Phase 5 before Phase 6: the manifest interpreter must understand
  `organisationUuidGetter` to generate Tier 2 list views correctly.
- Phase 6 before Phase 7: spec rewrites can reference manifest-tier
  semantics ("custom view" vs "list view").
- Phase 8 last: independent, lowest blast radius; bundling earlier risks
  scope creep on higher-impact phases.
