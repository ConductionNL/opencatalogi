---
status: reviewed
---

# Auto-Publishing

## Purpose

The auto-publishing system automatically publishes OpenRegister objects and their file attachments when they are created or updated, based on configurable publishing options. It listens to OpenRegister's `ObjectCreatedEvent` and `ObjectUpdatedEvent` via Nextcloud's event dispatcher, evaluates whether the object belongs to a catalog, and triggers publish and share-link creation operations. This eliminates the need for manual publishing workflows for organizations that want all catalog content to be immediately public.

## Requirements

### Requirement: Listen to OpenRegister `ObjectCreatedEvent` and trigger auto-publishing logic (APB-001)
The system MUST listen to OpenRegister `ObjectCreatedEvent` and trigger auto-publishing logic.

**Priority:** Must **Status:** Implemented

### Requirement: Listen to OpenRegister `ObjectUpdatedEvent` and trigger auto-publishing logic (APB-002)
The system MUST listen to OpenRegister `ObjectUpdatedEvent` and trigger auto-publishing logic.

**Priority:** Must **Status:** Implemented

### Requirement: Auto-publish newly created objects when `auto_publish_objects` is enabled (APB-003)
The system MUST auto-publish newly created objects when `auto_publish_objects` is enabled.

**Priority:** Must **Status:** Implemented

### Requirement: Auto-publish file attachments (create share links) when `auto_publish_attachments` is enabled (APB-004)
The system MUST auto-publish file attachments (create share links) when `auto_publish_attachments` is enabled.

**Priority:** Must **Status:** Implemented

### Requirement: Only auto-publish objects whose register/schema match a configured catalog (APB-005)
The system MUST only auto-publish objects whose register/schema match a configured catalog.

**Priority:** Must **Status:** Implemented

### Requirement: Determine publication status from `@self.published` and `@self.depublished` timestamps (APB-006)
The system MUST determine publication status from `@self.published` and `@self.depublished` timestamps.

**Priority:** Must **Status:** Implemented

### Requirement: Skip event processing entirely when both auto-publish options are disabled (early return) (APB-007)
The system SHOULD skip event processing entirely when both auto-publish options are disabled (early return).

**Priority:** Should **Status:** Implemented

### Requirement: On update events, only process attachment publishing for already-published objects (APB-008)
The system SHOULD only process attachment publishing for already-published objects on update events.

**Priority:** Should **Status:** Implemented

### Requirement: On update events, detect publication status transitions (unpublished to published) (APB-009)
The system SHOULD detect publication status transitions (unpublished to published) on update events.

**Priority:** Should **Status:** Implemented

### Requirement: Use FileMapper for direct database file access to avoid infinite loop with ObjectService (APB-010)
The system MUST use FileMapper for direct database file access to avoid infinite loop with ObjectService.

**Priority:** Must **Status:** Implemented

### Requirement: Skip already-published files (those with existing share tokens) (APB-011)
The system SHOULD skip already-published files (those with existing share tokens).

**Priority:** Should **Status:** Implemented

### Requirement: Return structured results with processed/published/error counts (APB-012)
The system SHOULD return structured results with processed/published/error counts.

**Priority:** Should **Status:** Implemented

### Requirement: Log all processing results (successes and errors) for monitoring (APB-013)
The system SHOULD log all processing results (successes and errors) for monitoring.

**Priority:** Should **Status:** Implemented

### Requirement: Gracefully handle exceptions without breaking the originating OpenRegister operation (APB-014)
The system MUST gracefully handle exceptions without breaking the originating OpenRegister operation.

**Priority:** Must **Status:** Implemented

### Requirement: Event listeners registered in Application.php bootstrap via IRegistrationContext (APB-015)
Event listeners MUST be registered in Application.php bootstrap via IRegistrationContext.

**Priority:** Must **Status:** Implemented

## Architecture

### Event Flow

```
OpenRegister ObjectCreatedEvent/ObjectUpdatedEvent
  --> Nextcloud IEventDispatcher
    --> ObjectCreatedEventListener / ObjectUpdatedEventListener
      --> Check publishing options (SettingsService)
      --> Convert ObjectEntity to array format
      --> EventService.handleObjectCreateEvents() / handleObjectUpdateEvents()
        --> shouldAutoPublishObject() (catalog matching)
        --> publishObject() (ObjectService.publish())
        --> publishObjectAttachments() (FileMapper + FileService.createShareLink())
```

### Key Components

| Component | Location | Responsibility |
|-----------|----------|----------------|
| ObjectCreatedEventListener | `lib/Listener/ObjectCreatedEventListener.php` | Handles ObjectCreatedEvent, checks settings, delegates to EventService |
| ObjectUpdatedEventListener | `lib/Listener/ObjectUpdatedEventListener.php` | Handles ObjectUpdatedEvent, checks settings, detects status changes, delegates to EventService |
| EventService | `lib/Service/EventService.php` | Core auto-publishing logic: catalog matching, object publishing, attachment publishing |
| SettingsService | `lib/Service/SettingsService.php` | Provides `getPublishingOptions()` for auto_publish_objects and auto_publish_attachments |
| Application | `lib/AppInfo/Application.php` | Registers event listeners via `registerEventListener()` |

### Configuration Keys

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `auto_publish_objects` | string (bool) | "false" | When true, newly created objects matching a catalog are auto-published |
| `auto_publish_attachments` | string (bool) | "false" | When true, files on published objects get share links created automatically |

