# publication-inspection-and-the-national-indexes Specification

## Purpose
TBD - created by archiving change publication-inspection-and-the-national-indexes. Update Purpose after archive.

## Requirements

### Requirement: A record type is readable without an account, with the visible parts chosen (REQ-PIN-101)

What an anonymous reader may read MUST be decided by the record's schema in
OpenRegister: its `authorization.read` rules decide which records are public
(a publication is public once its publication date has passed and until its
depublication date), and property-level authorization on the schema decides
which properties an anonymous reader sees. Records MUST follow those rules
without anyone selecting them. The check MUST run where the read happens, on
every public read of this app alike: the catalogue API (`/api/{catalogSlug}`),
the public search, the catalogue feed and the pages that read them. A schema
without read rules is readable by nobody anonymously. (Amended 2026-10-10 to
decision 138, Q-opencatalogi-1: no publication carries a record type, so the
record-type publication rule is not the access check.)

Evidence: one driven passer (taiga, `projects/models.py:221-226`) and one
documented (youtrack).

#### Scenario: A new record of a published type is public on arrival

- **GIVEN** a schema whose read rules admit a record once its publication date has passed
- **WHEN** a new record's publication date passes
- **THEN** an anonymous reader can read it without anyone publishing it by hand.

#### Scenario: A property outside the set is absent from the API

- **GIVEN** a schema whose property-level authorization withholds a property from anonymous readers
- **WHEN** an anonymous reader reads a record through the API
- **THEN** that property is absent from the response.

#### Scenario: A rule is previewed before it is saved

- **GIVEN** a draft publication rule
- **WHEN** it is previewed
- **THEN** the records it would publish and the properties it would expose are shown.

### Requirement: The type declares publication, and the decision type's rules are validated (REQ-PIN-102)

A record type MUST be able to declare that its records are published and
with what publication text. A decision type MUST be able to declare its
publication obligation, and publishing a decision MUST validate the
publication date and the statutory response date computed from it against
that declaration. A decision that fails the validation MUST NOT be
published, and the reason MUST reach the app that asked.

Evidence: two driven passers (xxllnc-zaken for the declaration,
dimpact-zac for the validation).

#### Scenario: The publication text is the type's

- **GIVEN** a record type declaring a publication text
- **WHEN** one of its records is published
- **THEN** that text is published with it.

#### Scenario: A decision without a publication date is refused

- **GIVEN** a decision type that obliges publication
- **WHEN** a decision of that type is published without a publication date
- **THEN** it is refused and the reason names the missing date.

#### Scenario: The response date is computed, not typed

- **GIVEN** a decision type with a statutory response term
- **WHEN** a decision is published with its publication date
- **THEN** the response date is computed from the term and recorded.

### Requirement: Documents go on public inspection for exactly the statutory period (REQ-PIN-103)

An inspection MUST carry the record, the documents chosen for it, a start,
an end computed from the statutory term, and a link. The link MUST be
refused once the window has closed, checked at the read rather than by a
scheduled job. Any record type that declares an inspection term MUST be
able to use it.

Evidence: documented only (rx-mission, `/modules/` Inzien).

#### Scenario: The link works inside the window

- **GIVEN** an inspection whose window is open
- **WHEN** an anonymous reader follows its link
- **THEN** the chosen documents are readable.

#### Scenario: The link stops when the window closes

- **GIVEN** the same inspection after its end date
- **WHEN** the link is followed
- **THEN** access is refused and the end date is stated.

#### Scenario: The inspection set is chosen at publication

- **GIVEN** a record with five documents
- **WHEN** an inspection is opened choosing two of them
- **THEN** only those two are readable through the link.

#### Scenario: A second record type uses the same mechanism

- **GIVEN** a second record type declaring an inspection term
- **WHEN** an inspection is opened on one of its records
- **THEN** it behaves as the first, with that type's term.

### Requirement: Something published in error is depublished with one action (REQ-PIN-106)

Depublication MUST be one action. It MUST record who did it and why, MUST
remove the publication from every channel it reached including the
national ones, and MUST record each channel's acknowledgement. A
withdrawal that has not been acknowledged MUST be shown as outstanding.

Evidence: documented only (visma-circle).

#### Scenario: One action takes it down everywhere

- **GIVEN** a publication that reached the local channel and a national index
- **WHEN** it is depublished
- **THEN** a withdrawal is sent to both and the reason is recorded.

#### Scenario: An unacknowledged withdrawal is not done

- **GIVEN** a withdrawal one channel has not acknowledged
- **WHEN** the publication is read
- **THEN** that channel is shown as outstanding.

### Requirement: Official notices reach the national platform and the local channel (REQ-PIN-107)

The app MUST compose the official notice for the national publication
platform and for the local channel, and MUST hand it to the gateway for
delivery. It MUST register published records with the national Woo index
through the same gateway, and MUST record each destination's answer. The
app MUST NOT implement the transport itself.

Evidence: documented only (mozard for the notice, pinkroccade-izaaksuite
and visma-circle for the index).

#### Scenario: The notice is composed and handed over

- **GIVEN** a decision that must be bekendgemaakt
- **WHEN** it is published
- **THEN** the notice is composed for both channels and handed to the gateway.

#### Scenario: The index's answer is recorded

- **GIVEN** a record registered with the national Woo index
- **WHEN** the index answers
- **THEN** the answer is recorded on the publication.

#### Scenario: The app holds no transport

- **WHEN** a notice is delivered
- **THEN** the delivery is made by the gateway and no national endpoint is called from this app.

### Requirement: Which collections are published, and on what conditions, is configured (REQ-PIN-108)

