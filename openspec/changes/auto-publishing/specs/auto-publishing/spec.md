---
status: proposed
---

# Auto-Publishing (delta: auto-publishing)

This delta formalizes `APB-001` through `APB-015` in `openspec/specs/auto-publishing/spec.md` with GIVEN/WHEN/THEN acceptance scenarios and adds two new requirements (`APB-016`, `APB-017`) that close known gaps in the current implementation. Fold back into the canonical spec via `/opsx:sync` after this change is verified.

## Purpose

The auto-publishing system automatically publishes OpenRegister objects and their file attachments when they are created or updated, based on configurable publishing options (`auto_publish_objects`, `auto_publish_attachments`). It listens to OpenRegister's `ObjectCreatedEvent` and `ObjectUpdatedEvent` via Nextcloud's `IEventDispatcher`, evaluates whether the object belongs to a catalog, and triggers publish and share-link creation operations. This eliminates manual publishing workflows for organizations that want all catalog content to be immediately public.

## MODIFIED Requirements

### Requirement: APB-001 — Listen to ObjectCreatedEvent and trigger auto-publishing logic

The system MUST register `ObjectCreatedEventListener` on `ObjectCreatedEvent` via `IRegistrationContext::registerEventListener()` in `Application.php`.

#### Scenario: listener receives ObjectCreatedEvent
- GIVEN the opencatalogi app is loaded and both listeners are registered in `Application.php`
- WHEN OpenRegister dispatches an `ObjectCreatedEvent` for any object
- THEN `ObjectCreatedEventListener::handle()` is invoked with the event
- AND the listener retrieves the `ObjectEntity` from the event for further processing

---

### Requirement: APB-002 — Listen to ObjectUpdatedEvent and trigger auto-publishing logic

The system MUST register `ObjectUpdatedEventListener` on `ObjectUpdatedEvent` via `IRegistrationContext::registerEventListener()` in `Application.php`.

#### Scenario: listener receives ObjectUpdatedEvent
- GIVEN the opencatalogi app is loaded and both listeners are registered in `Application.php`
- WHEN OpenRegister dispatches an `ObjectUpdatedEvent` for any object
- THEN `ObjectUpdatedEventListener::handle()` is invoked with the event
- AND the listener retrieves the `ObjectEntity` from the event for further processing

---

### Requirement: APB-003 — Auto-publish newly created objects when `auto_publish_objects` is enabled

When `auto_publish_objects` is `true`, the system MUST auto-publish newly created objects that match a configured catalog by calling `ObjectService.publish()`.

#### Scenario: auto-publish a newly created object matching a catalog
- GIVEN `auto_publish_objects` is `true`
- AND a catalog exists with `registers: [1]` and `schemas: [2]`
- WHEN an OpenRegister object is created with `@self.register = 1` and `@self.schema = 2`
- THEN `ObjectCreatedEventListener` receives the `ObjectCreatedEvent`
- AND `EventService.shouldAutoPublishObject()` iterates all catalogs
- AND finds that the object's register and schema match the catalog
- AND `EventService.publishObject()` calls `ObjectService.publish()`
- AND the object's `@self.published` timestamp is set

---

### Requirement: APB-004 — Auto-publish file attachments (create share links) when `auto_publish_attachments` is enabled

When `auto_publish_attachments` is `true`, the system MUST create public share links for files attached to published objects by calling `FileService.createShareLink()` with read-only permissions (type 3, permissions 1).

#### Scenario: auto-publish attachments on a published object
- GIVEN `auto_publish_attachments` is `true`
- AND an object is published (`@self.published` is set, `@self.depublished` is `null`)
- WHEN the object is created or updated
- THEN `EventService.publishObjectAttachments()` is called
- AND `FileMapper.getFilesForObject()` retrieves file records directly from the database
- AND for each file without a `share_token`, `FileService.createShareLink()` is called
- AND each share link is created with read-only permissions (type 3, permissions 1)
- AND the file path is prefixed with `/OpenRegister/` before being passed to `FileService.createShareLink()`

---

### Requirement: APB-005 — Only auto-publish objects whose register/schema match a configured catalog

