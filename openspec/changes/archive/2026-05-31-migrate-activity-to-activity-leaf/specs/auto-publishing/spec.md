---
status: draft
---

# auto-publishing Specification (delta)

This delta consumes the **OpenRegister activity leaf** (integration registry,
ADR-019) for the per-publication activity feed and clarifies that OpenCatalogi's
object-lifecycle listeners are NOT the user-facing activity surface (hydra
ADR-022). The auto-publishing *side effect* — an OpenCatalogi-specific publishing
policy with no OR leaf equivalent — is explicitly kept in-app; only the activity
*feed* responsibility moves to the leaf.

## ADDED Requirements

### Requirement: Per-publication activity feed consumes the OpenRegister activity leaf (APB-ACT-001)
The system MUST provide the per-publication activity feed (create / update /
publish / depublish / file-change history) by **consuming the OpenRegister
activity leaf** sourced from OR's event stream and audit trail — NOT by building
a bespoke in-app activity log on top of `ObjectCreatedEventListener` /
`ObjectUpdatedEventListener` (hydra ADR-022). The feed is surfaced as the activity
widget on the publication detail page via the app manifest (ADR-024 / ADR-036).

#### Scenario: View a publication's activity history
- GIVEN a publication that has been created, updated, and published
- WHEN a user opens the publication detail page and views the activity widget
- THEN the create / update / publish events are listed from the OpenRegister
  activity leaf
- AND OpenCatalogi does NOT maintain a separate in-app activity table for these
  events

#### Scenario: Activity leaf absent
- GIVEN the OpenRegister activity leaf / integration is not available
- WHEN the publication detail page renders
- THEN the activity widget degrades gracefully ("activity integration required")
  rather than falling back to a bespoke feed

## MODIFIED Requirements

### Requirement: Listen to OpenRegister `ObjectCreatedEvent` and trigger auto-publishing logic (APB-001)
The system MUST listen to OpenRegister `ObjectCreatedEvent` and trigger
auto-publishing logic. This listener's responsibility is limited to the
OpenCatalogi-specific **auto-publishing side effect** (catalogue-membership +
WOO publishing policy), which has NO OpenRegister leaf equivalent and is
explicitly kept in-app per hydra ADR-022. The listener MUST NOT serve as a
bespoke activity feed — object-change activity is consumed from the OR activity
leaf (APB-ACT-001). Debug `OPENCATALOGI_EVENT_*` logging is removed.

#### Scenario: Created object triggers auto-publish only
- GIVEN `auto_publish_objects` is enabled and a catalogue object is created
- WHEN `ObjectCreatedEventListener` handles the event
- THEN it performs only the auto-publishing side effect
- AND it does NOT write to a bespoke activity log (the activity feed is the
  OR activity leaf)

### Requirement: Listen to OpenRegister `ObjectUpdatedEvent` and trigger auto-publishing logic (APB-002)
The system MUST listen to OpenRegister `ObjectUpdatedEvent` and trigger
auto-publishing logic. As with APB-001, the listener's scope is the
OpenCatalogi-specific auto-publishing side effect only; the per-object activity
feed is consumed from the OR activity leaf (APB-ACT-001), NOT reimplemented here.
Debug `OPENCATALOGI_EVENT_*` logging is removed.

#### Scenario: Updated object triggers auto-publish only
- GIVEN `auto_publish_objects` is enabled and a catalogue object is updated such
  that `shouldProcessUpdate()` passes
- WHEN `ObjectUpdatedEventListener` handles the event
- THEN it performs only the auto-publishing side effect
- AND it does NOT write to a bespoke activity log
