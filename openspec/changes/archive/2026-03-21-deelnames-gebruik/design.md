# Design: deelnames-gebruik

## Context

Defines how usage objects (gebruiksobjecten) with participant organizations (deelnemers) are queried and displayed alongside regular organization-owned modules on GEMMA views. Built on OpenRegister's ObjectService with RBAC/multitenancy bypass for cross-organization queries.

## Goals / Non-Goals

**Goals:**
- Two-phase retrieval: owned gebruik (RBAC-enabled) + deelnames gebruik (RBAC disabled)
- Deduplication of overlapping owned/deelnames results
- Source organization metadata on deelnames nodes
- Independent frontend toggle for deelnames filtering

**Non-Goals:**
- Write operations on deelnemers from OpenCatalogi (managed in source system)
- Real-time synchronization of deelnemers changes

## Decisions

1. Deelnames query uses `_rbac: false` and `_multitenancy: false` to find records across organizations
2. Deduplication key: module ID + referentiecomponent ID (owned wins over deelnames)
3. Deelnames toggle is independent and disabled by default

## File Changes

- Frontend ViewService — two-phase query logic
- Frontend filter panel — deelnames toggle
- Module overlay rendering — source organization metadata display
- View enrichment API — `include_deelnames_gebruik` parameter support
