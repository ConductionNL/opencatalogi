# Tasks: auto-publishing

## Task 1: Deduplication check

- **Spec ref**: specs/auto-publishing/spec.md
- **Status**: done
- [ ] Verify no overlap with OpenRegister's built-in publishing capabilities.
  - `ObjectService.publish()` is the canonical publish method — opencatalogi must not re-implement it.
  - `FileService.createShareLink()` is the canonical share-link method — no custom sharing logic.
  - `FileMapper.getFilesForObject()` is used specifically to avoid re-triggering events from inside the event handler (APB-010). This is an explicit architectural decision, not a bypass of OR abstractions.
  - **Finding**: no duplication found. Auto-publishing is a thin orchestration layer over existing OR services: it calls `ObjectService.publish()`, `FileService.createShareLink()`, and `FileMapper.getFilesForObject()` — all provided by OpenRegister — and does not re-implement any of their logic.

---

## Task 2: Remove debug logging from ObjectUpdatedEventListener

- **Spec ref**: specs/auto-publishing/spec.md (APB-016)
- **Status**: todo
- **Files**: `lib/Listener/ObjectUpdatedEventListener.php`
- **Acceptance criteria**:
  - [ ] All `OPENCATALOGI_EVENT_LISTENER_CALLED_AT_*` log statements are removed from the listener.
  - [ ] No other development-only debug log entries remain in the listener.
  - [ ] Functional logging (info/error results from `EventService`) is preserved.
  - [ ] `grep -n "OPENCATALOGI_EVENT_LISTENER_CALLED_AT_" lib/Listener/ObjectUpdatedEventListener.php` returns no matches.
  - [ ] `composer check:strict` passes.

---

## Task 3: Fix file retrieval on update events (resolve @self.files = [] TODO)

- **Spec ref**: specs/auto-publishing/spec.md (APB-017)
- **Status**: todo
- **Files**: `lib/Listener/ObjectUpdatedEventListener.php`
- **Acceptance criteria**:
  - [ ] The `@self.files = []` placeholder is replaced with `FileMapper.getFilesForObject($objectId)`.
  - [ ] `FileMapper` is injected into `ObjectUpdatedEventListener` via constructor dependency injection (no `new FileMapper(...)` instantiation inside the class).
  - [ ] When a published object with 2 unpublished attachments is updated (both have no `share_token`), the processing result shows `attachmentsPublished = 2`.
  - [ ] No new `ObjectUpdatedEvent` is dispatched as a side effect of the `FileMapper` call (FileMapper reads directly from DB — does not touch the event bus).
  - [ ] `composer check:strict` passes.

---

## Task 4: Verify and unit-test the /OpenRegister/ path prefix transformation

- **Spec ref**: specs/auto-publishing/spec.md (APB-004)
- **Status**: todo
- **Files**: `lib/Service/EventService.php`, `tests/Unit/Service/EventServiceTest.php`
- **Acceptance criteria**:
  - [ ] Confirm `EventService.publishObjectAttachments()` prepends `/OpenRegister/` to the FileMapper path before calling `FileService.createShareLink()`.
  - [ ] Add a unit test asserting the path transformation: a FileMapper path of `files/object-uuid/document.pdf` becomes `/OpenRegister/files/object-uuid/document.pdf` when passed to `FileService.createShareLink()`.
  - [ ] Unit test uses a mock `FileService` and asserts the exact path argument.

---

## Task 5: Add unit tests for EventService scenarios

- **Spec ref**: specs/auto-publishing/spec.md (APB-003 through APB-014)
- **Status**: todo
- **Files**: `tests/Unit/Service/EventServiceTest.php`
- **Acceptance criteria**:
  - [ ] **APB-003**: `handleObjectCreateEvents()` with a matching catalog calls `ObjectService.publish()` once; with no catalog match it does not call `publish()`.
  - [ ] **APB-004**: `publishObjectAttachments()` calls `FileService.createShareLink()` for each file without a `share_token`.
  - [ ] **APB-006**: `isObjectPublished()` returns `true` for published-only, `false` for depublished-after-published, `true` for published-after-depublished.
  - [ ] **APB-007**: When both options are `false`, the listener returns without calling any `EventService` method.
  - [ ] **APB-011**: `publishObjectAttachments()` skips files that already have a `share_token`.
  - [ ] **APB-012**: The returned result array contains `processed`, `published`, `attachmentsPublished`, `errors`, and `details` keys with the correct values.
  - [ ] **APB-014**: An exception from `ObjectService.publish()` is caught; the method still returns a result array with the error recorded in `errors`.
  - [ ] All tests pass under `composer test`.

---

## Task 6: Add integration tests for infinite-loop guard and attachment publishing

- **Spec ref**: specs/auto-publishing/spec.md (APB-010, APB-017)
- **Status**: todo
- **Files**: `tests/Integration/` or `tests/Feature/`
- **Acceptance criteria**:
  - [ ] **APB-010 regression guard**: Create a published object with 3 unpublished attachments in a configured catalog. Enable `auto_publish_attachments`. Assert that the total number of `ObjectUpdatedEvent` dispatches caused by the share-link creation is zero (FileMapper reads DB directly — no events). Without the `FileMapper` guard, this test would trigger recursive event dispatches.
  - [ ] **APB-017 end-to-end**: Update a published object that has 2 attachments (no `share_token`). Assert `attachmentsPublished = 2` in the processing result. This test fails before Task 3 (files = []) and passes after.
  - [ ] **APB-005 end-to-end**: Create an object with a register/schema that matches no catalog. Assert the object is not published and `published = 0` in the result.

---

## Task 7: Sync spec delta to canonical spec

- **Spec ref**: `openspec/specs/auto-publishing/spec.md`
- **Status**: todo
- **Acceptance criteria**:
  - [ ] `openspec/specs/auto-publishing/spec.md` is updated to include APB-016 and APB-017 from this delta.
  - [ ] All APB-001 through APB-015 entries in the canonical spec are updated to include their GIVEN/WHEN/THEN scenarios as written in `specs/auto-publishing/spec.md` in this change.
  - [ ] The canonical spec status is updated from `reviewed` to the appropriate status after sync.
  - [ ] Run `/opsx:sync` after Tasks 2–6 are verified and this change is ready to archive.

---

## Cross-cutting acceptance criteria

- [ ] `C.1` No `ObjectService` call exists inside `EventService.publishObjectAttachments()` after Task 3. `FileMapper` is the only file-data source in that method (APB-010 hard constraint).
- [ ] `C.2` `composer check:strict` passes on all modified files after each task.
- [ ] `C.3` The processing result structure (`processed`, `published`, `attachmentsPublished`, `errors`, `details`) is returned by both `handleObjectCreateEvents()` and `handleObjectUpdateEvents()` on every code path — including error paths (APB-012, APB-014).
- [ ] `C.4` No new custom database tables, REST endpoints, or frontend components are introduced by this change. Auto-publishing remains a pure event-listener + service layer over existing OpenRegister primitives.
