# Design — auto-publishing

## Context

The auto-publishing system responds to OpenRegister's `ObjectCreatedEvent` and `ObjectUpdatedEvent` to publish objects and their file attachments automatically, without operator intervention. It is a **pure event-listener + service** change: no custom database tables, no new REST endpoints, no frontend components, and no new OpenRegister schemas. Seed data is not required for this change (exception per ADR-001: changes that only modify backend event-listener logic do not require seed data).

## Goals

- Formally capture the event flow and component responsibilities so the `FileMapper` vs `ObjectService` constraint (and its rationale) is in the spec — not just in comments.
- Remove the temporary debug logging from `ObjectUpdatedEventListener`.
- Resolve the `@self.files = []` TODO so attachment publishing correctly runs on update events.
- Provide a test plan covering every scenario in the spec.

## Non-goals

- No changes to the OpenRegister event system.
- No new admin UI for per-catalog auto-publishing configuration (toggles already exist in `SettingsService`).
- No new publish triggers beyond create and update events.
- No changes to `CatalogSchemaEventListener` or `CatalogCacheEventListener` (handled in `fix-catalog-update-infinite-loop`).

## Architectural decisions

### A. Use `FileMapper` (not `ObjectService`) for file retrieval

Publishing file attachments calls `FileService.createShareLink()`, which may update file metadata. If `ObjectService` were used to retrieve the file list it could trigger another `ObjectUpdatedEvent`, causing an infinite dispatch loop. `FileMapper.getFilesForObject()` reads the file records directly from the database, bypassing the event dispatcher entirely.

**Rule**: Any code path inside `publishObjectAttachments()` MUST NOT call `ObjectService` for file retrieval. `FileMapper` is the only sanctioned access point for file data in this service.

### B. Early return when both options are disabled

When both `auto_publish_objects` and `auto_publish_attachments` are `false`, the listener returns immediately without invoking `EventService`. This prevents unnecessary processing on every object event in installations that do not use auto-publishing.

### C. Catalog membership check gates all publishing

Auto-publishing only applies to objects that belong to a configured catalog. `EventService.shouldAutoPublishObject()` iterates all catalogs and checks whether the object's `@self.register` and `@self.schema` values appear in `catalog.registers[]` and `catalog.schemas[]`. If no catalog matches, the object is skipped entirely — no publish, no share links.

### D. Publication status from timestamp comparison

An object is considered published when `@self.published` is set **and** `@self.depublished` is either `null` or has a timestamp **before** `@self.published`. This allows objects to be re-published after depublication: if the published timestamp is newer than the depublished timestamp the object is treated as published.

### E. ObjectEntity to array conversion

Both listeners manually construct the `@self` metadata array from the `ObjectEntity`, because `ObjectEntity::jsonSerialize()` may not include all required fields (`@self.register`, `@self.schema`, `@self.uuid`, `@self.published`, `@self.depublished`). This is a temporary workaround. A future OpenRegister update should expose a stable `toArray()` or `getMetadata()` contract that eliminates the manual construction.

### F. File path prefix for share links

The FileMapper stores paths in the format `files/<object-uuid>/<filename>`. The OpenRegister `FileService.createShareLink()` expects a path with a `/OpenRegister/` prefix. The `EventService` must prepend this prefix when passing the path from FileMapper to FileService. This transformation is a known quirk; it must be covered by a unit test to prevent silent regressions.

## Event flow

```
OpenRegister ObjectCreatedEvent / ObjectUpdatedEvent
  └─► Nextcloud IEventDispatcher
        └─► ObjectCreatedEventListener / ObjectUpdatedEventListener
              ├─ SettingsService.getPublishingOptions()
              │   └─ early return if both disabled (APB-007)
              ├─ Construct @self array from ObjectEntity (APB-015 workaround)
              └─► EventService
                    ├─ shouldAutoPublishObject()     catalog register/schema match (APB-005)
                    ├─ publishObject()               ObjectService.publish() (APB-003)
                    └─ publishObjectAttachments()    FileMapper → FileService.createShareLink() (APB-004, APB-010)
```

## Component responsibilities

