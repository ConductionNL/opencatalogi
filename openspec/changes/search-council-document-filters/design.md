# Design: search-council-document-filters

## Existing path

`SearchController::index()` passes `IRequest::getParams()` to `PublicationQueryService::assemblePublicSearchResults()`, which builds the query with `ObjectService::buildSearchQuery()`, strips caller scope parameters, applies the catalogue scope and clamps `_limit` to `PUBLIC_LIMIT_MAX` (`lib/Service/PublicationQueryService.php`). Facets are returned as `facets` and `facetable` in the envelope. Nothing in this path is specific to a schema.

## Range on `meetingDate`

OpenRegister accepts `property[gte]` and `property[lte]` on a date property (`openregister openspec/specs/zoeken-filteren/spec.md`). The task is to confirm that the strip step keeps these keys for a schema property (it strips only scope keys), and to add a guard that a malformed date answers 400 instead of an empty result.

## Contract test

`tests/Unit/Service/PublicationQuerySearchContractTest.php` loads decidiq's `108-publication-papers.json` fragment copied into `tests/fixtures` (copied, not read across repositories), creates three publications with two body names and two document types, and asserts the facet counts and the range result through `assemblePublicSearchResults()`.

## Risks

The fixture drifts from decidiq's fragment. The test names the decidiq sha it was copied from, and the drift is caught the next time decidiq changes that file and this test is refreshed.