## Data Model

Auto-publishing does not have its own data model. It operates on OpenRegister objects and uses:

- **Object metadata**: `@self.register`, `@self.schema`, `@self.uuid`, `@self.published`, `@self.depublished`
- **Catalog objects**: `registers[]`, `schemas[]` arrays to determine if an object belongs to a catalog
- **File data**: `share_token` (from FileMapper) to determine if a file is already published

### Processing Result Structure

```php
[
    'processed'            => int,   // Number of objects processed
    'published'            => int,   // Number of objects published
    'attachmentsPublished' => int,   // Number of attachments published
    'errors'               => [],    // Array of error messages
    'details'              => [      // Per-object results
        [
            'objectId' => string,
            'actions'  => [],        // e.g., 'object_published', 'attachments_processed'
            'errors'   => [],
        ]
    ]
]
```

## Scenarios

### Scenario: Auto-publish a newly created object
- GIVEN `auto_publish_objects` is enabled
- AND a catalog exists with registers `[1]` and schemas `[2]`
- WHEN an OpenRegister object is created with register=1 and schema=2
- THEN the ObjectCreatedEventListener receives the ObjectCreatedEvent
- AND EventService.shouldAutoPublishObject() checks all catalogs
- AND finds that the object's register/schema match the catalog
- AND EventService.publishObject() calls ObjectService.publish()
- AND the object's published timestamp is set

### Scenario: Auto-publish attachments on a published object
- GIVEN `auto_publish_attachments` is enabled
- AND an object is published (has published timestamp, no depublished)
- WHEN the object is created or updated
- THEN EventService.publishObjectAttachments() is called
- AND FileMapper.getFilesForObject() retrieves files directly from database
- AND for each file without a share_token, FileService.createShareLink() is called
- AND the share link is created with read-only permissions (type 3, permissions 1)

### Scenario: Skip processing when auto-publish is disabled
- GIVEN both `auto_publish_objects` and `auto_publish_attachments` are false
- WHEN an OpenRegister object is created or updated
- THEN the listener returns early without processing
- AND no calls are made to EventService

### Scenario: Object update triggers attachment publishing only
- GIVEN `auto_publish_objects` is false
- AND `auto_publish_attachments` is true
- AND an existing published object is updated
- WHEN the ObjectUpdatedEventListener receives the event
- THEN shouldProcessUpdate() returns true (object is published, attachments enabled)
- AND only handleObjectUpdateEvents() is called (not handleObjectCreateEvents())
- AND only attachment publishing logic runs (no object publish)

### Scenario: Avoid infinite loop with FileMapper
- GIVEN `auto_publish_attachments` is enabled
- AND a published object has 3 files
- WHEN attachments are being published
- THEN FileMapper.getFilesForObject() reads files directly from database
- AND FileService.createShareLink() creates shares without triggering object updates
- AND no ObjectUpdatedEvent is re-dispatched (avoiding infinite recursion)

### Scenario: Object does not belong to any catalog
- GIVEN `auto_publish_objects` is enabled
- AND the object has register=5, schema=10
- AND no catalog includes register 5 and schema 10
- WHEN the object is created
- THEN shouldAutoPublishObject() returns false
- AND the object is NOT auto-published

### Scenario: Publication status determination
- GIVEN an object with `@self.published = "2024-01-15T10:00:00+00:00"` and `@self.depublished = null`
- THEN isObjectPublished() returns true
- GIVEN an object with `@self.published = "2024-01-15T10:00:00+00:00"` and `@self.depublished = "2024-01-16T10:00:00+00:00"`
- THEN isObjectPublished() returns false (depublished is after published)
- GIVEN an object with `@self.published = "2024-01-16T10:00:00+00:00"` and `@self.depublished = "2024-01-15T10:00:00+00:00"`
- THEN isObjectPublished() returns true (published is after depublished = re-published)

## Dependencies

- **OpenRegister ObjectService** - `publish()` for setting published timestamp, `find()` for object lookup, `searchObjects()` for catalog queries
- **OpenRegister FileService** - `createShareLink()` for creating public share links on files
- **OpenRegister FileMapper** - `getFilesForObject()` for direct database file access (avoids infinite loops)
- **OpenRegister Events** - `ObjectCreatedEvent`, `ObjectUpdatedEvent` dispatched by OpenRegister when objects change
- **SettingsService** - `getPublishingOptions()` for auto_publish_objects and auto_publish_attachments configuration
- **Nextcloud IEventDispatcher** - Event dispatching and listener registration
- **CatalogiService** (indirect) - Catalog schema/register configuration used to determine catalog membership

## Notes

- **Debug logging**: The ObjectUpdatedEventListener contains temporary debug logging (`OPENCATALOGI_EVENT_LISTENER_CALLED_AT_*`) that should be removed before production release.
- **File path conversion**: When creating share links, the OpenRegister path format requires a `/OpenRegister/` prefix to be added to the FileMapper path.
- **ObjectEntity conversion**: Both listeners manually construct the `@self` metadata array from the ObjectEntity, as the jsonSerialize() output may not include all required fields.
- **TODO in code**: The ObjectUpdatedEventListener sets `@self.files = []` with a TODO comment about implementing a safer way to get file information for attachment publishing.
