# dashboard Specification (delta)

**Status**: proposed
**Scope**: opencatalogi
**OpenSpec changes**:
- harden-listings-admin-write-surface

## Purpose

Close the authorization asymmetry on the federation listing write surface:
creation, URL-based registration and on-demand synchronisation of listings are
federation-topology changes and MUST be admin-gated and (for creation)
allow-listed, matching the wave-12 hardening already applied to update and
delete.

## MODIFIED Requirements

### Requirement: Create a new listing (admin-only, allow-listed) (LST-003)

The system MUST allow creating a new listing. Creating a listing is a
federation-topology change: the endpoint MUST be admin-gated via
`#[AuthorizedAdminSetting]` (delegated-admin auditable), and the payload MUST
be filtered through a `CREATABLE_LISTING_FIELDS` allow-list so server-managed
sync state (`statusCode`, `lastSync`, `available`) can never be set by the
caller. When the payload contains a `directory` URL, the URL MUST pass the
same outbound-safety validation (`FILTER_VALIDATE_URL` +
`assertSafeOutboundUrl`) that `syncDirectory()` applies, and the request MUST
be rejected with `400` when it does not.

#### Scenario: admin creates a listing
- GIVEN an authenticated admin with allow-listed listing data
- WHEN a POST request is made to `/api/listings`
- THEN a new listing MUST be created and returned

#### Scenario: non-admin creation is rejected
- GIVEN an authenticated non-admin user
- WHEN a POST request is made to `/api/listings`
- THEN the request MUST be rejected by the admin guard
- AND no listing MUST be created

#### Scenario: off-list fields are dropped
- GIVEN an authenticated admin
- WHEN a POST request is made to `/api/listings` including `statusCode`, `lastSync` or `available`
- THEN those fields MUST NOT be persisted on the created listing

#### Scenario: unsafe directory URL is rejected
- GIVEN an authenticated admin
- WHEN a POST request is made to `/api/listings` with a `directory` URL that resolves to a private, loopback, link-local or metadata address
- THEN the response MUST be `400`
- AND no listing MUST be created

### Requirement: Add a new listing from a URL (admin-only) (DIR-005)

The system MUST allow an authenticated admin to add a new listing from a URL.
The admin requirement MUST be enforced by the controller via
`#[AuthorizedAdminSetting]` — a session-only guard (any authenticated user) is
NOT sufficient. Anonymous requests MUST be rejected with `403`. The
cross-instance broadcast-receive path remains the separate public
`POST /api/directory` endpoint (DIR-008); do not merge the two.

#### Scenario: admin adds a listing from a URL
- GIVEN a directory or publications URL
- AND an authenticated admin session
- WHEN a POST request is made to `/api/listings/add` with that URL
- THEN a listing MUST be created from the URL

#### Scenario: non-admin caller is rejected
- GIVEN a directory or publications URL
- AND an authenticated non-admin session
- WHEN a POST request is made to `/api/listings/add` with that URL
- THEN the request MUST be rejected by the admin guard
- AND no listing MUST be created

#### Scenario: unauthenticated caller is rejected
- GIVEN a directory or publications URL
- AND no user session
- WHEN a POST request is made to `/api/listings/add` with that URL
- THEN the response MUST be `403 Forbidden`
- AND no listing MUST be created

### Requirement: Synchronize a specific listing's directory (admin-only) (DIR-003)

The system MUST allow synchronising a specific listing's directory (or all
directories when no id is given) via `POST /api/listings/sync`. On-demand
synchronisation triggers outbound HTTP fetches and MUST be admin-gated via
`#[AuthorizedAdminSetting]`; scheduled synchronisation for all users is
provided by the hourly cron (DIR-004), which does not pass through this
endpoint.

#### Scenario: admin syncs a listing's directory
- GIVEN an existing listing with a directory URL
- AND an authenticated admin session
- WHEN a POST request is made to `/api/listings/sync` with the listing id
- THEN that directory MUST be synchronised and the results returned

#### Scenario: non-admin sync is rejected
- GIVEN an authenticated non-admin session
- WHEN a POST request is made to `/api/listings/sync`
- THEN the request MUST be rejected by the admin guard
- AND no outbound fetch MUST be made
