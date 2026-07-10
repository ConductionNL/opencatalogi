# Tasks: e2e-verify-listings-admin-gates

## 1. Add non-admin negative-auth requests to the federation Newman suite

- **spec_ref**: `openspec/changes/e2e-verify-listings-admin-gates/specs/federation/spec.md#requirement-update-and-delete-listing-reject-non-admin-callers-lst-004-lst-005`
- **files**: `tests/federation/federation-tests.postman_collection.json`
- **acceptance_criteria**:
  - GIVEN a Nextcloud user that is authenticated but NOT in the `admin` group
  - WHEN that session sends `PUT /apps/opencatalogi/api/listings/{id}` (with an existing listing id from an earlier phase)
  - THEN the response status MUST be `403` and the listing's stored fields MUST be unchanged
  - WHEN that session sends `DELETE /apps/opencatalogi/api/listings/{id}`
  - THEN the response status MUST be `403` and the listing MUST still exist afterward (confirmed via a follow-up GET)
- [ ] 1.1 Add (or reuse from the existing Nextcloud dev-instance fixtures) a non-admin test user + credentials as a collection variable, alongside the existing `admin`/`admin` variables
- [ ] 1.2 Add "4.7 Non-admin update rejected (403)" and "4.8 Non-admin delete rejected (403)" requests to "Phase 4: Listing CRUD", run against the listing seeded in 3.3/4.1, using the non-admin credentials
- [ ] 1.3 Add Postman test assertions for status code `403` and (for update) a follow-up GET proving the fields are unchanged, and (for delete) a follow-up GET proving the listing still exists
- [ ] 1.4 Run the updated Newman collection against a live dev instance and confirm both new requests pass

## 2. Spec: make the non-admin-rejection behavior an explicit requirement

- **spec_ref**: `openspec/changes/e2e-verify-listings-admin-gates/specs/federation/spec.md#requirement-update-and-delete-listing-reject-non-admin-callers-lst-004-lst-005`
- **files**: `openspec/specs/dashboard/spec.md`
- **acceptance_criteria**:
  - GIVEN the delta is synced THEN LST-004 and LST-005 each carry a non-admin-rejection scenario in addition to their existing "authenticated user" scenario
- [ ] 2.1 Run `/opsx-sync` (or manual sync) after 1.4 passes to fold the delta spec into `openspec/specs/dashboard/spec.md`

## 3. Narrow the blanket e2e exclusion so it stops over-claiming coverage

- **spec_ref**: `openspec/changes/e2e-verify-listings-admin-gates/specs/federation/spec.md#requirement-update-and-delete-listing-reject-non-admin-callers-lst-004-lst-005`
- **files**: `openspec/specs/dashboard/spec.md`
- **acceptance_criteria**:
  - GIVEN the `@e2e exclude` note at the top of the Dashboard and Directory spec THEN it no longer implies the Newman suite covers the admin-vs-non-admin authorization boundary (since Task 1 makes that true, this is a wording tightening, not a removal, unless Task 1 is deferred — in which case the exclusion text MUST be corrected to admit the gap instead of asserting coverage that does not exist)
- [ ] 3.1 Update the `@e2e exclude` line (`openspec/specs/dashboard/spec.md:25`) to read something like: "...listing/directory CRUD *data-shape* behaviours, SPA-serving, CSP, bootstrap registration, and aggregation data-sourcing are backend/HTTP-contract behaviours covered by Newman API tests and PHPUnit; the admin-vs-non-admin authorization boundary on listing writes is covered by the non-admin-rejection Newman requests added in `e2e-verify-listings-admin-gates`."

## 4. Cross-reference for the sibling authorization-hardening change

- **spec_ref**: n/a (coordination note, not a spec requirement)
- **files**: `openspec/changes/harden-listings-admin-write-surface/tasks.md`
- **acceptance_criteria**: n/a — informational only, no code change required by this task
- [ ] 4.1 When `harden-listings-admin-write-surface` is implemented, add the same non-admin-authenticated-HTTP Newman pattern (not PHPUnit-only) for its three newly-gated methods (`create()`, `add()`, `synchronise()`), reusing the non-admin credentials fixture added in Task 1
