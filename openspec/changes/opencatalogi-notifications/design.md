# Design — opencatalogi-notifications

status: pr-created

## Context

OpenCatalogi publishes catalogues and federates listings from remote sources.
Publication officers need timely feedback when a catalogue reaches stable status
(publication milestone) and when a federated listing sync fails (operational health).

The OpenRegister notification engine consumes `x-openregister-notifications` schema
extensions and dispatches `nc-notification` on the configured trigger. This change
adds those declarations to the `catalog` and `listing` schemas.

## Declarative-vs-imperative decision

This is a pure `kind: config` change per ADR-031. Both requirements are expressible
as `x-openregister-notifications` schema metadata — no PHP service code is needed.

## Changes

- `lib/Settings/publication_register.json` — added `x-openregister-notifications`
  to `catalog` (catalog-stable rule) and `listing` (listing-sync-failed rule)
- Also fixed a pre-existing duplicate `configuration` key on both schemas:
  `x-openregister-lifecycle` was inside a redundant first `configuration` block
  (shadowed by the second `configuration: {autoPublish: false}` block). Moved
  lifecycle to the schema root level, consistent with `x-openregister-notifications`.

## Caveats carried from spec

- The `publication-officers` group name must exist in the target deployment.
  Adjust in `lib/Settings/publication_register.json` if the operator uses a
  different group name.
- The `transition` trigger fires only when the sync/publish flow drives status
  through named OpenRegister transition actions (`stable`, `obsolete`). If the
  code writes `status` directly, these rules are declared-but-dormant.
