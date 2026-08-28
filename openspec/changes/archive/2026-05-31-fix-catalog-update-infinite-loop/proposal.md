# Proposal: fix-catalog-update-infinite-loop

## Summary
Fix an infinite event loop that hangs every update and (soft-)delete request on objects of the `catalog` schema. The `CatalogSchemaEventListener` re-saves the catalog from inside an `ObjectUpdatedEvent` handler, which dispatches a fresh `ObjectUpdatedEvent` and re-enters the same listener forever. Resolve it by moving slug-to-ID rewriting to the pre-save hook (`ObjectCreatingEvent` / `ObjectUpdatingEvent`) and mutating the in-flight save via `setModifiedData(...)` instead of issuing a second `saveObject(...)`.

## Motivation
- **User impact**: PUT and DELETE on any catalog object hang until the PHP request times out. This blocks editing and removing catalogs through the UI and the API.
- **Root cause** (see design): `CatalogSchemaEventListener` is registered on the post-save events `ObjectCreatedEvent` / `ObjectUpdatedEvent` and calls `CatalogiService::rewriteSchemasAndRegisters()`, which unconditionally calls `$this->getObjectService()->saveObject($objectEntity)`. That save dispatches another `ObjectUpdatedEvent`, which re-enters the listener, which re-saves, ad infinitum. Soft-deletes hang for the same reason: `DeleteObject.php` performs the soft-delete by calling `MagicMapper::update()`, which dispatches `ObjectUpdatedEvent`.
- **Why pre-save is the right shape**: `MagicMapper::updateObjectEntity()` already merges `$updatingEvent->getModifiedData()` into the object before persisting (see `lib/Db/MagicMapper.php` around the `ObjectUpdatingEvent` dispatch). The platform was designed for normalisation-style hooks to run in the pre-save phase. The current post-save re-save is both the bug and an architectural smell.

## Scope

### In scope
- Rename `CatalogSchemaEventListener` to listen to `ObjectCreatingEvent` and `ObjectUpdatingEvent` instead of the post-save events.
- Refactor `CatalogiService::rewriteSchemasAndRegisters()` to compute the rewritten `registers` and `schemas` arrays and return them, without persisting. The listener calls `$event->setModifiedData([...])` so the in-flight save picks up the rewritten values.
- Update listener registration in `lib/AppInfo/Application.php`.
- Add an integration-style test that updates a catalog object and asserts the request returns within a reasonable time bound (a regression guard against re-introducing the loop).

### Out of scope
- Audit and refactor of other listeners that may share the same anti-pattern (`ObjectUpdatedEventListener`, `ObjectCreatedEventListener` for auto-publishing). Those do not currently re-save the originating object, so they are not part of this fix. A follow-up audit can be filed separately.
- Hard-delete / `ObjectDeletedEvent` handling — `CatalogSchemaEventListener` already filters out delete events; no rewriting is needed when an object is going away.
- Any change to OpenRegister itself. The fix is contained in OpenCatalogi.

## Risks
- **Behavioural change for callers that rely on a fully-persisted object inside the listener.** None of the current listener logic does this — it only performs the rewrite — but if any out-of-tree code subscribes to `ObjectUpdatedEvent` and assumes the rewrite has already happened on the database row, it will now see the rewrite via the new object (still correct) but will not see a separate update audit-trail entry for the rewrite. We accept this — the rewrite was never a meaningful semantic change worth its own audit entry.
- **Stoppable event semantics**. `ObjectUpdatingEvent` implements `StoppableEventInterface`. The listener must not call `stopPropagation()` and must not raise; on failure it must log and let the original save proceed unmodified. This matches the existing try/catch behaviour in the listener.
