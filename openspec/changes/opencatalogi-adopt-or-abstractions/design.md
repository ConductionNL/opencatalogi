# Design — opencatalogi-adopt-or-abstractions

## Context

opencatalogi is the highest-traffic frontend in the Conduction fleet — it
serves the public publication catalogues that municipalities and ministries
expose to citizens. It also carries the largest volume of bespoke code
duplicating capabilities OR or upstream Nextcloud now own (per
`.claude/audit-2026-05-03/`). This change is the one large per-app
adoption ticket that brings opencatalogi onto the shared abstractions.

It is intentionally written as one change rather than eight because:

1. **The controllers, store, and manifest are entangled.** Migrating
   one controller to `RegisterResolverService` while the store still
   hand-rolls register lookups produces inconsistent error shapes that
   confuse the UI. The phases are ordered so each phase removes code
   that the previous phase replaced.

2. **The audit landed simultaneously across all five affected specs.**
   Splitting the spec rewrites across multiple changes risks divergent
   conventions (e.g., one spec citing `IFileService` and another citing
   the older `FileService`). One change keeps citations aligned.

3. **Manifest tiering depends on the upstream contracts being agreed.**
   We cannot tier opencatalogi until the resolver, the i18n handler,
   the multi-tenancy context, and the manifest interpreter are all
   contractually settled. Authoring tiering decisions in the same
   change as the abstraction adoption gives us a single review pass.

## Goals

- Delete the bespoke 2 449-line object store and inherit
  `createObjectStore()` from `@conduction/nextcloud-vue`.
- Stop calling `IAppConfig::getValueString(..., '')` for register/schema
  lookups in any controller; consume `RegisterResolverService` instead.
- Ship the first multi-language editing UI in opencatalogi (Pages,
  Menus, Publications, Themes, Glossary), wiring `Accept-Language` /
  `?_lang=` on the backend and a language-picker on the frontend.
- Adopt `useTenantContext()` and `<CnTenantBadge>` once
  `nextcloud-vue@v0.x` ships.
- Adopt the manifest convention as the first **Tier 2-3** consumer
  (proves the manifest can express bespoke views via `type: "custom"`).
- Rewrite five NEEDS-REWRITE specs to cite OR and stop re-deriving
  capabilities OR already owns.
- Promote three hardcoded magic numbers to admin-config and delete one
  duplicated minimum-version constant.

## Non-goals

- **No code changes in this change.** Every artifact here is spec only.
  Implementation lands in a follow-up change once these contracts are
  archived.
- **No new product features.** This is pure cleanup — every behaviour
  the user sees today must be preserved.
- **No migration of existing data.** Translatable fields default to
  the source language; no bulk auto-translation is in scope.
- **No reshuffling of the public catalogue URL structure.** SEO
  stability is a hard constraint; the manifest must reproduce existing
  paths exactly.

## Architectural decisions

### A. Eight-phase split and ordering

The phase ordering in `tasks.md` is load-bearing:

| # | Phase | Depends on | Why this order |
|---|-------|-----------|---------------|
| 1 | RegisterResolverService | OR resolver archived | Every later phase calls controllers |
| 2 | createObjectStore | Phase 1 (consistent error shapes) | Largest line-count reduction |
| 3 | file-management rewrite | Phase 2 (store owns file relations) | OR file APIs land cleanly |
| 4 | i18n editing UI | OR i18n changes archived | Independent of 1-3, parallel-able |
| 5 | Multi-tenancy adoption | nc-vue v0.x archived | Top-bar coexists with language picker |
| 6 | Manifest adoption | Phase 5 (tenant getter wired) | Manifest interpreter needs tenant getter |
| 7 | Spec rewrites (search/admin/dashboard/download) | Phase 6 (tier semantics) | Specs reference manifest tier names |
| 8 | Magic-number cleanup | none | Lowest blast radius, ships last |

### B. RegisterResolverService consumption pattern

Every controller follows the same migration shape:

- **Before:** `$registerId = $this->config->getValueString($appName,
  '<context>_register', '');` followed by silent fallthrough when empty.
- **After:** `$register = $this->resolver->resolveRegister('<context>');`
  which throws `RegisterNotConfiguredException` mapped to a 503 with
  operator-actionable detail.

The five contexts named in the audit are `publications`, `listings`,
`catalogi`, `themes`, `pages`. The three additional contexts
(`glossary`, `menus`, `organisations`) follow the same pattern.

### C. Store migration boundary

The bespoke store at `src/store/modules/object.js` has one public
contract today: action names like `fetchAll`, `fetchOne`, `create`,
`update`, `delete`, `fetchRelated`. The migrated thin-wrapper preserves
those action names so consumer components don't need code changes
beyond the import path.

The four plugins consumed are:

- `filesPlugin()` — replaces the bespoke `relatedData.files` block.
- `auditTrailsPlugin()` — replaces the bespoke `relatedData.logs` block.
- `relationsPlugin()` — replaces the bespoke `relatedData.uses` block.
- `searchPlugin()` — replaces the bespoke search/filter/facet logic.

`src/store/modules/search.js` is migrated either by consuming
`searchPlugin()` directly or by extracting a shared store. The
decision is captured in `tasks.md` Phase 2.6; default is "consume
the plugin" unless cross-store federation requires otherwise.

### D. i18n editing UI shape

Per ADR-025, every translatable schema:

