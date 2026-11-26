---
status: done
---

# publication-retention-lifecycle Specification

## Purpose
Give publications an explicit, auditable time dimension: scheduled (embargoed)
publication, scheduled depublication, statutory retention metadata per WOO
information category (Archiefwet / selectielijst), automatic action on retention
expiry, and an `archived` end state with a disposal decision trail. Built
entirely on mechanisms OpenCatalogi already consumes (hydra ADR-022): the
`publicatiedatum` / `depublicatiedatum` timestamps that the OR published-predicate
evaluates, the `archived` state declared in `x-openregister-lifecycle`,
schema-declared notifications (ADR-031), and the OR immutable audit-trail
abstraction. The only new moving part is one daily retention-evaluation
background job (`Cron\RetentionEvaluation`).

> @e2e exclude Backend-and-data capability — every scenario asserts server-side
> behaviour with no distinct browser-observable surface of its own: schema-field
> validation and expiry computation (RET-003), per-catalog default resolution
> (RET-004), the daily evaluation job's per-action behaviour and idempotency
> (RET-005), schema-declared notification rules (RET-008), disposal-decision
> recording and the authenticated CSV report (RET-006/RET-009), and the
> published-predicate visibility of scheduled publish/depublish (RET-001/RET-002).
> These are verified by PHPUnit (`tests/Unit/Service/RetentionServiceTest.php`,
> `tests/Unit/Cron/RetentionEvaluationTest.php`); the review queue filters on the
> server, so no client-side status classifier remains. The user-facing surfaces are reused,
> already-e2e-covered ones: the scheduled publish/depublish date pickers live in
> the mass publish/depublish modals (covered under
> generic-object-modals), the review queue is the existing publications table
> with retention facets, and the dashboard retention widget is an NC dashboard
> widget (not reliably present in a default test environment — same exclusion
> rationale as catalogs::dashboard-widget scenarios).

## Requirements
### Requirement: Scheduled publication (embargo) via future `publicatiedatum` (RET-001)
The system MUST support scheduling a publication by setting its own
`publicationDate` field (formerly `publicatiedatum`) to a future date-time while
its stored lifecycle state is `published` (REQ-PLC-001). Until that moment the
object MUST be invisible on every public surface: public publications API
(PUB-001/002), search, sitemaps (WOO-001), DCAT feed, and federation, because all
of them derive visibility from the one OR RBAC predicate
`{group:public, match:{status:"published", publicationDate:{$lte:$now}, depublicationDate:{$gte:$now}}}`.
A publication whose stored state is anything other than `published` MUST be
invisible on every public surface whatever its dates say. OpenCatalogi MUST NOT
implement a second embargo mechanism or visibility check (hydra ADR-022); the
lifecycle clause is part of the same predicate, not a second check. The
`unlisted` flag (REQ-PLC-006) is a listing filter, not a visibility check: an
unlisted record stays readable at its own link. The removed object-level
`@self.published` predicate is no longer available. From the scheduled moment
the object MUST be publicly visible without any further user action.

#### Scenario: Embargoed besluit invisible before its effective date
- GIVEN a publication in state `published` with `publicationDate` set to tomorrow 09:00
- WHEN the public publications API, search, and sitemap are requested today
- THEN the publication MUST NOT appear on any of them
- AND an anonymous direct fetch of the publication MUST return `404`

#### Scenario: Publication appears at the scheduled moment
- GIVEN the same publication
- WHEN the public surfaces are requested after tomorrow 09:00
- THEN the publication MUST appear on all of them with no manual action
- AND no OpenCatalogi cron or listener MUST be required to "flip" it (the
  predicate evaluation does)

#### Scenario: A draft with a past date stays private
<!-- @e2e exclude Read rule contract; proven by PublicationLifecycleReadRuleTest::testADraftWithAPastPublicationDateIsNotPublic. -->
- GIVEN a publication in state `draft` with `publicationDate` last week
- WHEN the public surfaces are requested
- THEN the publication MUST NOT appear on any of them
- AND an anonymous direct fetch MUST return `404`

