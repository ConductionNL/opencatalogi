# Tasks: dashboard-consume-or-aggregations

## 1. Schema-level aggregation declarations

- [ ] Add `x-openregister-aggregations.countByStatus` (`field: status`,
      `operation: count`, `groupBy: status`) to the `publication` schema in
      `lib/Settings/register.d/` (whichever fragment currently declares
      `publication`'s `status` property).
- [ ] Add `x-openregister-aggregations.countBySchema` (`field: @self.schema`,
      `operation: count`, `groupBy: @self.schema`) to the same `publication`
      schema fragment, for the "Publications by Category" chart.
- [ ] Add `x-openregister-aggregations.countByStatus` (`field: status`,
      `operation: count`, `groupBy: status`) to the `attachment` schema
      fragment.
- [ ] Confirm the register import (`SettingsService::loadSettings()`) picks up
      the new annotations on a clean install and on `reload-settings` without
      requiring a schema version bump beyond the existing convention.

## 2. Frontend: Dashboard.vue KPI counts

- [ ] Add a small `fetchPublicationAggregations()` method that calls
      `GET /api/objects/aggregations/{register}/{schema}/grouped?aggregation=countByStatus`
      and `.../grouped?aggregation=countBySchema` (register/schema ids
      resolved the same way `getCatalogConfiguration()` already resolves them
      for `CatalogiController`), replacing `fetchAllPublications()`'s role as
      the KPI data source.
- [ ] Update `kpis()` (`Dashboard.vue:411-420`) to read
      `publicationCount`, `conceptPublicationCount`, `publishedPublicationCount`,
      `depublishedPublicationCount` from the `countByStatus` aggregation
      result instead of `this.allPublications.length` / the `*Publications`
      filtered-array getters.
- [ ] Update `publicationsByCategoryData()` (`Dashboard.vue:422-440`) to map
      the `countBySchema` aggregation result directly to `{ labels, series }`
      instead of looping `allPublications`.
- [ ] Remove `allPublications`, `conceptPublications`, `publishedPublications`,
      `depublishedPublications` computed getters (`Dashboard.vue:381-402`) if
      nothing else in the file still needs the raw list; if the "recent
      concepts" side panel still needs individual records, keep a
      *paginated* (not `_limit=1000`) fetch scoped to that panel only.
- [ ] Remove or right-size `fetchAllPublications()`'s `_limit=1000` fetch —
      it must no longer be the source for any KPI count.

## 3. Frontend: re-enable Concept Attachments widget

- [ ] Uncomment `widget-count-concept-attachments` template block
      (`Dashboard.vue:98-115`) and the sidebar concept-attachments block
      (`:204-224`), wiring `kpis.conceptAttachmentCount` to the new
      `attachment` `countByStatus` aggregation (filtered/read for the
      `Concept` bucket) instead of the old per-publication file-fetch loop.
- [ ] Delete the commented-out `fetchConceptAttachments()` /
      `allAttachments()` / `conceptAttachments()` dead code
      (`Dashboard.vue:524-542`, `:404-408`) now that the scalable
      replacement exists — resolves the "Do NOT remove this code" TODO by
      actually replacing it.
- [ ] Add `{ id: 'count-concept-attachments', ... }` back to `widgetDefs()`.

## 4. Frontend: UnpublishedAttachmentsWidget / UnpublishedPublicationsWidget

- [ ] Replace `objectStore.fetchCollection('attachment')` +
      client-side `.filter(status === 'Concept')` in
      `src/views/widgets/UnpublishedAttachmentsWidget.vue:50-58,68-71` with a
      call to the `attachment` schema's `countByStatus` aggregation grouped
      value, requesting only the `Concept` list rows needed for the widget's
      item display (or the aggregation's row-level detail endpoint if the
      widget needs individual items, not just a count — confirm against
      OR's `aggregation#grouped` response shape).
- [ ] Apply the equivalent change to `UnpublishedPublicationsWidget.vue` /
      `src/unpublishedPublicationsWidget.js`.

## 5. Spec + verification

- [ ] Update `openspec/specs/dashboard/spec.md` DSH-010, DSH-011, DSH-OR-001
      "Status" lines once the above lands (remove the inaccurate "Status:
      Implemented (aggregation citation added by Phase 7)" claim from the
      *change* history and replace with the real landing note).
- [ ] `npm run check:l10n` if any new user-facing strings are introduced
      (e.g. an aggregation-unavailable fallback message) — add keys via
      `scripts/l10n-ai.js add`, never by hand.
- [ ] Manually verify against a catalog with >1000 publications (or a
      Postgres seed script) that KPI counts and the category chart now match
      the true totals, where before this change they were silently truncated.
- [ ] Verify `UnpublishedAttachmentsWidget` and `UnpublishedPublicationsWidget`
      render correctly on the Nextcloud dashboard (`/apps/dashboard/`), not
      just inside the OpenCatalogi SPA.