The system MUST only auto-publish objects whose `@self.register` and `@self.schema` values match the `registers[]` and `schemas[]` arrays of at least one configured catalog object.

#### Scenario: object matches a catalog — publishing proceeds
- GIVEN `auto_publish_objects` is `true`
- AND a catalog exists with `registers: [3]` and `schemas: [7]`
- WHEN an object with `@self.register = 3` and `@self.schema = 7` is created
- THEN `EventService.shouldAutoPublishObject()` returns `true`
- AND `publishObject()` is called for the object

#### Scenario: object does not belong to any catalog — publishing is skipped
- GIVEN `auto_publish_objects` is `true`
- AND the object has `@self.register = 5` and `@self.schema = 10`
- AND no catalog includes register 5 in its `registers[]` array and schema 10 in its `schemas[]` array
- WHEN the object is created
- THEN `EventService.shouldAutoPublishObject()` returns `false`
- AND `publishObject()` is NOT called
- AND the object is NOT auto-published

---

### Requirement: APB-006 — Determine publication status from `@self.published` and `@self.depublished` timestamps

The system MUST determine whether an object is published by comparing `@self.published` and `@self.depublished` timestamps. An object is published when `@self.published` is set and `@self.depublished` is either `null` or has a timestamp earlier than `@self.published`.

#### Scenario: object is published — depublished is null
- GIVEN an object with `@self.published = "2024-01-15T10:00:00+00:00"` and `@self.depublished = null`
- THEN `isObjectPublished()` returns `true`

#### Scenario: object is depublished — depublished is after published
- GIVEN an object with `@self.published = "2024-01-15T10:00:00+00:00"` and `@self.depublished = "2024-01-16T10:00:00+00:00"`
- THEN `isObjectPublished()` returns `false` (depublished timestamp is after published)

#### Scenario: object is re-published — published is after depublished
- GIVEN an object with `@self.published = "2024-01-16T10:00:00+00:00"` and `@self.depublished = "2024-01-15T10:00:00+00:00"`
- THEN `isObjectPublished()` returns `true` (published timestamp is after depublished)

---

### Requirement: APB-007 — Skip event processing entirely when both auto-publish options are disabled

When both `auto_publish_objects` and `auto_publish_attachments` are `false`, the event listener MUST return early without invoking `EventService`.

#### Scenario: early return when auto-publish is disabled
- GIVEN `auto_publish_objects` is `false`
- AND `auto_publish_attachments` is `false`
- WHEN an OpenRegister object is created or updated
- THEN the listener returns early without processing
- AND no calls are made to `EventService`
- AND no calls are made to `ObjectService.publish()` or `FileService.createShareLink()`

---

### Requirement: APB-008 — On update events, only process attachment publishing for already-published objects

On `ObjectUpdatedEvent`, attachment publishing MUST only run when the object is currently in a published state (as determined by APB-006).

#### Scenario: update on a published object triggers attachment publishing
- GIVEN `auto_publish_attachments` is `true`
- AND an existing object is published (`isObjectPublished()` returns `true`)
- WHEN the object is updated and `ObjectUpdatedEventListener` processes the event
- THEN `EventService.publishObjectAttachments()` is called
- AND share links are created for any files that do not yet have a `share_token`

#### Scenario: update on an unpublished object does not trigger attachment publishing
- GIVEN `auto_publish_attachments` is `true`
- AND an existing object is NOT published (`isObjectPublished()` returns `false`)
- WHEN the object is updated
- THEN `EventService.publishObjectAttachments()` is NOT called
- AND no share links are created

---

### Requirement: APB-009 — On update events, detect publication status transitions (unpublished to published)

On `ObjectUpdatedEvent`, the system MUST detect when an object transitions from an unpublished state to a published state and trigger the appropriate publishing flow.

#### Scenario: object update triggers attachment publishing only (no object re-publish)
- GIVEN `auto_publish_objects` is `false`
- AND `auto_publish_attachments` is `true`
- AND an existing object is currently published
- WHEN `ObjectUpdatedEventListener` receives the `ObjectUpdatedEvent`
- THEN `shouldProcessUpdate()` returns `true` (object is published, attachments are enabled)
- AND only `EventService.handleObjectUpdateEvents()` is called (not `handleObjectCreateEvents()`)
- AND only attachment publishing logic runs (no `ObjectService.publish()` call)

