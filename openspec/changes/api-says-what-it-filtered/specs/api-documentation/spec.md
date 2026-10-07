---
status: proposed
---

# API documentation

## ADDED Requirements

### Requirement: The OpenAPI document describes appliedFilters on every list response (REQ-ASF-004)

`openapi.json` SHALL declare a component schema `AppliedFilters` (array of `{name, value, reason}` with `name` an enum of the names in REQ-ASF-001 plus `unknown`) and SHALL reference it from every list and search response the routes of REQ-ASF-001 answer. `tests/Unit/OpenApiParityTest.php` SHALL fail when a list route's documented response lacks it.

#### Scenario: A documented list response lacks appliedFilters
<!-- @e2e exclude Documentation contract; proven by OpenApiParityTest::testEveryListResponseDocumentsAppliedFilters, which fails on today's document. -->

- **GIVEN** `openapi.json`
- **WHEN** the parity test reads every public list route's response schema
- **THEN** each references `AppliedFilters`

### Requirement: Integrator-only parameters are marked (REQ-ASF-003)

Gated on decision D10: build only once Ruben keeps row 13.26.

`openapi.json` SHALL mark every query parameter of a public route that no screen in `src/` or portaliq sends with `x-integrator-only: true` and a description starting "Integrator-only:". A drift test SHALL collect the parameters the frontend sends (from `src/` request builders) and SHALL fail on a documented parameter that is neither sent by a screen nor marked.

#### Scenario: An unmarked integrator-only parameter fails the build

- **GIVEN** a public route documenting a parameter `sourceIdentifier` that no screen sends and that carries no marker
- **WHEN** the drift test runs
- **THEN** it fails naming the route and the parameter
