# Tasks: dataset-views-on-the-public-page

Start after `open-data-table-query-and-dictionary` is merged: every task here reads its `publishedTable` schema and rows endpoint. Find OpenRegister's grouped aggregation in OpenRegister `development` (`git grep -n "grouped" lib/Controller lib/Service`) and call its service in process; if only the HTTP route exists, stop and name the missing service method in the PR body instead of calling HTTP from PHP.

## 1. The view

- [x] 1.1 Add `lib/Settings/register.d/dataset-views.json` with the `tableView` schema of design D1, a `slug`, and no public read rule (REQ-DSV-001). Verify: `tests/Unit/Settings/DatasetViewSchemaTest.php::testTheFragmentHasASlugAndNoPublicReadRule`.
- [x] 1.2 Add `lib/Service/DatasetViewService.php::save()` with the column checks of design D2 (REQ-DSV-001). Verify: `tests/Unit/Service/DatasetViewServiceTest.php::testABarViewOverAKnownColumnIsSaved`, `::testAViewOverAnUnknownColumnIsRefusedWithItsName`, `::testASumOverATextColumnIsRefused`.

## 2. The data endpoint

- [x] 2.1 Add the `views` and `views/{viewId}/data` routes in `appinfo/routes.php` before the wildcard catalog routes, with CORS preflight, on a new `DatasetViewController` (REQ-DSV-002). Verify: a route-table test and `tests/Unit/Controller/DatasetViewControllerTest.php::testAViewOfANonPublicPublicationIs404` (uses the same public-read check as `PublicationsController::show()`).
- [x] 2.2 Shape `table`, `bar`, `line` and `map` data as design D3, with the 200-group and 5,000-point caps (REQ-DSV-002). Verify: `DatasetViewControllerTest::testABarViewAnswersOneValuePerGroup`, `::testAMapViewSkipsRowsWithoutCoordinates`, `::testMoreThan200GroupsAnswersTruncated`.
- [x] 2.3 List each table's views with data URLs in the public publication answer (REQ-DSV-002). Verify: `tests/Unit/Controller/PublicationsControllerTest.php::testThePublicAnswerListsTheViewsOfEachTable`.
  - Built: the service is `lib/Service/Table/DatasetViewService.php` (beside `PublishedTableService`), with the column rules in `ViewColumnRules` and the data in `DatasetViewData` / `MapPoints`. Charts call OpenRegister's in-process `AggregationRunnerInterface::runAdhocByRef()`, no HTTP. The editor routes `GET|POST /api/publications/{id}/tables/{tableId}/views` and `DELETE .../views/{viewId}` (`DatasetViewEditController`, behind `PublicationWriteGuard`) are what the dialog of 3.1 will call.

## 3. The editor

- [x] 3.1 Add "Show on the public page" to a published table's actions menu in the file list, opening `src/modals/DatasetViewDialog.vue` with `NcSelect` column pickers that carry `inputLabel` and a data preview (REQ-DSV-001). Verify: `tests/e2e/dataset-views.spec.ts` "an editor adds a bar chart", carrying `@e2e` REQ-DSV-001.
- [x] 3.2 List saved views under their table on the publication page with edit, move and remove. Verify: the same e2e file, "an editor reorders and removes a view".
  - Built: the action sits on each ready table in `PublicationTablesWidget` (the publication's tables widget, where a published table and its dictionary live), and opens `src/modals/DatasetViewDialog.vue` over the editor routes. The preview reads the saved view's public data answer, so it follows the save rather than preceding it: the editor route has no unsaved-view data. Helpers in `src/services/datasetViews.js`. Covered by `src/services/datasetViews.spec.js`, `src/modals/DatasetViewDialog.spec.js` and the views cases of `PublicationTablesWidget.spec.js` (jest). `tests/e2e/dataset-views.spec.ts` is not written yet: it needs a live Nextcloud with a ready table.

## 4. portaliq (cross-repo, ConductionNL/portaliq)

- [x] 4.1 Read `views` in `src/site/components/PublicationDetailBlock.vue` and render a paged table per `table` view (REQ-DSV-003).
- [x] 4.2 Render `bar` and `line` as SVG with NL Design System tokens and a "Show as table" toggle; render `map` with the portal's map component when present, else the table of points (REQ-DSV-003).
  - Built in ConductionNL/portaliq#2560 (branch `quality/build-dataset-views-on-the-public-page`): `PublicationDetailBlock.vue` loads `PublicationDatasetViews.vue` on demand when a table carries views; `PublicationDatasetView.vue` draws the paged table (later pages through the table's `rowsUrl`), the SVG bar and line charts and the map on the portal's `NlLocationMap`, each chart and map with "Show as table". Logic in `src/site/lib/datasetViews.js`, covered by `tests/dataset-views.spec.mjs`.
- [ ] 4.3 Add `tests/e2e/publication-dataset-views.spec.ts` "a reader switches a chart to a table" (REQ-DSV-003).

## 5. Docs and strings

- [x] 5.1 Document views for editors in `docs/` and add the strings to `l10n/` (en, nl). Verify: `npm run check:l10n` and a grep for U+2014 on the changed docs.
  - The API side is documented in `docs/features/open-data-tables.md`; the editor dialog's strings follow with task 3.1.

## 6. Verification

- [ ] 6.1 `openspec validate dataset-views-on-the-public-page --strict`, `composer check:strict`, `npm run lint`.
  - Run 2026-11-17: `composer phpstan` on the whole tree is clean, phpcs on the changed lib files is clean, `npm run lint` reports 13 errors, all in `src/sidebars/dashboard/DashboardSideBar.vue`, which this change does not touch (inherited). `openspec validate --strict` still refuses on three warnings in `specs/open-data-tables/spec.md` (text above the first section, two requirements over 500 characters): the spec text needs a rewrite by its author, so this stays open.
- [ ] 6.2 Live: publish a CSV as a table on the dev instance, add a bar view and a map view, and paste both data answers in the PR body. (live pass, decision 139)
- [x] 6.3 Set `od-visualise` in `openspec/parity/capabilities.json` to `building` while portaliq tasks are open, `built` once they land.
