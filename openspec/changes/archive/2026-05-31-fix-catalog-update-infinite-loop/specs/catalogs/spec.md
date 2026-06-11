---
status: proposed
---

# Catalogs (delta: fix-catalog-update-infinite-loop)

This delta refines existing requirement **CAT-011** and adds a new safety requirement **CAT-012** to the canonical `openspec/specs/catalogs/spec.md`. It is folded back into the canonical spec via `/opsx:sync` after the change is verified.

## MODIFIED Requirements

### Requirement: CAT-011 — Catalog object event handling

Catalog object lifecycle events MUST be handled in a way that:

1. **Normalisation runs pre-save**: Slug-to-ID rewriting of the `registers` and `schemas` fields on a catalog object MUST happen via a pre-save listener subscribed to `ObjectCreatingEvent` and `ObjectUpdatingEvent`. The listener MUST express its mutation by calling `setModifiedData(...)` on the event, so the in-flight `MagicMapper` save merges the rewritten values into a single persisted record.

2. **Cache management runs post-save**: Cache invalidation and warmup MUST happen via a post-save listener subscribed to `ObjectCreatedEvent`, `ObjectUpdatedEvent`, and `ObjectDeletedEvent`. The cache listener MUST be read-only with respect to the catalog object itself — it MAY query the object via `searchObjects` or `find`, but it MUST NOT call any method that re-persists the object.

3. **No listener triggers a recursive event**: No listener for any catalog object lifecycle event MAY call `saveObject`, `update`, `deleteObject`, or any other persistence operation on the entity that triggered it. Listeners that need to mutate the entity MUST do so via the pre-save event's `setModifiedData(...)` API instead.

#### Scenario: Catalog update with slug-valued registers persists in a single save
- GIVEN a catalog object whose JSON contains `"registers": ["my-register"]` (slug, not numeric ID)
- WHEN the catalog is saved via `ObjectService::saveObject(...)`
- THEN the persisted record MUST contain `"registers": [<integer-id>]`
- AND exactly **one** `ObjectUpdatedEvent` (or `ObjectCreatedEvent`) MUST be dispatched as a result of the save
- AND the request MUST return within the standard PHP request budget (no hang)

#### Scenario: Catalog soft-delete returns promptly
- GIVEN any catalog object
- WHEN it is soft-deleted via `ObjectService::deleteObject(...)`
- THEN the request MUST return within the standard PHP request budget
- AND no listener MUST issue an additional `update` or `saveObject` on the same entity during deletion handling
- AND the slug cache for the catalog MUST be invalidated

#### Scenario: Pre-save normalisation failure does not block the save
- GIVEN a catalog object with a `registers` entry that does not resolve to an existing register
- WHEN the catalog is saved
- THEN the pre-save listener MUST log the resolution failure
- AND MUST NOT call `stopPropagation()` on the event
- AND the save MUST proceed with the original (un-rewritten) data
- AND the user MUST receive a successful response (the save itself is not the place to enforce slug validity)

## ADDED Requirements

### Requirement: CAT-012 — No recursive saves from post-save event handlers

A listener subscribed to a post-save catalog lifecycle event (`ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`) MUST NOT, directly or indirectly, invoke a persistence operation that re-emits the same event class for the same object.

#### Scenario: Listener under test for re-entry safety
- GIVEN any listener registered on `ObjectUpdatedEvent` for the catalog schema
- WHEN the listener handles the event
- THEN it MUST NOT call `ObjectService::saveObject(...)` on the originating object
- AND it MUST NOT call `MagicMapper::update(...)` on the originating object
- AND it MUST NOT call any service method documented to internally re-save the object

#### Scenario: Test asserts catalog update does not recurse
- GIVEN a test environment with the configured `catalog_schema` / `catalog_register`
- WHEN a catalog object is updated through the public API
- THEN the test MUST observe exactly one `ObjectUpdatedEvent` dispatch for the operation
- AND the wall-clock duration of the request MUST be below a sane threshold (e.g. 5 seconds)
