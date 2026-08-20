# Tasks: publications

## Phase 1 — Deduplication check

- [ ] 1. **Deduplication check**: search `openspec/specs/` and `lib/Service/` for any capability that overlaps with `findObjectLocation` (cross-schema UUID scan) and `extractFilterValues` (filter normalisation). Document findings in a comment on this task. If no overlap is found, record "no overlap found". If overlap is found, evaluate whether the helper should be extracted to `PublicationService` or another shared service rather than remaining a private controller method.
  - **Files to check**: `lib/Service/PublicationService.php`, `lib/Service/CatalogiService.php`, OpenRegister `lib/Service/ObjectService.php`
  - **Acceptance criteria**: findings documented; any extractable helper identified and tracked as a follow-up issue.

## Phase 2 — Route ordering verification

- [ ] 2. **Verify wildcard route placement** (`appinfo/routes.php`): confirm that all `{catalogSlug}` routes (GET, OPTIONS) are declared after every specific named route (`/api/themes`, `/api/glossary`, etc.).
  - **Spec ref**: `specs/publications/spec.md` REQ-PUB-012
  - **Acceptance criteria**: a grep or automated test confirms no specific named route appears after the first `{catalogSlug}` route in `routes.php`. Route-ordering regression test added to the PHPUnit suite.

## Phase 3 — CORS header verification

- [ ] 3. **Verify CORS headers on all six endpoint variants**: make real HTTP requests (curl or PHPUnit HTTP test) to each of the six public endpoints and confirm `Access-Control-Allow-Origin` and friends are present on both GET and OPTIONS responses.
  - **Spec ref**: `specs/publications/spec.md` REQ-PUB-010
  - **Endpoints**: `/{slug}`, `/{slug}/{id}`, `/{slug}/{id}/attachments`, `/{slug}/{id}/download`, `/{slug}/{id}/uses`, `/{slug}/{id}/used`
  - **Acceptance criteria**: all 12 responses (6 × GET + 6 × OPTIONS) carry required CORS headers. Missing headers are fixed in `PublicationsController`.

## Phase 4 — findObjectLocation coverage

- [ ] 4. **Add unit test for `findObjectLocation` — object found**: mock `IDBConnection` to return a row from the UNION ALL query; assert the method returns `['register' => int, 'schema' => int]`.
  - **Spec ref**: REQ-PUB-002, REQ-PUB-014
  - **File**: `tests/Unit/Controller/PublicationsControllerTest.php`

- [ ] 5. **Add unit test for `findObjectLocation` — object not found**: mock `IDBConnection` to return zero rows; assert the method returns `null`.
  - **Spec ref**: REQ-PUB-014
  - **Acceptance criteria**: test passes; controller returns `404` when `findObjectLocation` returns null.

- [ ] 6. **Add unit test for `findObjectLocation` — table name parsing**: assert that tables named `oc_openregister_table_3_7` are correctly parsed as `register_id = 3`, `schema_id = 7`.
  - **Spec ref**: REQ-PUB-014

## Phase 5 — extractFilterValues coverage

- [ ] 7. **Add unit tests for `extractFilterValues` covering all seven input formats**:
  - Single numeric (`1` → `[1]`)
  - Simple array (`[1,2,3]` → `[1,2,3]`)
  - OR array (`{ "or": [1,2,3] }` → `[1,2,3]`)
  - OR string (`{ "or": "1,2,3" }` → `[1,2,3]`)
  - AND array (`{ "and": [1,2] }` → `[1,2]`)
  - AND string (`{ "and": "1,2" }` → `[1,2]`)
  - Comma-separated string (`"2,5,7"` → `[2,5,7]`)
  - **Spec ref**: REQ-PUB-013
  - **File**: `tests/Unit/Controller/PublicationsControllerTest.php`
  - **Acceptance criteria**: all seven formats produce correct integer arrays; existing `[or]` behaviour for tilburg-woo-ui confirmed.

## Phase 6 — Multi-schema ordering restriction

- [ ] 8. **Add test for universal-ordering restriction on multi-schema catalogs**: pass a non-universal `_order` parameter (e.g., `_order[naam]=asc`) to the list endpoint for a catalog with two schemas; assert the parameter is stripped and the request succeeds without a 500 or malformed query.
  - **Spec ref**: REQ-PUB-004
  - **Acceptance criteria**: non-universal order fields are absent from the `searchObjectsPaginated` call; universal fields (`uuid`, `created`, `updated`, `published`, `depublished`) are passed through unchanged.

## Phase 7 — 404 error response quality

- [ ] 9. **Verify 404 responses contain `message` and no internal details**: for both the "unknown slug" and "unknown UUID" cases, assert the response body has a `message` key and does NOT include stack traces, SQL, or internal paths.
  - **Spec ref**: REQ-PUB-011
  - **Acceptance criteria**: existing PHPUnit assertions confirm `$response->getData()['message']` is set; `assertStringNotContainsString('SELECT', ...)` guard added.

## Phase 8 — Seed data

- [ ] 10. **Add seed data to `lib/Settings/publication_register.json`**: add the 5 Dutch publication objects defined in `design.md` (Seed Data section) under `components.objects[]`. Each object must use the `@self` envelope with matching `register`, `schema`, and a unique `slug`.
  - **Spec ref**: ADR-001 seed data requirements
  - **Files**: `lib/Settings/publication_register.json`
  - **Acceptance criteria**:
    - `importFromApp()` imports all 5 objects without error on a clean install.
    - Re-import (idempotency) skips objects whose slug already exists.
    - After import, the admin UI shows 5 publications in the default catalog.

## Phase 9 — @spec traceability

- [ ] 11. **Add `@spec` PHPDoc tags** to `PublicationsController` and `PublicationService` linking to this change:
  - File-level header docblock: `@spec openspec/changes/publications/tasks.md`
  - `list()` method: `@spec openspec/changes/publications/tasks.md#task-2` (REQ-PUB-001)
  - `show()` method: `@spec openspec/changes/publications/tasks.md#task-4` (REQ-PUB-002)
  - `findObjectLocation()`: `@spec openspec/changes/publications/tasks.md#task-4` (REQ-PUB-014)
  - `extractFilterValues()`: `@spec openspec/changes/publications/tasks.md#task-7` (REQ-PUB-013)
  - **Spec ref**: ADR-003 backend spec-traceability requirement
  - **Files**: `lib/Controller/PublicationsController.php`, `lib/Service/PublicationService.php`

## Phase 10 — Spec sync

- [ ] 12. **Sync delta spec to canonical spec**: after all tasks above are verified, run `/opsx:sync` to fold `openspec/changes/publications/specs/publications/spec.md` back into `openspec/specs/publications/spec.md` and confirm the canonical spec is up-to-date.
  - **Acceptance criteria**: `openspec/specs/publications/spec.md` reflects all REQ-PUB-001 through REQ-PUB-015 entries with their final implementation notes.

## Phase 11 — tilburg-woo-ui compatibility

- [ ] 13. **Test with tilburg-woo-ui**: start a local OpenCatalogi dev environment, point tilburg-woo-ui at the local instance, and confirm:
  - Publication list loads with pagination and facets.
  - `?schemas[or]=…` filter syntax returns correct results.
  - Single publication detail page loads including attached files.
  - Relation traversal (`/uses`, `/used`) returns expected objects.
  - No CORS errors appear in the browser console.
  - **Acceptance criteria**: all five checks pass; any regression is filed as a follow-up bug.
