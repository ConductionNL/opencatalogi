---
kind: code
depends_on: []
---

# Proposal: harden-listings-admin-write-surface

## Why

The federation listing write surface was hardened in wave-12 (SB1/WF1, WOO-513)
— but only half of it. At HEAD the four write paths on
`lib/Controller/ListingsController.php` have inconsistent authorization
postures, and two of them contradict the app's own spec:

- **`create()` is any-authenticated-user and has NO field allow-list**
  (`lib/Controller/ListingsController.php:268-300`): it carries
  `@NoAdminRequired` and passes the raw request body straight to
  `saveObject()` after unsetting only `id`/`_route` (lines 280-295). Meanwhile
  `update()` is admin-gated via `#[AuthorizedAdminSetting]` AND allow-listed
  through `UPDATABLE_LISTING_FIELDS` precisely because — per the const's own
  docblock at `ListingsController.php:302-305` — "Directory-identity URLs and
  server-managed sync state … stay off deliberately: rebinding them would
  revive the federation-SSRF vector WOO-513 hardened." Any authenticated
  non-admin can therefore do via POST what the PUT hardening forbids: register
  a listing with an arbitrary `directory` URL (a federation-topology change),
  which the hourly cron sync (`DirectoryService::doCronSync()`) will then
  fetch. The outbound fetch itself is SSRF-guarded by
  `assertSafeOutboundUrl()` (`lib/Service/DirectoryService.php:425`), but the
  topology change and content injection into the local directory remain open
  to non-admins — and `destroy()` (`ListingsController.php:394`) is admin-gated
  exactly because "removing a peer is a federation-topology change". Adding
  one must be too.

- **`add()` violates DIR-005**: `openspec/specs/dashboard/spec.md` requirement
  "Add a new listing from a URL (admin-only) (DIR-005)" says "The system MUST
  allow an authenticated **admin** to add a new listing from a URL", but
  `add()` (`ListingsController.php:532-546`) carries `@NoAdminRequired` and
  only guards `getUser() === null`. Any authenticated user passes.

- **`synchronise()` is any-authenticated-user**
  (`ListingsController.php:472-520`): triggers outbound HTTP fetches
  (`syncDirectory()` / `doCronSync()`) on demand. Combined with the unguarded
  `create()`, a non-admin controls both the target URL and the fetch trigger.

## What Changes

- Gate `ListingsController::create()` with
  `#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]` (drop
  `@NoAdminRequired`), matching `update()`/`destroy()`. **BREAKING** for any
  client that created listings as a non-admin (no known frontend flow does —
  the Directory UI uses `add()`).
- Add a `CREATABLE_LISTING_FIELDS` allow-list to `create()` (the
  `UPDATABLE_LISTING_FIELDS` set plus the identity fields legitimately set at
  registration time: `directory`, `catalog`, `slug`, `status`), and validate
  the `directory` URL with the same `FILTER_VALIDATE_URL` +
  `assertSafeOutboundUrl()` posture `syncDirectory()` applies, so a listing
  can never be persisted with an unsafe outbound target.
- Enforce the DIR-005 admin requirement in `add()`: replace the
  session-only guard with `#[AuthorizedAdminSetting]`. **BREAKING** for
  non-admin users who used the "Add directory" UI — the spec already declares
  this surface admin-only.
- Gate `synchronise()` behind `#[AuthorizedAdminSetting]` as well: on-demand
  outbound fetches are an admin operation; the hourly cron covers everyone
  else.
- Update the spec text so LST-003's scenario says "authenticated admin"
  instead of "authenticated user", and DIR-005 gains an explicit
  non-admin-rejection scenario (currently it only tests the anonymous case).
