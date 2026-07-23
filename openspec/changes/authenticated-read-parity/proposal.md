---
kind: code
depends_on: []
---

# Proposal: authenticated-read-parity

## Summary
Stop treating logged-in users as anonymous on OpenCatalogi's public read
endpoints. Today `CatalogiService` strips a fixed list of `@self` metadata
properties from every response regardless of session (the code carries the
`@todo: a logged-in user should be able to see the full object` at
`lib/Service/CatalogiService.php`). This change makes the stripping
session-aware: anonymous callers keep today's minimal envelope byte-identical;
authenticated callers receive the full `@self` metadata for objects OpenRegister
RBAC already lets them read.

## Motivation
Editors and administrators browsing catalogs through the same public endpoints
the portal uses cannot see ownership, lock state, retention or validation
metadata on their own objects — they must detour through the OpenRegister UI.
The visibility decision (may this caller read this object at all?) already
lives in OR RBAC (`_rbac: true` on the delegated search, per ADR-022 and the
catalogs spec); the leaf-side stripping is a *presentation* rule for the
anonymous public web, wrongly applied to everyone. Rights-aware UI is also a
recurring user wish in the 2026-07-23 research (issues #535/#569/#635 cluster:
users see surfaces they cannot act on and vice versa).

## Scope
- `CatalogiService` (and any sibling public-read assembler applying the same
  `$unwantedProperties` strip — PublicationService/PublicationQueryService to
  be confirmed during apply) consults `IUserSession`: strip for anonymous,
  pass-through full `@self` for authenticated callers.
- No change to which OBJECTS are returned — OR RBAC continues to govern
  visibility for both audiences; this only changes envelope metadata richness.
- PHPUnit coverage proving: anonymous envelope byte-identical to baseline;
  authenticated envelope carries the previously stripped keys; object-set
  parity between the two audiences given identical RBAC context.

## Out of scope
- Group/role-differentiated partial metadata (all-or-nothing on session).
- Any write-surface change.

## Impact
- Touched: `lib/Service/CatalogiService.php` (+ sibling assemblers if they
  duplicate the strip list), unit tests.
- Specs: delta on `catalogs` (ADDED CAT-AUTH-001, MODIFIED none).
