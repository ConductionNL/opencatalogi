---
status: done
or_dep: x-openregister-lifecycle
audit_ref: .claude/audit-2026-05-03/04-hardcoded.md
---

# Auto-Publishing

> **x-openregister-lifecycle citation (Phase 8):** This spec is updated
> as part of `opencatalogi-adopt-or-abstractions` (Phase 8). The PHP
> state machine for publication state transitions is replaced by a
> citation of OR's `x-openregister-lifecycle` schema extension.
> opencatalogi MUST NOT encode allowed transitions, guards, or state
> sequences in PHP. See the REMOVED section and Breaking Changes.
>
> Upstream dependency: OR `x-openregister-lifecycle` schema extension
> (ADR-031).

## Purpose

@e2e exclude retrofit spec — auto-publishing is event-driven backend behaviour (OR ObjectCreated/Updated listeners, catalog-membership evaluation, share-link side effects, lifecycle state-machine consumption) with no browser-UI surface; covered by PHPUnit and Newman API tests.

The auto-publishing system automatically publishes OpenRegister objects and
their file attachments when they are created or updated, based on configurable
admin options. It listens to OR's `ObjectCreatedEvent` and
`ObjectUpdatedEvent` events, evaluates whether the object belongs to a
configured catalog, and triggers publish and share-link creation.

**Scope limitation:** This system's responsibility is limited to the
opencatalogi-specific side effect — catalog-membership evaluation and WOO
publishing policy. This has NO OR leaf equivalent (APB-001 rationale).
The system MUST NOT serve as a bespoke activity feed (APB-ACT-001) and
MUST NOT encode state machine transitions in PHP (APB-SM-001 below).

After Phase 8:
- **Publication state transitions** (draft → review → published → archived)
  are declared in the publication schema via `x-openregister-lifecycle`
  and executed by OR. opencatalogi is NOT responsible for computing allowed
  transitions or enforcing guards.
- **Auto-publish side effect** (setting `@self.published` when an object
  matches a catalog) remains in-app because it is opencatalogi-specific
  policy with no OR leaf equivalent.

## ADDED Requirements

### Requirement: publication state transitions consumed from `x-openregister-lifecycle` (APB-SM-001)

The publication schema MUST declare its state machine via the
`x-openregister-lifecycle` extension:

```json
{
  "x-openregister-lifecycle": {
    "states": ["draft", "review", "published", "archived"],
    "transitions": [
      { "from": "draft",     "to": "review",    "trigger": "submitForReview" },
      { "from": "review",    "to": "published", "trigger": "publish" },
      { "from": "review",    "to": "draft",     "trigger": "reject" },
      { "from": "published", "to": "archived",  "trigger": "archive" },
      { "from": "published", "to": "draft",     "trigger": "depublish" }
    ]
  }
}
```

opencatalogi MUST NOT encode this state machine in a PHP class. Any PHP code
that hard-codes state names, validates allowed transitions, or acts as a
transition guard MUST be removed in the Phase 8 implementation.

