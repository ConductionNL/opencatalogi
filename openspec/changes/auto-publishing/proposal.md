# Proposal: auto-publishing

## Summary

Specify and document the auto-publishing system in OpenCatalogi, which automatically publishes OpenRegister objects and their file attachments when they are created or updated via Nextcloud's event dispatcher. This eliminates manual publishing workflows for organizations that want all catalog content to be immediately public.

## Motivation

- **User impact**: Organizations managing public catalogs need objects to be instantly visible after creation without manual publish steps. The implementation is complete (see `context-brief.md`), but the specification was missing formal requirements, design rationale, and GIVEN/WHEN/THEN acceptance scenarios.
- **Code quality**: The `ObjectUpdatedEventListener` contains temporary debug logging (`OPENCATALOGI_EVENT_LISTENER_CALLED_AT_*`) that must be removed before the next production release.
- **Correctness gap**: The `ObjectUpdatedEventListener` sets `@self.files = []` with a TODO comment indicating that file attachment data is not safely retrieved for update events. Until this is resolved, attachment publishing silently skips files on update events even when files exist.
- **Infinite-loop risk**: The system uses `FileMapper` for direct database access to avoid triggering fresh `ObjectUpdatedEvent` dispatches. This architectural decision is not documented and must be captured in the spec to prevent future regressions where `FileMapper` is swapped for `ObjectService`.

## Scope

### In scope

- Formal specification of `APB-001` through `APB-015` requirements with GIVEN/WHEN/THEN acceptance scenarios derived from the scenarios in `context-brief.md`.
- Design documentation: event flow, component responsibilities, configuration keys, and reuse analysis.
- New requirement APB-016: remove debug logging before production release.
- New requirement APB-017: replace the `@self.files = []` placeholder with a safe `FileMapper.getFilesForObject()` call on update events.
- Integration test plan covering all spec scenarios.
- Path-prefix verification task: ensure the `/OpenRegister/` prefix is correctly applied when converting FileMapper paths to share-link paths.
- Spec sync: fold this delta back into `openspec/specs/auto-publishing/spec.md` via `/opsx:sync` after verification.

### Out of scope

- Changes to the OpenRegister event system, `ObjectService`, or `FileService` — this change is contained in OpenCatalogi.
- New auto-publishing triggers beyond `ObjectCreatedEvent` and `ObjectUpdatedEvent`.
- A UI for configuring auto-publishing (settings keys are already surfaced in `SettingsService` and the existing admin-settings page).
- Any changes to `CatalogSchemaEventListener` or `CatalogCacheEventListener` — those are addressed in `fix-catalog-update-infinite-loop`.

## Risks

- **Infinite-loop regression**: Any future change that replaces `FileMapper` with `ObjectService` for file retrieval inside `publishObjectAttachments()` would re-introduce an infinite event loop. Captured as a hard constraint in APB-010 so reviewers can gate against it.
- **Silent attachment skips on update**: The `@self.files = []` TODO means attachment publishing does nothing on update events today. Resolving this (APB-017) requires injecting `FileMapper` into `ObjectUpdatedEventListener` via constructor DI; this is a small, low-risk change.
- **Debug logging in production**: The temporary `OPENCATALOGI_EVENT_LISTENER_CALLED_AT_*` log entries add noise to production Nextcloud logs on every object update. Must be removed (APB-016) before the next release.
- **ObjectEntity conversion**: Both listeners manually construct the `@self` metadata array because `jsonSerialize()` may not include all required fields. This workaround is documented in the design; a future OpenRegister update should expose a stable `toArray()` contract to replace it.
