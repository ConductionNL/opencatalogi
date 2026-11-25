---
status: proposed
---

# Publication retention lifecycle

## ADDED Requirements

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
