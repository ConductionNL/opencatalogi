# Design: fix-catalog-update-infinite-loop

## Problem

Updating or (soft-)deleting any object whose schema/register matches `catalog_schema` / `catalog_register` causes the request to hang indefinitely (until PHP times out or memory is exhausted).

## Root cause

```
PUT /catalog/{id}   ── or ──   DELETE /catalog/{id}
        │                              │
        ▼                              ▼
 ObjectService::saveObject       ObjectService::deleteObject
        │                              │
        │                       (soft-delete → setDeleted →
        │                        objectEntityMapper->update())
        ▼                              ▼
 MagicMapper::update()  ─────► dispatches ObjectUpdatedEvent
                                   (DeleteObject.php:260,
                                    MagicMapper.php:6801)
        │
        ▼
 CatalogSchemaEventListener::handle()
   only fires for catalog_schema/catalog_register
        │
        ▼
 CatalogiService::rewriteSchemasAndRegisters($entity)
        │
        ▼
 $this->getObjectService()->saveObject($entity)   ◄── unconditional re-save
   (CatalogiService.php:241)
        │
        ▼
 MagicMapper::update()  ─────► dispatches ObjectUpdatedEvent ──┐
                                                                │
                          ┌─────────────────────────────────────┘
                          ▼
                  CatalogSchemaEventListener::handle() → ♾️
```

Why both update and delete hang the same way:
- **Update**: PUT goes through `MagicMapper::update()` which dispatches `ObjectUpdatedEvent` after the persist.
- **Delete (default = soft)**: `DeleteObject::deleteObject()` sets the `_deleted` field and calls `objectEntityMapper->update(...)` (see `lib/Service/Object/DeleteObject.php:260`). That dispatches `ObjectUpdatedEvent`, not `ObjectDeletedEvent`. The listener filters out `ObjectDeletedEvent`, but it does **not** filter out the soft-delete-disguised-as-update.
- **Hard delete (`permanent=true`)** dispatches `ObjectDeletedEvent` and bypasses the listener — that path works.

The bug only manifests on the `catalog` schema because the listener short-circuits for any other schema/register pair (`CatalogSchemaEventListener.php:88`).

## Why option B (pre-save hook) over option A (idempotent guard)

Option A would patch the symptom by skipping `saveObject` when the rewritten arrays are already equal to the stored arrays — viable as a hotfix, but it leaves the listener architecturally wrong: it would still listen to a post-save event in order to perform pre-save normalisation and would still re-save on the first invocation.

Option B aligns with the platform's existing pre-save hook contract:

`MagicMapper::updateObjectEntity()` (around the `ObjectUpdatingEvent` dispatch in `lib/Db/MagicMapper.php`):

```php
$updatingEvent = new ObjectUpdatingEvent(newObject: $entity, oldObject: $oldObject);
$this->eventDispatcher->dispatchTyped($updatingEvent);

// (propagation-stop check)

$modifiedData = $updatingEvent->getModifiedData();
if (empty($modifiedData) === false) {
    $objectData = $entity->getObject() ?? [];
    $entity->setObject(array_merge($objectData, $modifiedData));
}
```

`ObjectCreatingEvent` has the same `setModifiedData` / `getModifiedData` shape. The mapper already merges the modified payload into the in-flight save. No second save, no second event, no loop possible — by construction.

## Target design

### New flow

```
PUT /catalog/{id}
       │
       ▼
ObjectService::saveObject
       │
       ▼
MagicMapper::update()
       │
       ├──► dispatches ObjectUpdatingEvent  (pre-save)
       │           │
       │           ▼
       │   CatalogSchemaEventListener
       │     - matches catalog schema/register
       │     - computes rewritten registers/schemas (slug → integer ID)
       │     - calls $event->setModifiedData(['registers' => ..., 'schemas' => ...])
       │           │
       │           ▼
       │   MagicMapper merges modifiedData into entity
       │
       ├──► persists entity (single write)
       │
       └──► dispatches ObjectUpdatedEvent  (post-save, terminal)
                  │
                  ▼
          CatalogCacheEventListener
            - cache invalidate + warmup (read-only, no re-save)
```

