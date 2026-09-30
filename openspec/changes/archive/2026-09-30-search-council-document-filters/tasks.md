# Tasks: search-council-document-filters

## 1. Filters and range

- [x] 1.1 Confirm the strip step keeps `documentType`, `bodyName` and `meetingDate[gte|lte]`, and add a 400 for a malformed date (REQ-SCF-002). Verify: `tests/Unit/Service/PublicationQueryServiceTest.php` with the three filters and one bad date.
- [x] 1.2 Add the contract test with the copied decidiq fragment (REQ-SCF-001, REQ-SCF-003). Verify: `tests/Unit/Service/PublicationQuerySearchContractTest.php`, real fragment, real payload.

## 2. Docs

- [x] 2.1 Document the filters and facets in `docs/` and in the API description, English and Dutch strings for any new error message, `openspec validate search-council-document-filters --strict`.