> @e2e exclude Backend state-machine contract (allowed transitions/guards come from the schema's `x-openregister-lifecycle`; OR rejects undeclared transitions; opencatalogi holds no duplicate PHP state machine) — no UI surface; verified by PHPUnit (state-change processing reads the schema, invalid transition rejected by OR) plus a grep assertion that no PHP transition guard remains.

#### Scenario: publishing transitions read from the schema

- **GIVEN** the publication schema declares `x-openregister-lifecycle`,
- **WHEN** opencatalogi processes a state change,
- **THEN** the allowed transitions and guards come from the schema declaration,
- **AND** PHP code in opencatalogi does NOT hold a duplicate state machine.

#### Scenario: invalid transition rejected by OR

- **GIVEN** a publication is in state `draft`,
- **WHEN** a user attempts to apply the `archive` transition (not declared
  from `draft`),
- **THEN** OR rejects the transition per the schema lifecycle declaration,
- **AND** opencatalogi does NOT perform its own duplicate transition-validity
  check.

### Requirement: per-publication activity feed consumes the OR activity leaf (APB-ACT-001)

The per-publication activity feed (create / update / publish / depublish /
file-change history) MUST be provided by the OR activity leaf sourced from
OR's event stream and audit trail. It is surfaced as the activity widget on
the publication detail page via the app manifest entry for `PublicationDetail`
(`src/manifest.json`, widgetKey: `activity`, ADR-024).

opencatalogi MUST NOT maintain a separate in-app activity table or compute
activity events from `ObjectCreatedEventListener` / `ObjectUpdatedEventListener`.

> @e2e exclude Backend data-source contract for the activity feed (events sourced from the OR activity leaf, opencatalogi maintains no separate in-app activity table, graceful "activity integration required" degradation when the leaf is absent) — the assertion is about the data source and the absence of a bespoke feed, not a browsable surface; verified by PHPUnit (no in-app activity table / listener compute) and vitest (graceful degradation when the leaf is absent). The detail page that hosts the widget is reachable via spa-deep-link-routing::open-a-deep-link-directly.

#### Scenario: view a publication's activity history

- **GIVEN** a publication that has been created, updated, and published,
- **WHEN** a user views the activity widget on the detail page,
- **THEN** the events are listed from the OR activity leaf,
- **AND** opencatalogi does NOT maintain a separate in-app activity table.

#### Scenario: activity leaf absent

- **GIVEN** the OR activity leaf is not available,
- **WHEN** the publication detail page renders,
- **THEN** the activity widget degrades gracefully ("activity integration required"),
  rather than falling back to a bespoke feed.

## Requirements

### Requirement: Listen to `ObjectCreatedEvent` and trigger auto-publishing logic (APB-001)

The system MUST listen to OR's `ObjectCreatedEvent` and trigger auto-publishing
logic. This listener's responsibility is limited to the opencatalogi-specific
catalog-membership + WOO publishing policy side effect, which has NO OR leaf
equivalent (explicit exception per ADR-022). The listener MUST NOT serve as a
bespoke activity feed — object-change activity is consumed from the OR activity
leaf (APB-ACT-001). Debug `OPENCATALOGI_EVENT_*` logging is removed.

**Priority:** Must **Status:** Implemented

#### Scenario: object creation triggers auto-publishing
- GIVEN an `ObjectCreatedEvent` is dispatched by OR
- WHEN the listener handles it
- THEN the system MUST run the catalog-membership and WOO publishing policy side effect

### Requirement: Listen to `ObjectUpdatedEvent` and trigger auto-publishing logic (APB-002)

The system MUST listen to OR's `ObjectUpdatedEvent` and trigger auto-publishing
logic. Scope is identical to APB-001. Debug logging removed.

**Priority:** Must **Status:** Implemented

#### Scenario: object update triggers auto-publishing
- GIVEN an `ObjectUpdatedEvent` is dispatched by OR
- WHEN the listener handles it
- THEN the system MUST run the same catalog-membership and WOO publishing policy side effect as on create

### Requirement: Auto-publish newly created objects when `auto_publish_objects` is enabled (APB-003)
The system MUST auto-publish newly created objects when `auto_publish_objects` is enabled.

**Priority:** Must **Status:** Implemented

#### Scenario: new object is auto-published
- GIVEN `auto_publish_objects` is `"true"`
- WHEN a new object matching a configured catalog is created
- THEN the system MUST set `@self.published` on that object

### Requirement: Auto-publish file attachments when `auto_publish_attachments` is enabled (APB-004)

The system MUST auto-publish file attachments when `auto_publish_attachments`
is enabled. Share links are created via the OR shares leaf or
`OCP\Share\IShareManager` per `file-management/spec.md` FIL-OR-002. The
auto-publishing system MUST NOT call a bespoke `FileService::createShareLink()`.

**Priority:** Must **Status:** Implemented (share path updated in Phase 3)

#### Scenario: attachments get public share links
- GIVEN `auto_publish_attachments` is `"true"` and an object is published
- WHEN its attachments are processed
- THEN the system MUST create public share links via the OR shares leaf or `IShareManager`, not a bespoke `FileService::createShareLink()`

### Requirement: Only auto-publish objects whose register/schema match a configured catalog (APB-005)
The system MUST only auto-publish objects whose register/schema match a configured catalog.

**Priority:** Must **Status:** Implemented

#### Scenario: only catalog-matching objects are published
- GIVEN an object whose register/schema does not match any configured catalog
- WHEN the auto-publish listener evaluates it
- THEN the system MUST NOT publish it

### Requirement: Determine publication status from `@self.published` and `@self.depublished` timestamps (APB-006)

The system MUST determine publication status from the `@self.published` and
`@self.depublished` timestamps. These are set by OR's lifecycle declarations
(APB-SM-001); opencatalogi reads them to determine whether an object is
currently published.

**Priority:** Must **Status:** Implemented

#### Scenario: published status derived from timestamps
- GIVEN an object with `@self.published` set and no later `@self.depublished`
- WHEN its publication status is evaluated
- THEN the system MUST treat the object as currently published

### Requirement: Skip event processing when both auto-publish options are disabled (APB-007)
The system MUST skip event processing when both auto-publish options are disabled.

**Priority:** Should **Status:** Implemented

#### Scenario: no work when both options are off
- GIVEN both `auto_publish_objects` and `auto_publish_attachments` are `"false"`
- WHEN an OR object event arrives
- THEN the system MUST skip processing and do no publishing work

### Requirement: On update events, only process attachment publishing for already-published objects (APB-008)
The system MUST, on update events, only process attachment publishing for already-published objects.

**Priority:** Should **Status:** Implemented

#### Scenario: update only publishes attachments for published objects
- GIVEN an update event for an object that is not yet published
- WHEN attachment publishing is considered
- THEN the system MUST NOT publish attachments until the object itself is published

### Requirement: On update events, detect publication status transitions (APB-009)

The system MUST, on update events, detect publication status transitions.
Status transitions are executed by OR per the `x-openregister-lifecycle`
declaration (APB-SM-001). This listener detects the resulting state change
in the event payload but does NOT compute or validate the transition.

**Priority:** Should **Status:** Implemented

#### Scenario: status transition detected from event payload
- GIVEN an update event whose payload reflects a state change executed by OR
- WHEN the listener processes it
- THEN the system MUST detect the resulting publication status transition without computing or validating it itself

### Requirement: Use FileMapper for direct database file access to avoid infinite loop (APB-010)
The system MUST use FileMapper for direct database file access to avoid an infinite loop.

**Priority:** Must **Status:** Implemented

#### Scenario: direct file access avoids re-triggering events
- GIVEN attachment publishing needs file records
- WHEN the system reads them
- THEN it MUST use FileMapper for direct database access so it does not re-trigger OR object events

### Requirement: Skip already-published files (those with existing share tokens) (APB-011)
The system MUST skip already-published files (those with existing share tokens).

**Priority:** Should **Status:** Implemented

#### Scenario: already-shared files are skipped
- GIVEN a file that already has a share token
- WHEN attachment publishing runs
- THEN the system MUST skip creating a new share link for it

### Requirement: Return structured results with processed/published/error counts (APB-012)
The system MUST return structured results with processed/published/error counts.

**Priority:** Should **Status:** Implemented

#### Scenario: processing returns structured counts
- GIVEN an auto-publish run over a batch
- WHEN it completes
- THEN it MUST return a structured result containing processed, published, and error counts

### Requirement: Log all processing results for monitoring (APB-013)
The system MUST log all processing results for monitoring.

**Priority:** Should **Status:** Implemented

#### Scenario: results are logged
- GIVEN an auto-publish run completes
- WHEN results are available
- THEN the system MUST log the processing results for monitoring

### Requirement: Gracefully handle exceptions without breaking the originating OR operation (APB-014)
The system MUST gracefully handle exceptions without breaking the originating OR operation.

**Priority:** Must **Status:** Implemented

#### Scenario: exception does not break the OR operation
- GIVEN auto-publishing raises an exception while handling an event
- WHEN the exception occurs
- THEN the system MUST catch it so the originating OR create/update operation still succeeds

### Requirement: Event listeners registered in Application.php bootstrap (APB-015)
The system MUST register the event listeners in the Application.php bootstrap.

**Priority:** Must **Status:** Implemented

#### Scenario: listeners wired at bootstrap
- GIVEN the app is bootstrapped via Application.php
- WHEN registration runs
- THEN the `ObjectCreatedEvent` and `ObjectUpdatedEvent` listeners MUST be registered

## REMOVED Requirements

| ID | Title | Reason removed |
|----|-------|----------------|
| (PHP state machine) | Any PHP constant, class, or method that encodes publication state names, validates allowed transitions, or acts as a transition guard | REMOVED — re-implements OR's `x-openregister-lifecycle`; consume OR per ADR-022 and ADR-031. The state machine is declared in the publication schema; PHP code in opencatalogi does NOT hold a parallel version. |

## Breaking Changes

| Breaking change | Old behaviour | New behaviour |
|---|---|---|
| PHP state machine for publication transitions removed | Transition logic in PHP (state names, allowed-transition arrays, guard checks) | State machine declared in publication schema via `x-openregister-lifecycle`; OR executes transitions. Any PHP code checking allowed transitions directly will be deleted. |
| Share-link creation in auto-publishing | Called bespoke `FileService::createShareLink()` | Calls OR shares leaf or `IShareManager::createShare()` per FIL-OR-002; bespoke `FileService::createShareLink()` is deleted. |

## Configuration Keys

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `auto_publish_objects` | string (bool) | `"false"` | When `"true"`, auto-set `@self.published` on objects matching a catalog |
| `auto_publish_attachments` | string (bool) | `"false"` | When `"true"`, auto-create public share links for attachments on published objects |

## References

- OR `x-openregister-lifecycle` schema extension (upstream dependency)
- `.claude/audit-2026-05-03/04-hardcoded.md` (Stream 4 rationale for Phase 8)
- `openspec/changes/opencatalogi-adopt-or-abstractions/` (Phase 8 implementation change)
- `openspec/specs/file-management/spec.md` (FIL-OR-002 — share creation)
- ADR-022 — Apps consume OR abstractions
- ADR-031 — Schema-declarative business logic
