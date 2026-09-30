# Design: search-council-document-filters

## Existing path

`SearchController::index()` passes `IRequest::getParams()` to `PublicationQueryService::assemblePublicSearchResults()`, which builds the query with `ObjectService::buildSearchQuery()`, strips caller scope parameters, applies the catalogue scope and clamps `_limit` to `PUBLIC_LIMIT_MAX` (`lib/Service/PublicationQueryService.php`). Facets are returned as `facets` and `facetable` in the envelope. Nothing in this path is specific to a schema.

## Range on `meetingDate`

OpenRegister accepts `property[gte]` and `property[lte]` on a date property (`openregister openspec/specs/zoeken-filteren/spec.md`). The task is to confirm that the strip step keeps these keys for a schema property (it strips only scope keys), and to add a guard that a malformed date answers 400 instead of an empty result.

## Contract test

`tests/Unit/Service/PublicationQuerySearchContractTest.php` loads decidiq's `108-publication-papers.json` fragment copied into `tests/fixtures` (copied, not read across repositories), creates three publications with two body names and two document types, and asserts the facet counts and the range result through `assemblePublicSearchResults()`.

## Risks

The fixture drifts from decidiq's fragment. The test names the decidiq sha it was copied from, and the drift is caught the next time decidiq changes that file and this test is refreshed.

## Found at build time (30 Sep)

- The strip step already kept `documentType`, `bodyName` and `meetingDate[gte|lte]`: the contract tests for the filters, the range and the facets passed on the base. What was missing was the 400: a malformed bound reached OpenRegister and read as "nothing found".
- The 400 guard covers a range on every property, not only `meetingDate`. A bound is an ISO date, an ISO date-time or a number; anything else answers 400 with the parameter named under `parameter`. `MalformedSearchParameterException` carries it from `SearchRangeGuard::malformedParameter()` (its own class, so `PublicationQueryService` stays under phpmd's method limits) to `SearchController::index()`.
- decidiq's `PublicationPayload` is spread over its base register and two register.d fragments (108, 109). The fixture is the merged schema, with the three file shas in `_source`, not the 108 fragment alone.