#### Scenario: Public cache headers respect the embargo boundary
- GIVEN a cacheable public surface (sitemap, DCAT document) containing
  publications with a pending embargo in its scope
- WHEN cache validators (`Last-Modified`/`ETag`/max-age) are emitted
- THEN they MUST NOT cause a conditional `304`/cached response to mask the
  embargo moment by more than the surface's documented cache granularity

#### Scenario: Schedule set from the publish dialog
- GIVEN a user opens the publish confirmation dialog (PUB-018) on an `approved` publication
- WHEN they choose "Publish on" and pick a future date-time
- THEN the store action MUST run the `publish` transition with `publicationDate` set to that date-time
- AND the publication list MUST show a "Scheduled" status with the scheduled
  moment

### Requirement: Scheduled depublication via future `depublicatiedatum` (RET-002)
The system MUST support scheduling depublication by setting the object's own
`depublicatiedatum` field to a future date-time, after which the object MUST
disappear from all public surfaces via the same RBAC predicate evaluation — no
bespoke takedown job. The depublish dialog (PUB-018) MUST offer
"Depublish on" alongside immediate depublication.

#### Scenario: Time-limited publication expires automatically
- GIVEN a publication of a temporary traffic measure with
  `depublicatiedatum` set to 2026-09-01
- WHEN public surfaces are requested on 2026-09-02
- THEN the publication MUST NOT appear on any public surface
- AND its internal (authenticated) record MUST remain intact

#### Scenario: Clearing a scheduled depublication
- GIVEN a publication with a future `depublicatiedatum`
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

The category-to-default match itself MUST be evaluated by OpenRegister's
shared decision-table evaluator
(`OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator`), not by app-local
matching code (hydra ADR-065, One Engine). The stored defaults are translated
per catalog into a decision table under the `FIRST` hit policy — one
quoted-literal rule per configured category in configured order, the
catalog-wide fallback as a trailing `-` catch-all rule — so the observable
outcome (which default a publication receives) is identical to the previous
in-app matching. A `no_rule_matched` evaluation MUST leave the publication
unchanged, and an unavailable evaluator MUST be logged and treated as "no
defaults configured", never guessed around with a second matcher.

#### Scenario: Default applied at publication time
- GIVEN catalog "vergunningen" configures category "vergunningen" with
  `{ termMonths: 12, action: "depublish" }`
- WHEN a publication in that category is published without retention fields
- THEN the publication MUST carry `retentionTermMonths: 12`,
  `retentionAction: "depublish"`, and a computed `retentionExpiresAt`

> @e2e exclude Unchanged carried-over RET-004 scenario — this delta changes the
> matcher behind it, not the observable behaviour; the outcome is verified by
> PHPUnit `RetentionServiceTest::testApplyDefaultsFillsEmptyButNeverOverwrites`
> now running through the shared evaluator, and the surface (publication save)
> has no new UI.

#### Scenario: Officer override wins over the default
- GIVEN the same catalog default
- WHEN a publication is published with `retentionTermMonths: 60` already set
- THEN the value 60 MUST be preserved

> @e2e exclude Unchanged carried-over RET-004 scenario; only-fill-empties is a
> server-side rule with no distinct UI surface, verified by PHPUnit
> `RetentionServiceTest::testApplyDefaultsFillsEmptyButNeverOverwrites`.

#### Scenario: Changing a default is not retroactive
- GIVEN existing publications created under the old default of 12 months
- WHEN the admin changes the category default to 24 months
- THEN existing publications MUST keep their stored retention values

> @e2e exclude Unchanged carried-over RET-004 scenario; non-retroactivity is a
> data-at-rest property (stored values are simply never re-stamped), verified
> by PHPUnit `RetentionServiceTest`.

#### Scenario: Category match runs through the shared evaluator
- GIVEN a catalog with defaults for categories "vergunningen" and
  "beschikkingen" plus a `_fallback` row
