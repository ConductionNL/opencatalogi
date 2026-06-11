---
status: draft
---

# publication-retention-lifecycle Specification

## Purpose
Give publications an explicit, auditable time dimension: scheduled
(embargoed) publication, scheduled depublication, statutory retention
metadata per WOO information category (Archiefwet / selectielijst), automatic
action on retention expiry, and an `archived` end state with a disposal
decision trail. Built entirely on mechanisms OpenCatalogi already consumes
(hydra ADR-022): the `@self.published` / `@self.depublished` timestamps that
the OR published-predicate evaluates (APB-006), the `archived` state already
declared in `x-openregister-lifecycle` (APB-SM-001), schema-declared
notifications (ADR-031), and the OR immutable audit-trail abstraction. The
only new moving part is one daily retention-evaluation background job.

## Context
The `publications` spec treats publish/depublish as instantaneous manual
actions (PUB-016/017/018). The lifecycle schema declares `archived` but no
code path ever reaches it; no retention metadata exists; nothing warns an
officer that a statutory term is expiring. WOO information categories carry
retention expectations and the Archiefwet requires substantiated, recorded
retention/disposal decisions — today the app can satisfy neither without
hand-work outside the system.

**Relation to existing specs:**
- `auto-publishing` APB-006: public visibility is derived from
  `@self.published`/`@self.depublished` — this spec makes the future-dated
  use of those timestamps an explicit, surfaced contract (embargo/scheduling)
  rather than an accident.
- `auto-publishing` APB-SM-001: the `published → archived` transition is
  declared in `x-openregister-lifecycle`; this spec adds the triggers that
  request it. No PHP state machine.
- `publications` PUB-016/017/018: the publish/depublish store actions and
  confirmation dialog gain date-time scheduling; the depublication path is
  reused unchanged for retention-driven depublication.
- `woo-compliance` WOO-003: the 17 WOO information categories key the
  per-catalog retention defaults; sitemaps must stay coherent with scheduled
  visibility.
- `opencatalogi-notifications` (in-flight change): establishes the
  schema-declared `x-openregister-notifications` pattern these retention
  notifications extend.
- `dashboard` / `retrofit-2026-05-26-object-table-listing` /
  `retrofit-2026-05-26-mass-object-actions`: the review queue and widget are
  built on these existing surfaces.

## ADDED Requirements

### Requirement: Scheduled publication (embargo) via future `@self.published` (RET-001)
The system MUST support scheduling a publication by setting
`@self.published` to a future date-time. Until that moment the object MUST be
invisible on every public surface — public publications API (PUB-001/002),
search, sitemaps (WOO-001), DCAT feed, and federation — because all of them
derive visibility from the OR published-predicate; OpenCatalogi MUST NOT
implement a second embargo mechanism or visibility check (hydra ADR-022).
From the scheduled moment the object MUST be publicly visible without any
further user action.

#### Scenario: Embargoed besluit invisible before its effective date
- GIVEN a publication with `@self.published` set to tomorrow 09:00
- WHEN the public publications API, search, and sitemap are requested today
- THEN the publication MUST NOT appear on any of them
- AND an anonymous direct fetch of the publication MUST return `404`

#### Scenario: Publication appears at the scheduled moment
- GIVEN the same publication
- WHEN the public surfaces are requested after tomorrow 09:00
- THEN the publication MUST appear on all of them with no manual action
- AND no OpenCatalogi cron or listener MUST be required to "flip" it (the
  predicate evaluation does)

#### Scenario: Public cache headers respect the embargo boundary
- GIVEN a cacheable public surface (sitemap, DCAT document) containing
  publications with a pending embargo in its scope
- WHEN cache validators (`Last-Modified`/`ETag`/max-age) are emitted
- THEN they MUST NOT cause a conditional `304`/cached response to mask the
  embargo moment by more than the surface's documented cache granularity

#### Scenario: Schedule set from the publish dialog
- GIVEN a user opens the publish confirmation dialog (PUB-018)
- WHEN they choose "Publish on" and pick a future date-time
- THEN the store action MUST set `@self.published` to that date-time
- AND the publication list MUST show a "Scheduled" status with the scheduled
  moment

