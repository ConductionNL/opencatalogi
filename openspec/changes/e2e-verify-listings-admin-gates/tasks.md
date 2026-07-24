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
- [x] 1.1 Added `nonAdminUsername`/`nonAdminPassword` collection variables
      (`federation-tests.postman_collection.json`), and provisioning in
      `run-federation-tests.sh` (`provision_non_admin_user()`, `occ user:add`
      with no `admin` group membership) + threaded through to `newman run`
      via `--env-var`.
- [x] 1.2 Added "4.7 Non-admin update rejected (403)" and "4.8 Non-admin
      delete rejected (403)" to "Phase 4: Listing CRUD", targeting
      `seededListingId` (the listing still alive at that point in the
      sequence — `manualListingId` is already deleted by 4.4) with a
      per-request `auth` override using the non-admin credentials.
- [x] 1.3 Added `4.7b`/`4.8b` follow-up GET assertions: `4.7b` asserts the
      seeded listing's title is unchanged (not the rejected PUT's payload)
      after the 403; `4.8b` asserts the seeded listing still exists after the
      403'd DELETE.
- [ ] 1.4 DEFERRED — running Newman against a live two-instance federation
      environment requires `docker compose -f docker-compose.federation.yml`
      up with real network I/O, which is out of scope for this isolated,
      no-deploy worktree. JSON syntax was validated
      (`php -r 'json_decode(...)'`) and the shell script was syntax-checked
      (`bash -n`); the actual Newman run against a live instance still needs
      to happen before this change can be considered fully verified.

## 2. Spec: make the non-admin-rejection behavior an explicit requirement

- **spec_ref**: `openspec/changes/e2e-verify-listings-admin-gates/specs/federation/spec.md#requirement-update-and-delete-listing-reject-non-admin-callers-lst-004-lst-005`
- **files**: `openspec/specs/dashboard/spec.md`
- **acceptance_criteria**:
  - GIVEN the delta is synced THEN LST-004 and LST-005 each carry a non-admin-rejection scenario in addition to their existing "authenticated user" scenario
- [x] 2.1 Manually synced: LST-004 and LST-005 in `openspec/specs/dashboard/spec.md`
      now each carry a "non-admin ... is rejected" scenario alongside their
      existing authenticated-admin scenario (synced ahead of 1.4 since the
      spec wording change doesn't depend on the live Newman run passing —
      the requirement text now correctly describes intent; the live-run
      checkbox above tracks the runtime proof separately).

## 3. Narrow the blanket e2e exclusion so it stops over-claiming coverage

- **spec_ref**: `openspec/changes/e2e-verify-listings-admin-gates/specs/federation/spec.md#requirement-update-and-delete-listing-reject-non-admin-callers-lst-004-lst-005`
- **files**: `openspec/specs/dashboard/spec.md`
- **acceptance_criteria**:
  - GIVEN the `@e2e exclude` note at the top of the Dashboard and Directory spec THEN it no longer implies the Newman suite covers the admin-vs-non-admin authorization boundary (since Task 1 makes that true, this is a wording tightening, not a removal, unless Task 1 is deferred — in which case the exclusion text MUST be corrected to admit the gap instead of asserting coverage that does not exist)
- [x] 3.1 Updated the `@e2e exclude` line in `openspec/specs/dashboard/spec.md`
      (Dashboard and Directory `## Purpose`) to the suggested wording,
      narrowing "listing/directory CRUD" to "*data-shape*" and adding the
      explicit non-admin-boundary coverage pointer to 4.7/4.8.

## 4. Cross-reference for the sibling authorization-hardening change

- **spec_ref**: n/a (coordination note, not a spec requirement)
- **files**: `openspec/changes/harden-listings-admin-write-surface/tasks.md`
- **acceptance_criteria**: n/a — informational only, no code change required by this task
- [x] 4.1 `harden-listings-admin-write-surface` was implemented in this same
      session (its `create()`/`add()`/`synchronise()` are now
      `#[AuthorizedAdminSetting]`-gated). Added the equivalent non-admin
      negative-auth Newman requests: "4.9 Non-admin create rejected (403)",
      "4.10 Non-admin add-directory rejected (403)", "4.11 Non-admin sync
      rejected (403)", reusing the same `nonAdminUsername`/`nonAdminPassword`
      fixture. Same 1.4-style caveat applies: JSON-validated, not yet run
      against a live instance.