- WHEN a publication in category "beschikkingen" is stamped at publication
  time
- THEN the resolution MUST be produced by
  `DecisionTableEvaluator::evaluate()` over a `FIRST`-policy table in which
  each category is a quoted-literal input cell and `_fallback` is the last,
  `-` catch-all rule
- AND the "beschikkingen" rule's outputs MUST win over the fallback

> @e2e exclude Internal delegation contract with no HTTP- or UI-observable
> surface of its own (the observable outcome is the unchanged RET-004
> behaviour above); verified by PHPUnit
> `RetentionServiceTest::testApplyDefaultsResolvesSpecificCategoryThroughEvaluator`
> asserting against the real evaluator grammar (or its signature-identical CI
> stub).

#### Scenario: No matching rule leaves the publication untouched
- GIVEN a catalog whose defaults configure only category "vergunningen" and
  no `_fallback`
- WHEN a publication in category "onbekend" is stamped
- THEN the evaluator's `no_rule_matched` outcome MUST leave every retention
  field as the officer left it
- AND no error MUST surface to the publishing flow

> @e2e exclude Server-side exception-mapping contract with no UI surface;
> verified by PHPUnit
> `RetentionServiceTest::testApplyDefaultsWithoutMatchingRuleLeavesPublicationUnchanged`.

#### Scenario: An unavailable evaluator degrades to "no defaults", loudly
- GIVEN OpenRegister's evaluator cannot be resolved from the container
- WHEN a publication is stamped at publication time
- THEN the publication MUST be returned unchanged
- AND a warning MUST be logged naming the unavailable evaluator
- AND no app-local fallback matcher MUST be consulted

> @e2e exclude Availability-guard contract only reachable in bare test/CLI
> contexts (the live path is an OR event listener, so OR is present);
> verified by PHPUnit
> `RetentionServiceTest::testApplyDefaultsLogsWhenEvaluatorUnavailable`.

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
Retention notifications MUST be declared via the canonical
`x-openregister-notifications` dialect. The notifications — "publication
expiring soon", "retention review required", "publication
auto-depublished/archived on expiry" — are declared on the
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

### Requirement: An archive decision hands the publication to the e-depot (REQ-CRR-003)

When an officer decides to archive a publication for permanent keeping, the app
MUST hand the publication and its attachments to OpenRegister's e-Depot
transfer. The app SHALL store the transfer reference and its status on the
publication. A failed transfer MUST leave the publication archived in the app
and MUST show why it failed.

#### Scenario: A publication goes to the e-depot

- **GIVEN** a publication with retention action `archive` and an e-Depot configured in OpenRegister
- **WHEN** the officer records the archive decision
- **THEN** a transfer for the publication and its attachments is started in OpenRegister
- **AND** the publication shows the transfer reference with status `requested`

#### Scenario: The e-depot refuses

- **GIVEN** the same publication and an e-Depot that refuses the transfer
- **WHEN** the transfer ends
- **THEN** the publication stays archived in the app
- **AND** it shows status `failed` with the e-depot's reason
### Requirement: An administrator marks a subject as an archive hotspot (REQ-THA-001)

The theme schema SHALL gain `archiveHotspot` (boolean, default `false`), `archiveHotspotReason` (string) and `archiveHotspotSince` (date-time, read-only). A pre-save listener on the theme schema SHALL refuse a change of `archiveHotspot` by a user who is not an administrator, and a change without a non-empty `archiveHotspotReason`. On marking, it SHALL set `archiveHotspotSince` to now and set the theme object's OpenRegister retention `archiefnominatie` to `blijvend_bewaren` on the entity before save. On unmarking, it SHALL restore the appraisal the theme had before marking (kept in the retention as `hotspotPreviousAppraisal`) or remove it. Each change SHALL write an audit entry `theme.archiveHotspot.marked` or `theme.archiveHotspot.unmarked` with the user and the reason.

#### Scenario: An administrator marks a subject

