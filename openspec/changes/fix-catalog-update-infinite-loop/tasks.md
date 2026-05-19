# Tasks: fix-catalog-update-infinite-loop

## Task 1: Add a regression test that reproduces the hang
- **Spec ref**: specs/catalogs/spec.md (CAT-011, CAT-012)
- **Status**: done
- **Acceptance criteria**:
  - A test (integration or feature-level) updates a catalog object via the public API and asserts the request returns within a wall-clock budget (e.g. 5 seconds).
  - A second test soft-deletes a catalog object and asserts the same wall-clock budget.
  - Both tests fail on `master` (before the fix) and pass after the fix is applied.
  - Tests use the configured `catalog_schema` / `catalog_register` so they exercise the listener path that contains the bug.

## Task 2: Refactor `CatalogiService::rewriteSchemasAndRegisters` into a pure compute step
- **Spec ref**: specs/catalogs/spec.md (CAT-011)
- **Status**: done
- **Files**: `lib/Service/CatalogiService.php`
- **Acceptance criteria**:
  - New method `computeRewrittenRegistersAndSchemas(array $object): array` returns `['registers' => [...], 'schemas' => [...]]` with all slugs resolved to integer IDs. No DB write inside this method.
  - Method throws `RuntimeException` on an unresolvable register or schema slug, matching today's behaviour.
  - Old method `rewriteSchemasAndRegisters(ObjectEntity $entity): bool` is kept as a `@deprecated` thin wrapper calling the new compute method then `setObject` + `saveObject`. It is no longer used by the listener.
  - PHPCS / PHPMD / Psalm / PHPStan all clean (`composer check:strict`).

## Task 3: Re-point `CatalogSchemaEventListener` to the pre-save events
- **Spec ref**: specs/catalogs/spec.md (CAT-011, CAT-012)
- **Status**: done
- **Files**:
  - `lib/Listener/CatalogSchemaEventListener.php`
  - `lib/AppInfo/Application.php`
- **Acceptance criteria**:
  - The listener's `handle()` accepts `ObjectCreatingEvent` and `ObjectUpdatingEvent` only. It returns early for any other event class.
  - The listener fetches the entity via `$event->getNewObject()` (for `ObjectUpdatingEvent`) or the pre-save object accessor on `ObjectCreatingEvent`.
  - On match (`schema === catalog_schema && register === catalog_register`), it calls `CatalogiService::computeRewrittenRegistersAndSchemas(...)` and `$event->setModifiedData([...])` with only the keys that actually changed.
  - The listener never calls `saveObject` or any persistence method.
  - The listener never calls `stopPropagation()`.
  - All exceptions are caught and logged; the original save proceeds with unmodified data on failure.
  - `Application.php` registers `CatalogSchemaEventListener::class` against `ObjectCreatingEvent::class` and `ObjectUpdatingEvent::class`. The previous `ObjectCreatedEvent` / `ObjectUpdatedEvent` registrations for this listener are removed.

## Task 4: Confirm cache listener and auto-publish listener are unaffected
- **Spec ref**: specs/catalogs/spec.md (CAT-011)
- **Status**: done
- **Acceptance criteria**:
  - `CatalogCacheEventListener` remains registered on `ObjectCreatedEvent` / `ObjectUpdatedEvent` / `ObjectDeletedEvent` and continues to invalidate and warm the slug cache after a catalog change.
  - `ObjectCreatedEventListener` and `ObjectUpdatedEventListener` (auto-publishing) registrations are unchanged.
  - The regression tests from Task 1 still pass after Tasks 2 and 3 are merged, **and** a unit/integration assertion confirms the slug cache is invalidated on update.

## Task 5: Update the spec
- **Spec ref**: specs/catalogs/spec.md
- **Status**: done
- **Acceptance criteria**:
  - CAT-011 in the canonical `openspec/specs/catalogs/spec.md` is updated to state that catalog object normalisation happens on the **pre-save** events (`ObjectCreatingEvent` / `ObjectUpdatingEvent`).
  - A new requirement (CAT-012) is added: "Catalog event listeners MUST NOT trigger a re-save of the originating object from a post-save event handler" — to prevent re-introducing this class of bug.
  - The spec delta lives in `openspec/changes/fix-catalog-update-infinite-loop/specs/catalogs/spec.md` and is folded back via `/opsx:sync` after the change is verified.

## Task 6: Manual verification
- **Status**: done
- **Acceptance criteria**:
  - In a running dev environment (docker-compose), edit a catalog object via the OpenCatalogi UI and confirm the save returns immediately and the persisted object has integer IDs in `registers` / `schemas`.
  - Delete a catalog object via the UI and confirm the request returns immediately.
  - Tail the Nextcloud log during both operations and confirm there is no recursive listener firing (no "OpenCatalogi: Catalog cache" or rewrite log lines repeating in a tight loop).
