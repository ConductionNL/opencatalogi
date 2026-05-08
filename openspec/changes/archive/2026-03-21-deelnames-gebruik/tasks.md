# Tasks: deelnames-gebruik

## 1. Backend — Two-Phase Query

- [x] 1.1 Implement deelnames query with `_rbac: false` and `_multitenancy: false` in ViewService
- [x] 1.2 Add `include_deelnames_gebruik` parameter to enrichment API endpoint
- [x] 1.3 Implement deduplication logic (module ID + referentiecomponent key, owned wins)

## 2. Frontend — Filter Toggle

- [x] 2.1 Add independent deelnames toggle to view filter panel
- [x] 2.2 Wire toggle to re-fetch with `include_deelnames_gebruik=true`
- [x] 2.3 Default toggle to disabled on initial load

## 3. Module Overlay — Source Metadata

- [x] 3.1 Add `_sourceOrganization` and `_sourceOrganizationId` to deelnames module nodes
- [x] 3.2 Display source organization in tooltip for deelnames nodes
- [x] 3.3 Visual distinction for deelnames vs owned nodes in node lists

## 4. Unit Tests (ADR-009)

- [x] 4.1 Test two-phase query returns both owned and deelnames results
- [x] 4.2 Test deduplication removes duplicate module-referentiecomponent pairs
- [x] 4.3 Test deelnames query failure falls back gracefully

## 5. Documentation (ADR-010)

- [x] 5.1 Feature documentation at docs/features/deelnames-gebruik.md

## 6. Internationalization (ADR-005)

- [x] 6.1 Frontend toggle label translatable in nl/en