| Component | File | Responsibility |
|-----------|------|----------------|
| `ObjectCreatedEventListener` | `lib/Listener/ObjectCreatedEventListener.php` | Receives `ObjectCreatedEvent`, checks settings, constructs `@self` array, delegates to `EventService.handleObjectCreateEvents()` |
| `ObjectUpdatedEventListener` | `lib/Listener/ObjectUpdatedEventListener.php` | Receives `ObjectUpdatedEvent`, checks settings, detects publish-status transitions, delegates to `EventService.handleObjectUpdateEvents()` |
| `EventService` | `lib/Service/EventService.php` | Core logic: catalog membership check (`shouldAutoPublishObject`), object publishing (`publishObject`), attachment share-link creation (`publishObjectAttachments`) |
| `SettingsService` | `lib/Service/SettingsService.php` | Exposes `getPublishingOptions()` returning `auto_publish_objects` and `auto_publish_attachments` booleans |
| `Application` | `lib/AppInfo/Application.php` | Registers both listeners via `IRegistrationContext::registerEventListener()` at bootstrap |

## Configuration keys

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `auto_publish_objects` | string (bool) | `"false"` | When `true`, newly created objects matching a catalog are auto-published via `ObjectService.publish()` |
| `auto_publish_attachments` | string (bool) | `"false"` | When `true`, files on published objects receive read-only public share links automatically |

## Processing result structure

`EventService` methods return the following structured result:

```php
[
    'processed'            => int,   // Number of objects processed
    'published'            => int,   // Number of objects published
    'attachmentsPublished' => int,   // Number of attachments given share links
    'errors'               => [],    // Array of error messages (string[])
    'details'              => [      // Per-object results
        [
            'objectId' => string,
            'actions'  => [],        // e.g. 'object_published', 'attachments_processed'
            'errors'   => [],
        ]
    ]
]
```

## Reuse analysis

Auto-publishing is intentionally a thin orchestration layer over existing OpenRegister services. No new OR services are needed and no existing capabilities are duplicated.

| OR service / primitive | Usage in auto-publishing | Why not a custom implementation |
|------------------------|--------------------------|--------------------------------|
| `ObjectService.publish()` | Sets `@self.published` timestamp on matched objects | OR owns the publication state machine; encoding it in opencatalogi would duplicate logic and diverge from OR's state transitions |
| `FileService.createShareLink()` | Creates read-only public share links (type 3, permissions 1) for unpublished attachments | OR owns file sharing; a custom share-creation path would bypass OR's share lifecycle and audit trail |
| `FileMapper.getFilesForObject()` | Retrieves file records directly from DB | `ObjectService` cannot be used here (would trigger `ObjectUpdatedEvent` → infinite loop; see Design decision A) |
| `IEventDispatcher` (Nextcloud) | Receives `ObjectCreatedEvent` / `ObjectUpdatedEvent` | Standard Nextcloud event bus; no custom event system required |
| `SettingsService` | Reads `auto_publish_objects` / `auto_publish_attachments` | Already exists in opencatalogi; no new configuration infrastructure required |
| `CatalogiService` (indirect) | Provides catalog objects whose `registers[]` / `schemas[]` are checked | Catalog data is owned by opencatalogi's CatalogiService; auto-publishing queries it but does not duplicate catalog management logic |

**Deduplication finding**: no overlap found with existing OR capabilities. The system exclusively consumes OR primitives and does not re-implement any OR-provided behaviour.

## Known issues and resolution plan

| Item | Location | APB req | Resolution |
|------|----------|---------|------------|
| Temporary debug logging | `lib/Listener/ObjectUpdatedEventListener.php` | APB-016 | Remove all `OPENCATALOGI_EVENT_LISTENER_CALLED_AT_*` log statements before next release (Task 2) |
| `@self.files = []` placeholder | `lib/Listener/ObjectUpdatedEventListener.php` | APB-017 | Inject `FileMapper` via constructor DI and call `getFilesForObject($objectId)` to populate the real file list (Task 3) |
| `/OpenRegister/` path prefix | `lib/Service/EventService.php` | APB-004 | Verify and unit-test the prefix transformation from FileMapper path to FileService share-link path (Task 5) |

## Test strategy

1. **Unit tests for `EventService`**: mock `ObjectService`, `FileService`, `FileMapper`, `SettingsService`, and verify catalog match / catalog miss / attachment skip / early-return scenarios independently.
2. **Infinite-loop guard integration test**: create a multi-file object with auto-publish enabled; assert exactly one `ObjectUpdatedEvent` is dispatched for the operation (not one per file). Without the `FileMapper` guard, this test would hang.
3. **Publication status unit tests**: assert `isObjectPublished()` returns the correct boolean for all three timestamp combinations (published only, depublished after published, published after depublished).
4. **Path prefix unit test**: assert `EventService` prepends `/OpenRegister/` to the FileMapper path before calling `FileService.createShareLink()`.
5. **APB-017 regression test**: update a published object with 2 unpublished attachments; assert `attachmentsPublished = 2` in the result. This test fails before the `@self.files = []` fix and passes after.
