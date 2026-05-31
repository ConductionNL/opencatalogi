# Change: migrate-activity-to-activity-leaf

## Why

OpenCatalogi maintains bespoke object-lifecycle listeners —
`lib/Listener/ObjectCreatedEventListener.php` and
`lib/Listener/ObjectUpdatedEventListener.php` — that react to OpenRegister's
`ObjectCreatedEvent` / `ObjectUpdatedEvent` and drive in-app side effects
(currently auto-publishing). These listeners are also the only place
object-change activity is observed in OpenCatalogi: there is no user-facing
"what happened to this publication" feed, and the listeners reimplement
object-lifecycle reaction logic that OpenRegister now owns.

OpenRegister exposes an **activity leaf** in its integration registry (ADR-019):
a per-object activity feed (created / updated / published / file-changed events)
sourced from OR's own event stream and audit trail, surfaced as an activity
widget/tab on the object detail page (ADR-024). Per **hydra ADR-022**, an app
that needs a per-object activity feed MUST consume the OR activity leaf rather
than roll its own listener-driven feed.

This change:

- **Surfaces the OR activity leaf feed** on the publication detail page so users
  see the publication's create/update/publish history — a capability OpenCatalogi
  has no user-facing equivalent for today.
- **Retires the bespoke activity-reaction responsibility** of
  `ObjectCreatedEventListener` / `ObjectUpdatedEventListener`: object-change
  *observation/feed* is consumed from the activity leaf, not reimplemented in-app.

## What Changes

- **Consume the OR activity leaf** for the per-publication activity feed, placed
  as the activity widget/tab on `src/views/publications/PublicationDetail.vue` via
  the app manifest (ADR-024 / ADR-036).
- **Reduce the bespoke listeners** to only the genuinely app-specific side effect
  that has no leaf equivalent — see design.md for the exact keep/migrate split
  (the auto-publishing *side effect* is a separate concern from the activity
  *feed*, and the `migrate-share-links-to-shares-leaf` change handles its share
  call). The listeners stop being the de-facto activity surface.
- **Add an activity capability** to the `auto-publishing` spec documenting that
  object-change activity is consumed from the OR activity leaf, NOT from bespoke
  in-app listeners.

## Impact

- Affected specs: `auto-publishing` (adds a consumed-from-leaf activity feed
  requirement; clarifies the listeners' scope).
- Affected code: `lib/Listener/ObjectCreatedEventListener.php`,
  `lib/Listener/ObjectUpdatedEventListener.php` (scope reduced to non-leaf side
  effects), `src/views/publications/PublicationDetail.vue` + `src/manifest.json`
  (activity widget placement).
- Dependency: OpenRegister activity leaf (integration registry, ADR-019). Apply
  is blocked until the leaf is available; this is a SPEC-ONLY change.
