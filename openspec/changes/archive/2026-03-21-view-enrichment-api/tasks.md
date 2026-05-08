# Tasks: view-enrichment-api

## 1. Frontend Migration

- [x] 1.1 Replace direct OpenRegister calls with enrichment API calls for view data
- [x] 1.2 Map frontend filter toggles to backend enrichment parameters
- [x] 1.3 Update endpoint constants to enrichment API URLs

## 2. Backend

- [x] 2.1 Enrichment API returns standard viewNode format with enrichment metadata
- [x] 2.2 Support organization context parameter
- [x] 2.3 Handle 404 for non-existent views and 500 for server errors

## 3. Unit Tests (ADR-009)

- [x] 3.1 Test enrichment API returns enriched viewNode format
- [x] 3.2 Test non-existent view returns 404

## 4. Documentation (ADR-010)

- [x] 4.1 Feature documentation at docs/features/view-enrichment-api.md

## 5. Internationalization (ADR-005)

- [x] 5.1 Error messages translatable — N/A (API returns standard error codes)
