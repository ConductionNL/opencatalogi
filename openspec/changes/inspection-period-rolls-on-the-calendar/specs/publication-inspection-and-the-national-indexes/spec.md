---
status: proposed
---

# Publication inspection and the national indexes

## ADDED Requirements

### Requirement: An inspection period ends on a working day (REQ-IPR-001)

This extends REQ-PIN-103 of the open change `publication-inspection-and-the-national-indexes` (its end is "computed from the statutory term"): that end SHALL be the rolled one.

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

`TermRoll` SHALL offer `roll(DateTimeInterface $at, ?string $calendarSlug = null, ?string $organisation = null): array` returning `{at, unrolledAt, rolledBy}`. A listener on OpenRegister's `ObjectCreatingEvent` and `ObjectUpdatingEvent` for the `inspection` and `commentPeriod` schemas SHALL, when `endDate` is set or changed by the save, roll it through `roll()` and write the rolled `endDate`, `unrolledEndDate` and `rolledBy` through `setModifiedData()`. When the engine cannot be reached it SHALL stop the event with the reason, so the save is refused.

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