### Requirement: Scheduled depublication via future `@self.depublished` (RET-002)
The system MUST support scheduling depublication by setting
`@self.depublished` to a future date-time, after which the object MUST
disappear from all public surfaces via the same predicate evaluation — no
bespoke takedown job. The depublish dialog (PUB-018) MUST offer
"Depublish on" alongside immediate depublication.

#### Scenario: Time-limited publication expires automatically
- GIVEN a publication of a temporary traffic measure with
  `@self.depublished` set to 2026-09-01
- WHEN public surfaces are requested on 2026-09-02
- THEN the publication MUST NOT appear on any public surface
- AND its internal (authenticated) record MUST remain intact

#### Scenario: Clearing a scheduled depublication
- GIVEN a publication with a future `@self.depublished`
- WHEN an authorized user clears the scheduled date before it passes
- THEN the publication MUST remain publicly visible past the previously
  scheduled moment

### Requirement: Retention metadata on the publication schema (RET-003)
The publication schema MUST gain optional retention fields, stored in OR like
any other field (no new tables, ADR-022): `retentionCategory` (selectielijst /
WOO information-category reference), `retentionTermMonths` (positive
integer), `retentionAction` (enum: `review`, `depublish`, `archive`),
`retentionExpiresAt` (date-time, computed as the publication date plus the
term, recomputed when either changes), and `retentionNote` (free text, legal
basis / exception rationale). `retentionExpiresAt` MAY be manually overridden,
in which case a non-empty `retentionNote` MUST be required.

#### Scenario: Expiry computed from term
- GIVEN a publication published on 2026-06-11 with
  `retentionTermMonths: 12`
- WHEN the object is saved
- THEN `retentionExpiresAt` MUST be 2027-06-11

#### Scenario: Manual override requires a note
- GIVEN a publication officer overrides `retentionExpiresAt` to a later date
- WHEN they attempt to save without a `retentionNote`
- THEN the save MUST be rejected with a validation message requiring the
  rationale

#### Scenario: Term change recomputes expiry
- GIVEN a publication with a computed `retentionExpiresAt`
- WHEN `retentionTermMonths` is changed
- THEN `retentionExpiresAt` MUST be recomputed from the publication date
  (unless a manual override with note is in place)

### Requirement: Per-catalog retention defaults per WOO information category (RET-004)
Admin settings MUST allow configuring, per catalog, a default
`{retentionTermMonths, retentionAction}` per WOO information category (the 17
categories of WOO-003) plus a catalog-wide fallback. When a publication is
first published, the matching default MUST be applied to any retention field
the officer left empty; already-set values MUST NOT be overwritten, and
defaults changed later MUST NOT retroactively alter existing publications.
Retention terms MUST NOT be hard-coded in PHP.

#### Scenario: Default applied at publication time
- GIVEN catalog "vergunningen" configures category "vergunningen" with
  `{ termMonths: 12, action: "depublish" }`
- WHEN a publication in that category is published without retention fields
- THEN the publication MUST carry `retentionTermMonths: 12`,
  `retentionAction: "depublish"`, and a computed `retentionExpiresAt`

#### Scenario: Officer override wins over the default
- GIVEN the same catalog default
- WHEN a publication is published with `retentionTermMonths: 60` already set
- THEN the value 60 MUST be preserved

#### Scenario: Changing a default is not retroactive
- GIVEN existing publications created under the old default of 12 months
- WHEN the admin changes the category default to 24 months
- THEN existing publications MUST keep their stored retention values

### Requirement: Daily retention evaluation job (RET-005)
The system MUST run a daily background job (registered via the proper
IRegistrationContext background-job registration) that queries OR for
publications whose `retentionExpiresAt` falls within the warning window
(default 30 days, catalog-configurable) or in the past, and acts per the
object's `retentionAction`. The job MUST be a dumb evaluator of stored
fields/declared rules — no policy in code — and MUST be idempotent (re-runs
on the same day produce no duplicate notifications or repeated actions).

#### Scenario: Expiring-soon publication is flagged and notified
- GIVEN a publication with `retentionExpiresAt` 20 days from now and a
  30-day warning window
