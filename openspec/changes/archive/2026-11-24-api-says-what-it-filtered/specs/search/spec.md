---
status: proposed
---

# Search

## ADDED Requirements

### Requirement: Every list response says what the server filtered on its own (REQ-ASF-001)

Every JSON list and search response of the public API SHALL carry `appliedFilters`, a list of `{name, value, reason}`. It SHALL hold one entry for each of these that applied to the request, and no entry for one that did not:

| name | when | value |
|---|---|---|
| `catalogScope` | the catalogue's configured schemas and registers narrowed the query | `{schemas: [...], registers: [...]}` as slugs |
| `publicOnly` | the query ran as the anonymous public reader | `true` |
| `archivedExcluded` | rows with `status: archived` were dropped (RET-006) | the number dropped on this page |
| `callerScopeIgnored` | `CallerScope::strip()` removed caller-supplied scope keys | the removed key names |

The routes SHALL be: `GET /api/search`, `POST /api/publications/search`, `GET /api/{catalogSlug}`, `GET /api/themes`, `GET /api/federation/publications` and every other route of `PublicationsController`, `SearchController`, `ThemesController` and `FederationController` that answers a list. `reason` SHALL be a short English sentence; the entry names are stable API.

#### Scenario: The response says the catalogue was narrowed
<!-- @e2e exclude Public API contract; proven by AppliedFiltersResponseTest::testTheCatalogueListReportsItsScope, which fails on today's code because no response carries appliedFilters. -->

- **GIVEN** a catalogue `woo` scoped to the schemas `publication` and `besluit`
- **WHEN** an anonymous caller asks `GET /api/woo`
- **THEN** `appliedFilters` holds `catalogScope` with those two schemas and `publicOnly` true

#### Scenario: Archived rows are counted
<!-- @e2e exclude Public API contract; proven by AppliedFiltersResponseTest::testArchivedRowsDroppedAreReported. -->

- **GIVEN** a search whose page from OpenRegister holds two archived publications
- **WHEN** the response is assembled
- **THEN** `appliedFilters` holds `archivedExcluded` with value 2

#### Scenario: Nothing extra is claimed
<!-- @e2e exclude Public API contract; proven by AppliedFiltersResponseTest::testAFilterThatDidNotApplyIsNotListed. -->

- **GIVEN** a search with no archived rows and no caller scope keys
- **WHEN** the response is assembled
- **THEN** `appliedFilters` holds neither `archivedExcluded` nor `callerScopeIgnored`

### Requirement: A filter is reported where it is applied (REQ-ASF-002)

A request-scoped collector `OCA\OpenCatalogi\Service\AppliedFilters` with `record(string $name, mixed $value, string $reason): void` and `all(): array` SHALL be called at the site of each filter: where the catalogue scope is set, inside `evaluateAsAnonymous()`, at the RET-006 drop, and inside `CallerScope::strip()` (which SHALL report the keys it removed). The controllers SHALL read `all()` into the response. When building the list fails after filters ran, the error response SHALL NOT carry `appliedFilters`. When the collector throws, the response SHALL carry `[{name: "unknown", value: null, reason: "not recorded"}]`.

#### Scenario: A caller's scope is ignored and said so
<!-- @e2e exclude Public API contract; proven by AppliedFiltersResponseTest::testAStrippedCallerScopeIsReported. -->

- **GIVEN** a catalogue `woo`
- **WHEN** a caller asks `GET /api/woo?_schemas[]=secret`
- **THEN** the results are the catalogue's own
- **AND** `appliedFilters` holds `callerScopeIgnored` with the value `["_schemas"]`

#### Scenario: A failing collector is not read as nothing filtered
<!-- @e2e exclude Fail-closed path; proven by AppliedFiltersResponseTest::testAFailingCollectorReportsUnknown. -->

- **GIVEN** a collector that throws
- **WHEN** a list is requested
- **THEN** `appliedFilters` is the single `unknown` entry
