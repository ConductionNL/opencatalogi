---
kind: code
depends_on: []
---

# Proposal: e2e-verify-listings-admin-gates

## Why

`lib/Controller/ListingsController.php` currently admin-gates `update()`
(`ListingsController.php:328`) and `destroy()` (`ListingsController.php:394`)
via `#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]` — a
Nextcloud AppFramework attribute enforced by middleware **before** the
controller method body ever runs. At HEAD, no test anywhere in the repo
actually exercises that middleware boundary:

- `tests/Unit/Controller/ListingsControllerTest.php` (e.g.
  `testUpdateReturnsUpdatedListing` line 194, `testDestroyReturnsSuccessOnDeletion`
  line 233) instantiates `ListingsController` directly and calls
  `->update(...)`/`->destroy(...)` on the PHP object — this is plain PHPUnit
  with mocked collaborators. `#[AuthorizedAdminSetting]` is read and enforced
  by `\OC\AppFramework\Middleware\Security\SecurityMiddleware`, which sits in
  front of the controller in the real request pipeline; a direct method call
  never passes through it, so these tests cannot fail if the attribute were
  removed, renamed, or the admin group check regressed.
- `tests/federation/federation-tests.postman_collection.json` — the Newman
  suite that `openspec/specs/dashboard/spec.md:25`'s blanket
  `@e2e exclude` cites as covering "listing/directory CRUD... backend/HTTP-contract
  behaviours" — authenticates with exactly one credential pair for every
  request in the collection: `{"key": "username", "value": "admin"}` /
  `{"key": "password", "value": "admin"}` (lines 11-12). "Phase 4: Listing
  CRUD" (4.1-4.5) drives `update()`/`destroy()` over real HTTP, but always as
  admin — there is no request anywhere in the collection authenticated as a
  non-admin user, so the 403-on-non-admin behavior the attribute exists to
  enforce is never asserted by Newman either.
- `tests/e2e/spec-coverage/directory-page.spec.ts` (the one Playwright spec
  touching this feature area) only covers rendering
  (`@e2e federation::directory-renders-federation-status`), not any write
  path or authorization boundary.

Net effect: the admin-only posture on `update()`/`destroy()` — the exact
posture the open `harden-listings-admin-write-surface` change plans to also
apply to `create()`, `add()`, and `synchronise()` (whose own acceptance
criteria, per its `tasks.md`, are PHPUnit-only) — is asserted nowhere as an
end-to-end, real-request behavior. A regression that silently dropped or
weakened the attribute (e.g. during a refactor of `OpenCatalogiAdmin`, or a
Nextcloud AppFramework upgrade changing attribute resolution) would pass
every test in the suite while opening a real IDOR/topology-change hole.

## What Changes

- Add a Newman-suite (or dedicated Playwright API-request) test that
  authenticates as a **non-admin** Nextcloud user and asserts `PUT
  /apps/opencatalogi/api/listings/{id}` and `DELETE
  /apps/opencatalogi/api/listings/{id}` both return `403` (or the
  AppFramework's standard "not authorized" response) for that session,
  while the existing admin-authenticated requests continue to succeed.
- Extend `openspec/specs/dashboard/spec.md`'s LST-004 (Update an existing
  listing) and LST-005 (Delete a listing) requirements with an explicit
  non-admin-rejection scenario, mirroring the pattern the
  `harden-listings-admin-write-surface` change already uses for DIR-005.
- Narrow the blanket `@e2e exclude` at `openspec/specs/dashboard/spec.md:25`
  so it no longer implicitly claims authorization-boundary coverage that
  doesn't exist — the exclusion is legitimate for CRUD *data shape*
  assertions (those genuinely are HTTP-contract, not UI-observable) but
  should not be read as covering the admin-vs-non-admin authorization gate.
- Not BREAKING — adds test coverage and a spec scenario only; no production
  code changes.

## Non-goals

- Not re-implementing or duplicating `harden-listings-admin-write-surface`
  (which fixes `create()`/`add()`/`synchronise()`'s authorization posture).
  This change only adds the missing **verification** for the two write paths
  that are already correctly gated at HEAD (`update()`, `destroy()`), and
  recommends the same non-admin-authenticated-HTTP pattern be reused when
  that other change lands.
