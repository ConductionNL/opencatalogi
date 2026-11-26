---
status: proposed
---

# Dataset views on the public page

## ADDED Requirements

### Requirement: An editor adds a table, chart or map view to a published table (REQ-DSV-001)

A schema `tableView` SHALL be declared in `lib/Settings/register.d/dataset-views.json` with `table`, `kind` (`table`, `bar`, `line`, `map`), `title`, `columns`, `x`, `y`, `aggregate`, `latitude`, `longitude`, `label` and `position`. `DatasetViewService::save()` MUST refuse with 400, naming the column, a view whose columns do not fit the table's schema. A published table SHALL offer "Show on the public page" in its actions menu, opening `DatasetViewDialog.vue`.

#### Scenario: A bar chart of playgrounds per district
<!-- @e2e exclude Editor dialog not yet designed on the canvas; proven by tests/e2e/dataset-views.spec.ts once task 3.1 lands, and by DatasetViewServiceTest::testABarViewOverAKnownColumnIsSaved. -->

- **GIVEN** a published table "Speeltuinen" with columns `naam`, `wijk`, `lat` and `lon`
- **WHEN** an editor adds a view of kind `bar` with `x` `wijk` and `aggregate` `count`
- **THEN** the view is saved and listed under the table on the publication page

#### Scenario: A view over a renamed column is refused
<!-- @e2e exclude Service rule; proven by DatasetViewServiceTest::testAViewOverAnUnknownColumnIsRefusedWithItsName. -->

- **GIVEN** a published table without a column `buurt`
- **WHEN** an editor saves a `bar` view with `x` `buurt`
- **THEN** the answer is 400 and the message names `buurt`

#### Scenario: A sum over a text column is refused
<!-- @e2e exclude Service rule; proven by DatasetViewServiceTest::testASumOverATextColumnIsRefused. -->

- **GIVEN** a published table whose column `naam` is text
- **WHEN** an editor saves a `bar` view with `y` `naam` and `aggregate` `sum` (or `avg`)
- **THEN** the answer is 400 and the message names `naam`

#### Scenario: A map over text coordinates is refused
<!-- @e2e exclude Service rule; proven by DatasetViewServiceTest::testAMapNeedsNumericCoordinatesAndATableKnownColumns. -->

- **GIVEN** a published table whose coordinate column `lat` is not numeric
- **WHEN** an editor saves a `map` view with `latitude` `lat`
- **THEN** the answer is 400 and the message names `lat`

### Requirement: Anyone reads a view's data while the publication is public (REQ-DSV-002)

`GET /api/{catalogSlug}/{publicationId}/tables/{tableId}/views` and `GET .../views/{viewId}/data` SHALL be public routes with CORS. The data MUST be rows for `table`, grouped values for `bar` and `line` (at most 200 groups, with `truncated`), and a GeoJSON FeatureCollection of at most 5,000 points for `map` (with `skipped`). Both routes MUST answer 404 unless the publication is publicly readable at that moment. The public publication answer SHALL list each table's views with their data URLs.

#### Scenario: A chart answers grouped counts
<!-- @e2e exclude Public API contract; proven by DatasetViewControllerTest::testABarViewAnswersOneValuePerGroup. -->

- **GIVEN** the public publication with the `bar` view of playgrounds per district
- **WHEN** an anonymous reader asks for the view's data
- **THEN** the answer holds one point per district with the number of playgrounds in it

#### Scenario: A map leaves out rows without coordinates
<!-- @e2e exclude Public API contract; proven by DatasetViewControllerTest::testAMapViewSkipsRowsWithoutCoordinates. -->

- **GIVEN** a `map` view over a table where 3 rows have an empty `lat`
- **WHEN** an anonymous reader asks for the view's data
- **THEN** the answer is a FeatureCollection without those rows and `skipped` is 3

#### Scenario: More than 200 groups are cut off and say so
<!-- @e2e exclude Public API contract; proven by DatasetViewControllerTest::testMoreThan200GroupsAnswersTruncated. -->

- **GIVEN** a `line` view whose column `jaar` has 201 distinct values
- **WHEN** an anonymous reader asks for the view's data
- **THEN** the answer holds the first 200 points, grouped by OpenRegister's aggregation, and `truncated` is true

#### Scenario: A view of a withdrawn publication is not served
<!-- @e2e exclude Fail-closed path; proven by DatasetViewControllerTest::testAViewOfANonPublicPublicationIs404. -->

- **GIVEN** a publication with a view that has since been withdrawn
- **WHEN** an anonymous reader asks for the view's data
- **THEN** the answer is 404

### Requirement: The public page draws each view and offers its data as a table (REQ-DSV-003)

portaliq's publication block SHALL draw each view under its table: a paged table, an SVG chart in NL Design System tokens, or a map. Every chart and map MUST offer "Show as table" with the same data, so a reader who cannot see the chart gets the same facts.

#### Scenario: A reader switches a chart to a table
<!-- @e2e exclude Rendered by portaliq; proven by portaliq's tests/e2e/publication-dataset-views.spec.ts (cross-repo task 4.3). -->

- **GIVEN** a public publication with a `bar` view
- **WHEN** a reader opens the publication on the portal and chooses "Show as table"
- **THEN** a table lists each group with its value