- **GIVEN** a subject "Herindeling gemeente" and an administrator
- **WHEN** the administrator turns on Archive hotspot with the reason "Aangewezen in archiefhotspotlijst 2026" and saves
- **THEN** the subject shows "Archive hotspot since <today>" with the reason
- **AND** its audit trail holds `theme.archiveHotspot.marked` with the administrator and the reason

#### Scenario: A non-administrator cannot mark
<!-- @e2e exclude Pre-save refusal; proven by ThemeHotspotListenerTest::testANonAdministratorCannotMarkAHotspot, built on the real ObjectUpdatingEvent. -->

- **GIVEN** an editor who is not an administrator
- **WHEN** the editor saves a subject with `archiveHotspot` true
- **THEN** the save is refused and the subject is unchanged

#### Scenario: The marking reaches OpenRegister's appraisal
<!-- @e2e exclude Stored retention metadata; proven by ThemeHotspotListenerTest::testMarkingSetsTheThemesAppraisalToRetainPermanently, plus the live read-back in tasks 1.3. -->

- **GIVEN** a subject without an appraisal
- **WHEN** an administrator marks it with a reason
- **THEN** the subject object's `@self.retention.archiefnominatie` reads `blijvend_bewaren`

### Requirement: Every publication under a hotspot is kept from destruction (REQ-THA-002)

The publication schema SHALL declare `x-openregister-retention: {inheritAppraisalFrom: ["themes"]}`, merged with any retention configuration it already carries, with its version bumped. A publication under a hotspot SHALL then be held back by OpenRegister's destruction check whether it was filed before or after the marking, with no write to the publication.

#### Scenario: A publication filed before the marking is kept
<!-- @e2e exclude OpenRegister background destruction run; proven by ThemeHotspotDestructionTest::testAPublicationFiledBeforeTheMarkingIsNotEligible, which runs OpenRegister's RetentionService::destructionRefusal() over the shipped publication schema when the inheritance exists, and by the live check in tasks 2.2. -->

- **GIVEN** a publication with appraisal `vernietigen` and a past destruction date, filed last year under subject "Herindeling gemeente"
- **WHEN** the subject is marked a hotspot and OpenRegister's destruction check runs
- **THEN** the publication is not on the destruction list
- **AND** the run names "Herindeling gemeente" as the reason

### Requirement: OpenCatalogi's own retention takes no action under a hotspot (REQ-THA-003)

`RetentionService::evaluate()` SHALL, before acting on a publication's `retentionAction`, resolve its `themes`. When any is an archive hotspot, it SHALL take no action (no depublish, no archive transition), SHALL count the publication as `heldByHotspot`, and SHALL record the subject in the report row. When the themes cannot be resolved it SHALL take no action and report the publication as `heldUnresolved`. The retention report (`buildReport()`) and the publication page SHALL show "Kept permanently: archive hotspot <subject>".

#### Scenario: OpenCatalogi's own retention leaves it alone
<!-- @e2e exclude Background evaluation; proven by RetentionServiceTest::testAPublicationUnderAHotspotIsNotDepublished, which fails on today's code because evaluate() depublishes it. -->

- **GIVEN** a publication with `retentionAction` `depublish` and a passed `retentionExpiresAt`, under a hotspot subject
- **WHEN** `RetentionService::evaluate()` runs
- **THEN** the publication keeps no `depublicationDate`
- **AND** the counts hold `heldByHotspot` 1

#### Scenario: Unresolvable subjects hold the publication
<!-- @e2e exclude Fail-closed path; proven by RetentionServiceTest::testUnresolvableThemesHoldThePublication. -->

- **GIVEN** a publication with `retentionAction` `archive`, past its term, whose theme lookup throws
- **WHEN** the evaluation runs
- **THEN** no transition runs and the publication is reported as `heldUnresolved`

#### Scenario: The officer sees why

- **GIVEN** a publication under a hotspot
- **WHEN** an editor opens it
- **THEN** the page shows "Kept permanently: archive hotspot Herindeling gemeente"

