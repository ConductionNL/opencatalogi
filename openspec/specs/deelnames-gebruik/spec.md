# Deelnames Gebruik Specification

## Purpose
Defines how usage objects (gebruiksobjecten) with participant organizations (deelnemers) are queried, enriched, and displayed alongside regular organization-owned modules on GEMMA views.

## ADDED Requirements

### Requirement: ViewService MUST retrieve deelnames gebruik separately from regular gebruik
The ViewService MUST perform a two-phase retrieval: regular gebruik filtered by RBAC, then deelnames gebruik with RBAC disabled filtering by the `deelnemers` field.

#### Scenario: Organization has both owned and shared gebruik
- GIVEN organization A owns 5 gebruiksobjecten
- AND organization A appears in the `deelnemers` field of 3 gebruiksobjecten owned by organization B
- WHEN the view is requested with `include_gebruik=true` and `include_deelnames_gebruik=true`
- THEN the response MUST include module overlay nodes for all 5 owned gebruiksobjecten
- AND the response MUST include module overlay nodes for the 3 shared gebruiksobjecten
- AND shared module nodes MUST be marked with `_type: "deelnames"`

#### Scenario: Organization has only deelnames gebruik
- GIVEN organization A owns 0 gebruiksobjecten
- AND organization A appears in `deelnemers` of 2 gebruiksobjecten
- WHEN the view is requested with `include_deelnames_gebruik=true`
- THEN the response MUST include module overlay nodes for the 2 shared gebruiksobjecten
- AND no error MUST be returned for the empty regular gebruik result

#### Scenario: Deelnames flag is not set
- GIVEN organization A appears in `deelnemers` of 3 gebruiksobjecten
- WHEN the view is requested with `include_gebruik=true` but WITHOUT `include_deelnames_gebruik`
- THEN the response MUST NOT include the 3 shared gebruiksobjecten
- AND only directly owned gebruik MUST be included

### Requirement: Deelnames gebruik MUST be queried with RBAC disabled
Deelnames gebruiksobjecten are owned by other organizations, so the query MUST bypass RBAC to find records where the current organization appears in the `deelnemers` array.

#### Scenario: Deelnames query bypasses RBAC
- GIVEN organization A is searching for deelnames gebruik
- WHEN the ObjectService search is executed for deelnames
- THEN the search MUST be called with `_rbac: false`
- AND the query MUST filter on `deelnemers` containing organization A's UUID

### Requirement: Gebruiksobjecten MUST support the deelnemers field
Gebruiksobjecten MUST have a `deelnemers` field that contains an array of participating organization identifiers.

#### Scenario: Gebruiksobject with deelnemers array of UUIDs
- GIVEN a gebruiksobject with `deelnemers: ["uuid-org-a", "uuid-org-b"]`
- WHEN organization A queries for deelnames gebruik
- THEN this gebruiksobject MUST be returned in the results

#### Scenario: Gebruiksobject with deelnemers array of objects
- GIVEN a gebruiksobject with `deelnemers: [{"id": "uuid-org-a", "name": "Org A"}]`
- WHEN organization A queries for deelnames gebruik
- THEN this gebruiksobject MUST be returned in the results

#### Scenario: Gebruiksobject without deelnemers field
- GIVEN a gebruiksobject without a `deelnemers` field
- WHEN any organization queries for deelnames gebruik
- THEN this gebruiksobject MUST NOT be returned in deelnames results
- AND it MAY still appear in regular gebruik results if the querying organization owns it

### Requirement: Test data MUST include gebruiksobjecten with deelnemers
The development environment MUST have test data demonstrating the deelnames flow.

#### Scenario: Test data exists for deelnames verification
- GIVEN the development environment
- WHEN a developer wants to test the deelnames feature
- THEN there MUST be at least one gebruiksobject owned by organization X with `deelnemers` containing organization Y
- AND organization Y MUST be a different organization than X
- AND the gebruiksobject MUST reference a module linked to referentiecomponenten on at least one view

### Requirement: Performance testing MUST use the organization with most applications
After implementation, the system MUST be tested with the organization that has the highest number of applications and gebruiksobjecten set as active.

#### Scenario: Performance test with largest organization
- GIVEN the organization with the most applications is set as active
- WHEN the BBN poster view (388+ base nodes) is loaded with all enrichment flags enabled
- THEN the total render time (including overlay nodes) MUST be under 3 seconds
- AND the page MUST remain interactive (no browser freeze)

## MODIFIED Requirements

_None — this is a new capability._

## REMOVED Requirements

_None._