- WHEN the daily job runs
- THEN the publication MUST be flagged as "expiring soon"
- AND the declared expiring-soon notification event MUST be emitted exactly
  once for this window

#### Scenario: Expired publication with action `depublish`
- GIVEN a publication whose `retentionExpiresAt` passed yesterday with
  `retentionAction: "depublish"`
- WHEN the daily job runs
- THEN `@self.depublished` MUST be set (the standard PUB-017 depublication
  path — no parallel takedown mechanism)
- AND the action MUST be recorded via the OR audit-trail abstraction with
  reason "retention term expired"

#### Scenario: Expired publication with action `archive`
- GIVEN an expired publication with `retentionAction: "archive"`
- WHEN the daily job runs
- THEN the job MUST request the `archive` transition declared in the
  schema's `x-openregister-lifecycle` (APB-SM-001); OR validates the
  transition — OpenCatalogi MUST NOT guard it in PHP

#### Scenario: Expired publication with action `review` is never auto-actioned
- GIVEN an expired publication with `retentionAction: "review"`
- WHEN the daily job runs
- THEN the publication MUST only be flagged "retention review required" and
  notified; its public visibility MUST NOT change automatically

#### Scenario: Job re-run is idempotent
- GIVEN the daily job already processed today's expiries
- WHEN the job runs again the same day
- THEN no duplicate notifications MUST be emitted and no action repeated

### Requirement: Archived state semantics (RET-006)
A publication in the `archived` lifecycle state MUST be: invisible on every
public surface (the transition sets `@self.depublished` as part of archiving),
retained internally with its full object history and attachments, excluded
from sitemaps/DCAT/federation, visible to authenticated users behind an
"Archived" filter, and included in the retention report. Archiving MUST NOT
delete anything; physical disposal (vernietiging) MUST always be an explicit,
separately recorded human decision.

#### Scenario: Archived publication leaves public surfaces but not the system
- GIVEN a publication transitioned to `archived`
- WHEN public surfaces and the authenticated publications list are compared
- THEN the publication MUST appear on none of the public surfaces
- AND it MUST appear in the authenticated list under the "Archived" filter
  with attachments and history intact

#### Scenario: Disposal is a recorded human decision
- GIVEN an archived publication past its retention term
- WHEN an authorized officer records a disposal decision
- THEN the decision (who, when, legal basis from `retentionCategory`,
  mandatory rationale) MUST be persisted via the OR immutable audit-trail
  abstraction before any deletion is possible
- AND the retention report MUST list the disposal with its decision record

### Requirement: Retention review queue and dashboard widget (RET-007)
Publication officers MUST be able to work expiries from a review queue: the
existing publications listing filtered/faceted by retention status
(`expiring soon`, `expired — review required`, `archived`), with per-item and
bulk decisions "extend term" (requires a note; updates `retentionExpiresAt`),
"depublish now", and "archive" — reusing the existing object-table,
mass-object-actions, and dialog surfaces, not a new view framework. The
dashboard MUST show a retention widget with the expiring-soon and
review-required counts linking into the queue.

#### Scenario: Officer extends a term from the queue
- GIVEN a publication flagged "expiring soon" in the review queue
- WHEN the officer chooses "extend term", enters 12 extra months and a
  rationale note
- THEN `retentionExpiresAt` MUST move accordingly, the note MUST be stored,
  and the flag MUST clear
- AND the extension MUST be recorded via the audit-trail abstraction

#### Scenario: Bulk archive from the queue
- GIVEN 8 publications flagged "expired — review required"
- WHEN the officer multi-selects them and chooses "archive"
- THEN all 8 MUST be transitioned via the declared lifecycle transition
- AND each MUST get its own audit record

#### Scenario: Dashboard widget counts
- GIVEN 5 expiring-soon and 2 review-required publications
- WHEN the officer opens the dashboard
- THEN the retention widget MUST show both counts
- AND clicking a count MUST open the queue pre-filtered to that status

### Requirement: Retention notifications are schema-declared (RET-008)
Retention notifications — "publication expiring soon", "retention review
required", "publication auto-depublished/archived on expiry" — MUST be
declared via the canonical `x-openregister-notifications` dialect on the
publication schema (ADR-031, extending the rules established by the
`opencatalogi-notifications` change). OpenCatalogi MUST NOT register bespoke
notification listeners or its own cron-based reminder dispatch beyond the
RET-005 evaluation job emitting the declared events.