---

### Requirement: APB-010 — Use FileMapper for direct database file access to avoid infinite loop with ObjectService

The system MUST use `FileMapper.getFilesForObject()` for all file record retrieval inside `publishObjectAttachments()`. `ObjectService` MUST NOT be used for file retrieval in this context.

#### Scenario: avoid infinite loop — FileMapper is used, not ObjectService
- GIVEN `auto_publish_attachments` is `true`
- AND a published object has 3 files
- WHEN `EventService.publishObjectAttachments()` runs
- THEN `FileMapper.getFilesForObject()` reads file records directly from the database
- AND `FileService.createShareLink()` creates share links without triggering further object updates
- AND no new `ObjectUpdatedEvent` is dispatched as a side effect
- AND the event handler completes without infinite recursion

---

### Requirement: APB-011 — Skip already-published files (those with existing share tokens)

The system MUST skip files that already have a `share_token` set when processing attachment publishing.

#### Scenario: skip already-shared files
- GIVEN a published object has 2 files
- AND file A has `share_token = "abc123"` (already shared)
- AND file B has `share_token = null` (not yet shared)
- WHEN `EventService.publishObjectAttachments()` runs
- THEN `FileService.createShareLink()` is called only for file B
- AND file A is skipped
- AND the result shows `attachmentsPublished = 1`

---

### Requirement: APB-012 — Return structured results with processed/published/error counts

`EventService` MUST return a structured result containing `processed`, `published`, `attachmentsPublished`, `errors`, and per-object `details` on every invocation.

#### Scenario: structured result returned for a successful single-object publish
- GIVEN an `ObjectCreatedEvent` is processed for one object with one unpublished attachment
- WHEN `EventService.handleObjectCreateEvents()` completes
- THEN the returned array contains:
  - `processed: 1`
  - `published: 1`
  - `attachmentsPublished: 1`
  - `errors: []`
  - `details[0].objectId` — the UUID of the published object
  - `details[0].actions` includes `"object_published"` and `"attachments_processed"`
  - `details[0].errors: []`

---

### Requirement: APB-013 — Log all processing results (successes and errors) for monitoring

The system MUST log all processing results — both successful operations and errors — via the Nextcloud logger.

#### Scenario: successful publish is logged
- GIVEN an object is successfully auto-published
- WHEN `EventService` completes the operation
- THEN the logger records the result including the object UUID and the actions taken

#### Scenario: attachment error is logged and processing continues
- GIVEN `FileService.createShareLink()` throws an exception for one file
- WHEN `publishObjectAttachments()` processes multiple files
- THEN the error is logged with the file details
- AND processing continues for remaining files
- AND the result's `errors` array contains the error message

---

### Requirement: APB-014 — Gracefully handle exceptions without breaking the originating OpenRegister operation

The system MUST catch and handle all exceptions within the event listeners and `EventService` without breaking the originating OpenRegister save operation.

#### Scenario: exception in publishObject does not break the originating save
- GIVEN `ObjectService.publish()` throws an unexpected exception during auto-publishing
- WHEN `EventService.publishObject()` processes the object
- THEN the exception is caught and logged
- AND the error is recorded in the result's `errors` array
- AND `ObjectCreatedEventListener::handle()` returns normally
- AND the originating OpenRegister object save operation is NOT rolled back or interrupted

#### Scenario: exception in publishObjectAttachments does not break the originating operation
- GIVEN `FileService.createShareLink()` throws an exception for a file
- WHEN the event listener processes an object with multiple attachments
- THEN the exception is caught for that file
- AND `handle()` returns normally
- AND the OpenRegister operation is unaffected

---

### Requirement: APB-015 — Event listeners registered in Application.php bootstrap via IRegistrationContext

Both event listeners MUST be registered in `lib/AppInfo/Application.php` during the app bootstrap via `IRegistrationContext::registerEventListener()`.

