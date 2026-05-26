# Design: migrate-activity-to-activity-leaf
<!-- status: pr-created -->

## Context

OpenCatalogi's two object-lifecycle listeners:

| Listener | Reacts to | Today's behaviour |
|---|---|---|
| `lib/Listener/ObjectCreatedEventListener.php` | OR `ObjectCreatedEvent` | If auto-publish options enabled, converts the entity to an array and runs auto-publish + share-link logic via `EventService`. |
| `lib/Listener/ObjectUpdatedEventListener.php` | OR `ObjectUpdatedEvent` | Same, on update, with a `shouldProcessUpdate()` gate. |

These listeners are the only place object-change events are observed in
OpenCatalogi, and there is **no user-facing per-object activity feed** — a user
viewing a publication cannot see its create/update/publish history.

## Decision: consume the OR activity leaf for the feed

Per **hydra ADR-022**, a per-object activity feed is an OR abstraction (the
activity leaf, ADR-019), sourced from OR's event stream + audit trail. OpenCatalogi
MUST consume it rather than build a parallel listener-driven feed:

1. **Frontend** — place the activity leaf widget on the publication detail page
   via the manifest (`detail.config.sidebarTabs[].widgets[].type: "activity"`,
   ADR-024 / ADR-036). The widget renders the publication's create / update /
   publish / file-change history from the leaf.
2. **Backend** — the listeners are no longer the de-facto "what happened"
   surface; that is the leaf's job. The listeners are reduced to the genuinely
   app-specific *side effect* that has no leaf equivalent.

## The keep / migrate split (important)

There are two distinct concerns tangled in the current listeners:

- **The activity *feed* (observation / display)** — "show me what happened to
  this publication." This is what the OR activity leaf provides and what this
  change consumes. OpenCatalogi stops being the activity surface.
- **The auto-publishing *side effect* (an action)** — "when an object in a
  catalogue is created/updated, publish it and its attachments." This is
  OpenCatalogi-specific business logic (catalogue membership + WOO publishing
  policy) with **no OR leaf equivalent**, so the listener that triggers it stays.
  Its *share-link* call is migrated separately by the
  `migrate-share-links-to-shares-leaf` change; its *publish* decision is
  app-specific and stays.

So this change does NOT delete the auto-publish trigger; it removes the implicit
"these listeners are our activity log" responsibility and adds the leaf-backed
user-facing feed. The listeners also lose their debug `OPENCATALOGI_EVENT_*`
logging noise as part of the cleanup.

## Why not keep a bespoke feed

- OpenCatalogi has no real feed today — building one now would be a brand-new
  ADR-022 violation (home-grown activity trail on OR-owned objects).
- The leaf's feed is hash-chained / replayable via OR's audit trail; a hand-built
  feed would drift and miss file/share/relation events the leaf tracks.

## Kept in-app (documented ADR-022 exceptions)

Stated so reviewers do not flag them as un-migrated:

- **Auto-publishing trigger + catalogue-membership / WOO publishing policy.**
  No OR leaf decides "this catalogue auto-publishes its objects"; this is
  OpenCatalogi domain logic. The listener that triggers it stays (its share call
  migrates via the shares-leaf change).
- **Public-facing CMS layer (Pages / Menus / Themes / Glossary).** Anonymous web
  rendering of catalogue websites — not an authenticated object-detail tab, no
  leaf equivalent. Stays in-app.
- **PDF / ZIP `DownloadService`.** No OR leaf equivalent (DocuDesk partner).
  Stays in-app.

## Migration / sequencing

1. Land the OR activity leaf (upstream, ADR-019). Apply is blocked on it.
2. Add the activity widget to the publication detail manifest entry.
3. Trim the listeners to the auto-publish trigger only; remove debug logging and
   any implicit activity-recording responsibility.

## Risks

- **Don't break auto-publishing.** The publish-on-create/update behaviour must
  keep working after the listeners are trimmed — verify auto-publish E2E.
- **Feed completeness.** Confirm the leaf surfaces publish/depublish events
  (OpenCatalogi sets `@self.published` / `@self.depublished`), not just CRUD.
