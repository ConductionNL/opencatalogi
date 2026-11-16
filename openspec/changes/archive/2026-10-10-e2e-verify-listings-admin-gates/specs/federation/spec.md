# Federation — Admin-Gate Verification Delta

**Spec refs**: `openspec/specs/dashboard/spec.md` (LST-004, LST-005), sibling change
`harden-listings-admin-write-surface`

## MODIFIED Requirements

### Requirement: Update and delete listing reject non-admin callers (LST-004, LST-005)

The system MUST reject `PUT /api/listings/{id}` and `DELETE /api/listings/{id}` requests from an
authenticated non-admin user, and this rejection MUST be verified by a real end-to-end HTTP
request (Newman or equivalent) against a non-admin-authenticated session — not solely by a
PHPUnit test that calls the controller method directly, since `#[AuthorizedAdminSetting]`
enforcement happens in Nextcloud's `SecurityMiddleware`, in front of the controller, and a direct
method call bypasses it entirely.

**Feature tier**: MVP

#### Scenario: Non-admin update is rejected end-to-end

- GIVEN an existing listing and a Nextcloud user authenticated but not in the `admin` group
- WHEN that session sends `PUT /apps/opencatalogi/api/listings/{id}` with changed fields
- THEN the response MUST be `403`
- AND a follow-up GET on the same listing MUST show the fields unchanged

#### Scenario: Non-admin delete is rejected end-to-end

- GIVEN an existing listing and a Nextcloud user authenticated but not in the `admin` group
- WHEN that session sends `DELETE /apps/opencatalogi/api/listings/{id}`
- THEN the response MUST be `403`
- AND a follow-up GET MUST show the listing still exists

#### Scenario: Admin update and delete continue to succeed

- GIVEN an existing listing and an admin-authenticated session
- WHEN that session sends `PUT` then `DELETE` against `/apps/opencatalogi/api/listings/{id}`
- THEN both requests MUST succeed as today (no behavior change for admins)