#### Scenario: listeners are registered at bootstrap
- GIVEN the opencatalogi app is loaded via `Application.php`
- WHEN `register(IRegistrationContext $context)` is called
- THEN `$context->registerEventListener(ObjectCreatedEvent::class, ObjectCreatedEventListener::class)` is called
- AND `$context->registerEventListener(ObjectUpdatedEvent::class, ObjectUpdatedEventListener::class)` is called

## ADDED Requirements

### Requirement: APB-016 — Remove debug logging before production release

The `ObjectUpdatedEventListener` MUST NOT contain the temporary `OPENCATALOGI_EVENT_LISTENER_CALLED_AT_*` debug log entries in any production release. These entries were added during development and add noise to production Nextcloud logs on every object update.

#### Scenario: no debug logging in production
- GIVEN the opencatalogi app is deployed to a production Nextcloud instance
- WHEN an `ObjectUpdatedEvent` is dispatched for any object
- THEN no `OPENCATALOGI_EVENT_LISTENER_CALLED_AT_*` entries appear in the Nextcloud log
- AND functional logging (info/error from `EventService` processing) is preserved

#### Scenario: grep confirms debug logging is absent
- WHEN a reviewer greps `lib/Listener/ObjectUpdatedEventListener.php` for `OPENCATALOGI_EVENT_LISTENER_CALLED_AT_`
- THEN the pattern is not found
- AND the file passes `composer check:strict`

---

### Requirement: APB-017 — Safe file retrieval on update events replaces the `@self.files = []` placeholder

The `ObjectUpdatedEventListener` MUST retrieve the actual file list for an updated object using `FileMapper.getFilesForObject()`. Setting `@self.files = []` silently skips attachment publishing on all update events, even when files exist.

#### Scenario: attachment publishing on update uses the real file list
- GIVEN a published object with 2 file attachments (no `share_token` on either) is updated
- AND `auto_publish_attachments` is `true`
- WHEN `ObjectUpdatedEventListener` processes the `ObjectUpdatedEvent`
- THEN the listener retrieves the file list via `FileMapper.getFilesForObject($objectId)`
- AND `EventService.publishObjectAttachments()` is called with the populated file list
- AND share links are created for both files
- AND the result shows `attachmentsPublished = 2`

#### Scenario: FileMapper is injected into ObjectUpdatedEventListener via constructor DI
- GIVEN `lib/Listener/ObjectUpdatedEventListener.php`
- WHEN the listener is constructed by the Nextcloud DI container
- THEN `FileMapper` is injected as a constructor parameter
- AND the listener does NOT instantiate `FileMapper` directly

## Glossary

- **`auto_publish_objects`** — App config key (`IAppConfig`) controlling whether newly created objects matching a catalog are auto-published. Default: `"false"`.
- **`auto_publish_attachments`** — App config key controlling whether files on published objects receive automatic public share links. Default: `"false"`.
- **`shouldAutoPublishObject()`** — `EventService` method that iterates all configured catalogs and returns `true` if the object's `@self.register` and `@self.schema` match at least one catalog's `registers[]` and `schemas[]`.
- **`isObjectPublished()`** — `EventService` method that compares `@self.published` and `@self.depublished` timestamps to determine the current publication state.
- **`FileMapper`** — OpenRegister database mapper used for direct file record access. Preferred over `ObjectService` inside `publishObjectAttachments()` to prevent infinite event loops.

## References

- `proposal.md`, `design.md`, `tasks.md` (this change)
- `openspec/specs/auto-publishing/spec.md` (canonical spec — fold this delta back via `/opsx:sync`)
- `lib/Listener/ObjectCreatedEventListener.php`
- `lib/Listener/ObjectUpdatedEventListener.php`
- `lib/Service/EventService.php`
- `lib/Service/SettingsService.php`
- `lib/AppInfo/Application.php`
- `openspec/changes/fix-catalog-update-infinite-loop/` (related: infinite-loop pattern in catalog listeners)
- `openspec/changes/opencatalogi-adopt-or-abstractions/` (Phase 8: rewrite auto-publishing spec to consume `x-openregister-lifecycle`)