- declares `sourceLanguage: "nl"` (or `"en"` for the publication
  catalogue's English-first datasets);
- marks user-facing string properties as `translatable: true`;
- stores translations in a sibling structure resolved by the
  `TranslationHandler` on read.

The frontend language-picker is rendered by the upstream nextcloud-vue
component; opencatalogi-side wiring is:

1. Read the active locale from `loadState`.
2. Pass it as `?_lang=<locale>` on every detail-page fetch.
3. Render a tab strip at the top of the modal showing one tab per
   declared translation; missing translations show a "+ add" affordance.

### E. Multi-tenancy wiring

`useTenantContext()` returns a reactive `{ organisationUuid, switchTenant,
tenants }` shape. opencatalogi consumes it in `App.vue::setup()` and
forwards `organisationUuid` to every store invocation. Cache invalidation
on tenant switch is handled by the upstream nc-vue capability — this app
just wires the watcher.

### F. Manifest tiering

opencatalogi is the first **Tier 2-3** manifest consumer. The decision
table in `tasks.md` Phase 6.3 catalogues every existing route and tags
it `list` / `detail` / `custom`. Examples:

- `/publications` → `type: "list"` (manifest-rendered)
- `/publications/:id` → `type: "detail"` (manifest-rendered)
- `/publications/:id/render` → `type: "custom"` (bespoke renderer)
- `/catalogs/:slug` → `type: "custom"` (public catalogue landing)
- `/cms/pages/:id` → `type: "custom"` (CMS edit experience)

The manifest interpreter handles `list` / `detail` automatically; the
app retains code only for the `custom` views.

### G. Spec rewrite vs. spec deletion

Every NEEDS-REWRITE spec is **rewritten** rather than deleted because
each describes app-specific orchestration on top of OR primitives —
the orchestration still belongs in opencatalogi. The rewrite collapses
the spec to "consume OR's `<capability>`; this app's responsibility is
limited to <X, Y, Z>".

## Risks and mitigations

- **R1 — Big-bang change is hard to review.** Mitigation: the eight
  phases are reviewable independently; the change can be split into
  eight implementation PRs while keeping one spec change.
- **R2 — Upstream specs slip.** Mitigation: each phase declares its
  upstream blocker in `tasks.md`; phases that are unblocked can ship
  while later phases wait. Phase 8 has no upstream dependency and can
  ship first if needed.
- **R3 — Public catalogue regression.** Mitigation: SEO stability is
  a hard constraint; the manifest tiering table catalogues every
  current public URL with a 1:1 mapping. End-to-end smoke tests on
  `/publications`, `/catalogs/:slug`, `/cms/pages/:id` are mandatory
  acceptance gates per `tasks.md` 2.5 and 6.3.
- **R4 — i18n migration touches every detail page.** Mitigation: this
  is a UI-only addition (no data migration). Existing single-language
  content stays valid as the source language; translations are
  optional and can be authored incrementally.
- **R5 — `getValueString('')` empty-string fallback hid misconfiguration.**
  Mitigation: Phase 1.4 explicitly turns this into a 503 with
  operator detail; capture in the breaking-changes section of the
  affected specs so operators know to set the keys before upgrade.
- **R6 — Tenant switch invalidation cascading through every store
  could be slow.** Mitigation: the upstream nc-vue capability owns the
  invalidation strategy; opencatalogi just registers the watcher.
  Performance budget captured in nc-vue's `multi-tenancy-context`
  spec, not here.

## Alternatives considered

- **Eight separate per-app changes** — rejected because the audit
  files cross-cite each other and the controllers / store / manifest
  are entangled. Reviewing them in lockstep is cheaper than
  re-aligning eight in-flight changes.
- **Defer i18n editing UI to a separate feature change** — rejected
  because the schema markup (`translatable: true`, `sourceLanguage`)
  must land in lockstep with the controllers and the language-picker;
  doing them separately produces broken intermediate states.
- **Keep the bespoke object store and just refactor it** — rejected
  because every other app in the fleet either has migrated or will
  migrate to `createObjectStore`; keeping a bespoke store here makes
  opencatalogi an ongoing maintenance liability.
- **Tier the manifest as Tier 1 (fully manifest-driven)** — rejected
  because the public catalogue and CMS edit experiences cannot be
  expressed declaratively. Tier 2-3 with `type: "custom"` for those
  three views is the minimal-surface answer.
- **Promote `MIN_OPENREGISTER_VERSION` instead of deleting it** —
  rejected because `appinfo/info.xml` `<dependencies>` is already the
  Nextcloud-native source of truth and the install-time check enforces
  it; the PHP constant is dead weight.

## Open questions

- **Q1.** When the resolver throws `RegisterNotConfiguredException`,
  should the 503 response include enough detail for an operator to
  fix it (key name, expected register name) or only opaque "register
  not configured"? Recommendation: include detail behind admin auth;
  redact for unauthenticated callers.
- **Q2.** Does `searchPlugin()` from `@conduction/nextcloud-vue` cover
  cross-catalog federation, or do we still need the bespoke
  `src/store/modules/search.js` for that case? Decision in
  `tasks.md` 2.6.
- **Q3.** The current public catalogue uses URL slugs
  (`/catalogs/:slug`) that don't map cleanly to manifest `list`/`detail`
  types. The proposal tags these `custom`; is there value in extending
  the manifest spec to support slug-based public routes generically?
  Out of scope for this change; raise upstream against
  `adopt-app-manifest` if the pattern repeats across other apps.
- **Q4.** Should the language picker default to the user's Nextcloud
  language preference, the browser `Accept-Language`, or the catalogue's
  declared `sourceLanguage`? Recommendation: follow the negotiation
  order in `i18n-api-language-negotiation` (query > header > user pref
  > app default) — no app-specific override.
