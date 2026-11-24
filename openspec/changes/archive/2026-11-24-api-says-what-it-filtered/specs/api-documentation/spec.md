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