Which collections publish, and under what conditions, MUST be
configuration rather than code. A change to the configuration MUST take
effect without a release. A collection is published by adding its schema
to a catalogue; its conditions are the schema's read rules in OpenRegister.
(Amended 2026-10-10 to decision 138.)

Evidence: documented only (decos-join).

#### Scenario: A collection is added without a release

- **GIVEN** a running instance
- **WHEN** an administrator adds a schema to a published catalogue
- **THEN** its records publish under that schema's read rules.

#### Scenario: A condition narrows what publishes

- **GIVEN** a published collection whose read rule has a condition on a property
- **WHEN** a record fails the condition
- **THEN** it is not published.

### Requirement: One overview of what must be published, fed from every source (REQ-PIN-110)

The obligation overview MUST read from every application that registers as
a source, and from other case systems over the harvest intake, not only
from this app. It MUST show what must be published, what is, and what is
late.

Evidence: documented only (visma-circle).

#### Scenario: A second application's obligations appear

- **GIVEN** a second application registered as a source
- **WHEN** the overview is read
- **THEN** its obligations are listed beside this app's.

#### Scenario: A late publication is named

- **GIVEN** an obligation whose publication date has passed unpublished
- **WHEN** the overview is read
- **THEN** it is listed as late.

### Requirement: The public searches published information in plain words (REQ-PIN-111)

Published information MUST be searchable in plain words without an
account. A result MUST name the dossier the document belongs to and link
to it. The search MUST run over what the anonymous permission set allows
and no more: the schemas' read rules, evaluated as an anonymous reader for
every caller (`GET /api/search`). (Amended 2026-10-10 to decision 138.)

Evidence: documented only (visma-circle).

#### Scenario: A document is found with its dossier

- **GIVEN** a published document in a dossier
- **WHEN** an anonymous reader searches a word from it
- **THEN** the result names the dossier and links to it.

#### Scenario: Search respects the anonymous set

- **GIVEN** a record its schema's read rules do not admit for an anonymous reader
- **WHEN** an anonymous reader searches a word that occurs in it
- **THEN** the record is not returned.

### Requirement: An inspection period ends on a working day (REQ-IPR-001)

This extends REQ-PIN-103 (its end is "computed from the statutory term"): that end SHALL be the rolled one.

`InspectionService::open()` SHALL compute `endDate` with `TermRoll::endDate(start, termDays)`, in calendar days, rolled to the next working day per the Algemene termijnenwet, article 1 paragraph 1. When the roll moved the date it SHALL store `unrolledEndDate` and `rolledBy`. When the term engine cannot be reached it SHALL throw `TermRollUnavailableException` and the inspection SHALL NOT be opened. `isOpen()` and `resolveLink()` SHALL read the rolled `endDate`.

#### Scenario: An inspection ending on a Sunday ends on Monday
<!-- @e2e exclude Statutory date computation; proven by InspectionServiceTest::testAnEndOnASundayRollsToMonday, which fails on today's code because open() adds the days without a roll. -->

- **GIVEN** a record type with an inspection term of 42 days and an inspection opened on Sunday 1 November 2026, so that the term's last day is Sunday 13 December 2026
- **WHEN** the inspection is opened
- **THEN** its `endDate` is Monday 14 December 2026, its `unrolledEndDate` is Sunday 13 December 2026, and `rolledBy` names the weekend
- **AND** the link still works on Monday 14 December

#### Scenario: An end on a public holiday rolls past it
<!-- @e2e exclude Statutory date computation; proven by InspectionServiceTest::testAnEndOnChristmasRollsPastBothHolidays. -->

- **GIVEN** an inspection whose term's last day is Friday 25 December 2026 (Christmas Day, Atw art. 3)
- **WHEN** it is opened
- **THEN** its `endDate` is Monday 28 December 2026

#### Scenario: The engine is down
<!-- @e2e exclude Fail-closed path; proven by InspectionServiceTest::testAnUnreachableEngineRefusesToOpen. -->

- **GIVEN** a term engine that cannot be reached
- **WHEN** an officer opens an inspection
- **THEN** it is refused with the reason and nothing is stored

### Requirement: An end date written through the API is rolled (REQ-IPR-002)

`TermRoll` SHALL offer `roll(DateTimeInterface $date, ?string $calendarSlug = null, ?string $organisation = null): array` returning `{at, unrolledAt, rolledBy}`. A listener on OpenRegister's `ObjectCreatingEvent` and `ObjectUpdatingEvent` for the `inspection` and `commentPeriod` schemas SHALL, when `endDate` is set or changed by the save, roll it through `roll()` and write the rolled `endDate`, `unrolledEndDate` and `rolledBy` through `setModifiedData()`. When the engine cannot be reached it SHALL stop the event with the reason, so the save is refused.

#### Scenario: An end date written through the API is rolled
<!-- @e2e exclude API save contract; proven by TermRollListenerTest::testAnApiEndDateOnASaturdayIsRolledToMonday, built on the real ObjectUpdatingEvent, which fails on today's code because nothing rolls an API date. -->

- **GIVEN** a comment period
- **WHEN** an integrator saves it through OpenRegister's object API with `endDate` Saturday 12 December 2026
- **THEN** the stored `endDate` is Monday 14 December 2026 and `unrolledEndDate` is Saturday 12 December 2026

#### Scenario: The engine is down during an API write
<!-- @e2e exclude Fail-closed path; proven by TermRollListenerTest::testAnUnreachableEngineRefusesTheSave. -->

- **GIVEN** a term engine that cannot be reached
- **WHEN** an integrator writes an `endDate`
- **THEN** the save is refused and the stored date is unchanged