#### Scenario: Expiring-soon notification reaches the responsible officer
- GIVEN a declared expiring-soon notification rule on the publication schema
- WHEN the RET-005 job flags a publication
- THEN the notification MUST be dispatched through the declared dialect to
  the configured audience (e.g. catalog editors group)
- AND it MUST deep-link to the publication in the review queue

#### Scenario: Legacy dialect is not introduced
- GIVEN the retention notification rules
- WHEN the schema configuration is inspected
- THEN the rules MUST use the canonical dialect only (the legacy notification
  dialect MUST NOT appear — gate-18)

### Requirement: Retention report export (RET-009)
The system MUST provide an authenticated export of the retention ledger per
catalog and date range as CSV (UTF-8 with BOM): one row per publication with
publication date, retention category, term, expiry, action taken (or
pending), decision maker, and decision note — sufficient for Archiefwet /
audit accountability. The export MUST be derived from stored fields and
audit-trail records; no separate reporting store.

#### Scenario: Annual retention report
- GIVEN catalog "woo-besluiten" with publications expired, extended, and
  archived during 2026
- WHEN the officer exports the retention report for 2026
- THEN the CSV MUST contain one row per publication with the columns above
- AND extensions and disposals MUST reference their audit-trail decision
  records

#### Scenario: Export is authenticated
- GIVEN an anonymous request to the retention report endpoint
- WHEN the request is processed
- THEN it MUST be rejected (this is an internal accountability surface, not
  a public API)

## Non-Requirements
- This spec does NOT build a scheduler/state machine — scheduling is the
  published-predicate timestamps; transitions are `x-openregister-lifecycle`
  (ADR-022).
- This spec does NOT build notification dispatch — schema-declared dialect
  (ADR-031).
- This spec does NOT build an audit store — OR immutable audit-trail
  abstraction.
- This spec does NOT cover physical transfer to an e-Depot or TMLO/MDTO
  export — a future change consuming the archive state + decision trail.
- This spec does NOT delete files or objects automatically — disposal is
  always an explicit recorded human decision.
- This spec does NOT change WOO sitemap structure (woo-compliance) — it only
  relies on sitemaps already filtering on the published-predicate.

## Dependencies
- OR published-predicate over `@self.published`/`@self.depublished`
  (APB-006) — consumed for all scheduled visibility (NOTE: the known OR
  magic-mapping gap where magic-mapped objects cannot set `@self.published`
  is an upstream dependency for embargo on such objects).
- `x-openregister-lifecycle` on the publication schema (APB-SM-001) —
  consumed for the `archive` transition.
- `x-openregister-notifications` dialect (ADR-031) + the in-flight
  `opencatalogi-notifications` change — consumed for all retention
  notifications.
- OR immutable audit-trail abstraction — consumed for extension/disposal
  decision records.
- Existing publish/depublish store actions + dialog (PUB-016/017/018) —
  extended with date-time scheduling.
- Existing object-table, mass-object-actions, dashboard-widget surfaces
  (retrofit specs) — host the review queue and widget.
- admin-settings spec — hosts per-catalog category defaults and the warning
  window.
- woo-compliance WOO-003 — the WOO information-category list keying the
  defaults.

### Current Implementation Status
- **Not yet implemented**: no retention fields, defaults, evaluation job,
  review queue, archived-filter UI, retention notifications, or report exist.
- **Building blocks that exist**: published-predicate evaluation (APB-006)
  already honours future-dated timestamps end-to-end on the API path;
  `archived` is already declared in the lifecycle example (APB-SM-001);
  publish/depublish store actions + confirmation dialog (PUB-016..018);
  directory-sync cron as the background-job registration pattern; object
  table + facets, mass actions, dashboard widgets (retrofit specs); CSV
  export precedent (inventarislijst in `woo-transparency`).
- **Key gaps**: publish/depublish UI is now-or-nothing (no date pickers); no
  code path ever requests the `archive` transition; cache validators on
  public surfaces have not been audited against embargo boundaries.
