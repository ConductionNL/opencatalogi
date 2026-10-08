# Tasks: dataset-views-on-the-public-page

Start after `open-data-table-query-and-dictionary` is merged: every task here reads its `publishedTable` schema and rows endpoint. Find OpenRegister's grouped aggregation in OpenRegister `development` (`git grep -n "grouped" lib/Controller lib/Service`) and call its service in process; if only the HTTP route exists, stop and name the missing service method in the PR body instead of calling HTTP from PHP.

## 1. The view

- [ ] 1.1 Add `lib/Settings/register.d/dataset-views.json` with the `tableView` schema of design D1, a `slug`, and no public read rule (REQ-DSV-001). Verify: `tests/Unit/Settings/DatasetViewSchemaTest.php::testTheFragmentHasASlugAndNoPublicReadRule`.
- [ ] 1.2 Add `lib/Service/DatasetViewService.php::save()` with the column checks of design D2 (REQ-DSV-001). Verify: `tests/Unit/Service/DatasetViewServiceTest.php::testABarViewOverAKnownColumnIsSaved`, `::testAViewOverAnUnknownColumnIsRefusedWithItsName`, `::testASumOverATextColumnIsRefused`.

## 2. The data endpoint

- [ ] 2.1 Add the `views` and `views/{viewId}/data` routes in `appinfo/routes.php` before the wildcard catalog routes, with CORS preflight, on a new `DatasetViewController` (REQ-DSV-002). Verify: a route-table test and `tests/Unit/Controller/DatasetViewControllerTest.php::testAViewOfANonPublicPublicationIs404` (uses the same public-read check as `PublicationsController::show()`).
- [ ] 2.2 Shape `table`, `bar`, `line` and `map` data as design D3, with the 200-group and 5,000-point caps (REQ-DSV-002). Verify: `DatasetViewControllerTest::testABarViewAnswersOneValuePerGroup`, `::testAMapViewSkipsRowsWithoutCoordinates`, `::testMoreThan200GroupsAnswersTruncated`.
- [ ] 2.3 List each table's views with data URLs in the public publication answer (REQ-DSV-002). Verify: `tests/Unit/Controller/PublicationsControllerTest.php::testThePublicAnswerListsTheViewsOfEachTable`.

## 3. The editor

- [ ] 3.1 Add "Show on the public page" to a published table's actions menu in the file list, opening `src/modals/DatasetViewDialog.vue` with `NcSelect` column pickers that carry `inputLabel` and a data preview (REQ-DSV-001). Verify: `tests/e2e/dataset-views.spec.ts` "an editor adds a bar chart", carrying `@e2e` REQ-DSV-001.
- [ ] 3.2 List saved views under their table on the publication page with edit, move and remove. Verify: the same e2e file, "an editor reorders and removes a view".

## 4. portaliq (cross-repo, ConductionNL/portaliq)

- [ ] 4.1 Read `views` in `src/site/components/PublicationDetailBlock.vue` and render a paged table per `table` view (REQ-DSV-003).
- [ ] 4.2 Render `bar` and `line` as SVG with NL Design System tokens and a "Show as table" toggle; render `map` with the portal's map component when present, else the table of points (REQ-DSV-003).
- [ ] 4.3 Add `tests/e2e/publication-dataset-views.spec.ts` "a reader switches a chart to a table" (REQ-DSV-003).

## 5. Docs and strings

- [ ] 5.1 Document views for editors in `docs/` and add the strings to `l10n/` (en, nl). Verify: `npm run check:l10n` and a grep for U+2014 on the changed docs.

## 6. Verification

- [ ] 6.1 `openspec validate dataset-views-on-the-public-page --strict`, `composer check:strict`, `npm run lint`.
- [ ] 6.2 Live: publish a CSV as a table on the dev instance, add a bar view and a map view, and paste both data answers in the PR body.
- [ ] 6.3 Set `od-visualise` in `openspec/parity/capabilities.json` to `building` while portaliq tasks are open, `built` once they land.