### Listener responsibility split

| Listener | Event(s) | Responsibility |
|---|---|---|
| `CatalogSchemaEventListener` (new) | `ObjectCreatingEvent`, `ObjectUpdatingEvent` | Normalise `registers` / `schemas` slug-or-ID values into integer IDs **before** the entity is persisted. Mutate via `setModifiedData(...)`. **Never calls `saveObject`.** |
| `CatalogCacheEventListener` (unchanged) | `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent` | Read-only cache management. Still safe — does not re-save the originating object. |
| `ObjectCreatedEventListener` / `ObjectUpdatedEventListener` (unchanged) | post-save | Auto-publishing logic via `EventService`. Already does not re-save the originating object on the catalog schema, so unaffected. |

### Service-layer change

`CatalogiService::rewriteSchemasAndRegisters(ObjectEntity $entity)` is split into two methods:

- `computeRewrittenRegistersAndSchemas(array $object): array` — pure function returning `['registers' => [...], 'schemas' => [...]]` (or only the changed key(s)) for use in `setModifiedData`. Throws `RuntimeException` on unresolvable slug, same as today.
- The old `rewriteSchemasAndRegisters(ObjectEntity $entity): bool` is kept as a thin wrapper that calls the pure function and `setObject` + `saveObject` for backwards compatibility with any direct callers, but it is **not** used by the listener anymore. We deprecate it in a `@deprecated` docblock and remove direct callers in a follow-up.

### Handling propagation

`ObjectUpdatingEvent` and `ObjectCreatingEvent` are stoppable. The listener:
- MUST NOT call `stopPropagation()` under any circumstance.
- MUST catch all exceptions internally and log them. Failure to rewrite a slug must not block the user's update — the original (pre-rewrite) data flows through to persistence. This matches today's try/catch behaviour and keeps the listener non-fatal.

### Application registration change (`lib/AppInfo/Application.php`)

Today:

```php
$context->registerEventListener(ObjectCreatedEvent::class, CatalogSchemaEventListener::class);
$context->registerEventListener(ObjectUpdatedEvent::class, CatalogSchemaEventListener::class);
```

After:

```php
$context->registerEventListener(ObjectCreatingEvent::class, CatalogSchemaEventListener::class);
$context->registerEventListener(ObjectUpdatingEvent::class, CatalogSchemaEventListener::class);
```

The `CatalogCacheEventListener` registrations stay on the post-save events.

## Test strategy

1. **Regression integration test**: PUT and DELETE on a catalog object must complete within a sane wall-clock budget (e.g. 5s). Without the fix, the request hangs until the PHP `max_execution_time`. With the fix, it returns in milliseconds. This is the primary guard against re-introducing the loop.
2. **Slug→ID rewrite test**: create a catalog with `registers: ["my-register"]` and `schemas: ["my-schema"]` (slugs). After save, the persisted object MUST contain the corresponding integer IDs. Same on update.
3. **Pre-save event subscription test**: assert via the registration code or a unit test that `CatalogSchemaEventListener` is registered against `ObjectCreatingEvent` / `ObjectUpdatingEvent`, **not** against `ObjectCreatedEvent` / `ObjectUpdatedEvent`.
4. **Cache listener still fires**: a catalog update must still invalidate-and-warm the slug cache. This confirms the post-save cache flow is untouched.

## Alternatives considered

- **Option A — idempotent guard in `rewriteSchemasAndRegisters`**: skip `saveObject` if computed registers/schemas equal the existing ones. Smallest possible patch (1–3 lines). Rejected as the primary solution because it leaves the listener pointed at the wrong event class. May be applied as a defence-in-depth measure inside the new method as well, at near-zero cost.
- **Option C — re-entry guard** (static flag in the listener): works but is a code smell that hides design issues. Rejected.
- **Option D — debounce via `OutputBuffer` / async job**: overkill for a normalisation step that needs to happen synchronously before the value lands in the database.

## Open questions

- Is `rewriteSchemasAndRegisters` called from anywhere besides `CatalogSchemaEventListener`? A quick grep at implementation time will answer this; if the answer is "no", the wrapper can be removed instead of deprecated.
