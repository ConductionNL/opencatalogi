# Tasks: dashboard-consume-or-aggregations

> **Implementation note (see also spec delta):** two premises in this proposal
> did not survive contact with OR's real, live-verified aggregation contract
> (`OCA\OpenRegister\Service\Aggregation\AggregationAnnotationValidator` /
> `AggregationRunner` / `AggregationController`, read directly from the
> `openregister` app source during implementation):
>
> 1. **`groupBy: @self.schema` is not supported.** The annotation validator
>    requires `groupBy.field` to exist in the host schema's own `properties`
>    (`AggregationAnnotationValidator::validate()`); `@self.*` parent-reference
>    resolution only applies to cross-schema `where`/`filter` clauses, never to
>    `groupBy`. A `countBySchema` aggregation grouped by `@self.schema` would
>    fail schema-save validation outright. Separately, the ad-hoc
>    `.../grouped` endpoint is scoped to one `(register, schema)` pair per
>    call, so it cannot tally counts *across* schemas even if grouping by
>    object-metadata were supported — which is what "Publications by Category"
>    needs when a catalog's publications span multiple schemas. This is an
>    upstream OR capability gap, not something fixable inside this app.
> 2. **There is no `attachment` OR schema.** `objectStore.fetchCollection('attachment')`
>    (used by `UnpublishedAttachmentsWidget.vue` /
>    `UnpublishedPublicationsWidget.vue`) does not correspond to any register/schema
>    declared anywhere under `lib/Settings/` — attachments are Nextcloud Files
>    metadata reached per-publication via
>    `GET /apps/openregister/api/objects/{register}/{schema}/{id}/files`
>    (`PublicationsController::attachments()`), not a first-class OR object
>    collection. Declaring `x-openregister-aggregations` "on the `attachment`
>    schema" is not actionable until a design decision is made: either promote
>    attachments to a real OR-backed schema, or redesign these widgets around
>    the Files-metadata model. Deferred — flagging for a follow-up change/ADR
>    rather than fabricating a schema declaration with nothing behind it.
>
> Additionally, the three temporal KPI counts (`concept`/`published`/
> `depublished`) are DERIVED from a date comparison against "now"
> (`publicatiedatum`/`depublicatiedatum` — see `publicationStatus.js`), not a
> stored field, so they cannot be grouped by directly either. OR's ad-hoc
> `value` endpoint's `filter` DSL supports `gt/gte/lt/lte/ne/in/notIn` on
> stored fields with an explicit (client-computed) comparison value, which
> could probably express the two date-range filters, but there's no
> confirmed `isNull`/"field absent" operator, and `concept` requires exactly
> that (no `publicatiedatum` set, OR it's in the future) — implementing this
> via a guessed filter shape risked shipping confidently-wrong counts, which is
> worse than the known, visible truncation bug. **Only the one fix that is
> unambiguously correct and directly addresses the literal DSH-010 bug (the
> headline total silently truncating past 1000 rows) was implemented.**
>
> What DID ship: the `publicationCount` KPI now sources its total from OR's
> always-available ad-hoc `GET /api/objects/aggregations/{register}/{schema}/value?metric=count`
> endpoint (no schema annotation required for this entry point), which has no
> row cap — fixing the exact correctness bug the proposal opened with. The
> `countByStatus` aggregation was still declared on the `publication` schema
> (a real, valid, useful aggregation over the RET-006 lifecycle `status`
> field), even though it doesn't power the concept/published/depublished KPIs
> as originally envisioned.

## 1. Schema-level aggregation declarations

- [x] Add `x-openregister-aggregations.countByStatus` to the `publication`
      schema — implemented in `lib/Settings/publication_register.json`
      (`components.schemas.publication`) using the REAL annotation shape
      (`metric: count`, `groupBy: {field: status}`, not the proposal's assumed
      `operation`/bare-string `groupBy`), grouping by the RET-006 lifecycle
      `status` property (published/archived) — validated against
      `AggregationAnnotationValidator`'s actual rules and against `php -r
      'json_decode(...)'` for syntax.
- [ ] ~~Add `x-openregister-aggregations.countBySchema`~~ — NOT POSSIBLE with
      OR's current aggregation feature; see the note above. Not implemented.
- [ ] ~~Add `x-openregister-aggregations.countByStatus` to the `attachment`
      schema~~ — NO SUCH SCHEMA EXISTS; see the note above. Not implemented.
- [ ] Confirm the register import picks up the new annotation on a clean
      install / `reload-settings` — DEFERRED, needs a live instance
      (`SettingsService::loadSettings()` round-trip); not verifiable from an
      isolated worktree with no running Nextcloud.

## 2. Frontend: Dashboard.vue KPI counts

- [x] Add `fetchPublicationAggregations()` — implemented in
      `src/views/dashboard/Dashboard.vue`, calling OR's generic ad-hoc
      `GET /apps/openregister/api/objects/aggregations/{register}/{schema}/value?metric=count`
      (register/schema resolved via `loadState('opencatalogi',
      'publication_register'/'publication_schema')`, the same zero-network
      initial-state pattern `AddDirectoryModal.vue` already uses for
      `default_directory_url` — sourced from `UiController::MANIFEST_CONFIG_KEYS`).
      This is the ad-hoc entry point, not the named `{name}` aggregate route,
      so it works with or without a declared schema annotation.
- [x] Update `kpis()` / `publicationCount` to read `this.publicationTotal`,
      now populated by the aggregation call (falls back to the page total only
      if the aggregation call didn't resolve, e.g. unconfigured register/schema
      on a fresh install).
- [ ] ~~Update `publicationsByCategoryData()` to use `countBySchema`~~ — NOT
      DONE; blocked on the cross-schema groupBy gap above. Left on its
      existing client-side tally over the fetched page (same truncation
      exposure as before for catalogs with >1000 publications spanning many
      schemas — unresolved, needs upstream OR support or a redesign).
- [ ] `allPublications`/`conceptPublications`/`publishedPublications`/
      `depublishedPublications` getters — KEPT (not removed): they still back
      the concept/published/depublished KPI sub-counts and the "recent
      concepts" side panels, which remain list-derived because their
      underlying status is a computed (not stored) value — see the note above
      for why an aggregation-based replacement wasn't safe to guess at.
- [x] Right-sized `fetchAllPublications()`'s role — it is documented as no
      longer the source of the headline `publicationCount` KPI (that's
      `fetchPublicationAggregations()` now); it remains the source for the
      three sub-status counts and side-panel lists, with its `_limit=1000`
      left as-is (unchanged behaviour, now honestly scoped in the docblock
      rather than silently doubling as an inaccurate total-count source).

## 3. Frontend: re-enable Concept Attachments widget

- [ ] NOT DONE — depends on the `attachment` schema existing (see note above).
      Left commented out exactly as at HEAD; the `// Do NOT remove this code`
      TODO still stands until that design decision is made.

## 4. Frontend: UnpublishedAttachmentsWidget / UnpublishedPublicationsWidget

- [ ] NOT DONE — same blocker; `objectStore.fetchCollection('attachment')`
      has no real backing schema to aggregate against. Left as-is.

## 5. Spec + verification

- [x] Updated `openspec/specs/dashboard/spec.md` — DSH-010 now cites the
      real ad-hoc aggregation fix (total count only); DSH-011/DSH-OR-001 note
      the partial landing and the two upstream gaps explicitly rather than
      claiming full "Status: Implemented" compliance.
- [x] No new user-facing strings were introduced by the KPI aggregation change
      — nothing to register via `scripts/l10n-ai.js`.
- [ ] Manual verify against a >1000-publication catalog — DEFERRED, needs a
      live instance / Postgres seed script not available from this isolated
      worktree.
- [ ] Verify `UnpublishedAttachmentsWidget`/`UnpublishedPublicationsWidget` on
      `/apps/dashboard/` — DEFERRED, moot until task 4 is unblocked.
