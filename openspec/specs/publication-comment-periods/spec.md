---
status: in-progress
---

# Publication comment periods

## Purpose

@e2e exclude backend and API spec. Every scenario is an API call plus a date computed on the consumed OpenRegister term engine; the portal screens that render the three states are portaliq's and are out of scope here.

Terinzagelegging: the organisation puts a draft decision out for public comment, for a bounded statutory period, with a named legal remedy.

This is a sibling of the inspection, not a longer inspection. An inspection is an unguessable link to a set of documents chosen per case, checked at the read, for a named party. A comment period is public, announced on the official announcement platform, carries a legal remedy, and its close can withdraw the publication. Folding the second into the first would force a token onto a public thing and give `endDate` two meanings.

## Requirements

### Requirement: A publication can carry one bounded public comment period (REQ-PCP-001)
The system MUST store a comment period on a publication, with a start that falls before its end, a term in days, and the identity of whoever opened it.

**Priority:** Must **Status:** Implemented

#### Scenario: a period is opened
- GIVEN a publication and a term of six weeks
- WHEN a comment period is opened on it
- THEN the system MUST store the period with its start, its computed end, its term and its remedy

#### Scenario: a period of no days is refused
- GIVEN a term of zero days
- WHEN a comment period is opened
- THEN the system MUST refuse it rather than storing a period that is closed from the moment it opens

### Requirement: The period ends on a working day (REQ-PCP-002)
The system MUST compute the end date through the consumed term engine, rolled to the next working day per the Algemene termijnenwet, and MUST refuse rather than compute the date itself when the engine is unavailable.

**Priority:** Must **Status:** Implemented

#### Scenario: an end on a non-working day rolls
- GIVEN a term whose last day falls on a Sunday or a public holiday
- WHEN the period is opened
- THEN the end date MUST move to the next working day, and the period MUST record where it landed before the roll and why it moved

#### Scenario: the engine is unavailable
- GIVEN the term engine cannot be reached
- WHEN a period is opened
- THEN the system MUST refuse, and MUST NOT compute the roll itself

### Requirement: The reaction form follows from the legal remedy (REQ-PCP-003)
The system MUST derive the reaction form from the chosen remedy, which is one of a closed two-member list, and MUST refuse any other remedy.

**Priority:** Must **Status:** Implemented

#### Scenario: each remedy has its own form
- GIVEN a period whose remedy is `zienswijze`
- WHEN its reaction form is derived
- THEN the form MUST be the zienswijze form, and a `bezwaar` period MUST get the bezwaar form

#### Scenario: a third remedy is refused
- GIVEN a remedy that is neither `zienswijze` nor `bezwaar`
- WHEN a period is opened
- THEN the system MUST refuse it, because a reader told the wrong remedy loses a right

### Requirement: The period reports one of three states (REQ-PCP-004)
The system MUST report a period as upcoming, open or closed, derived from the clock rather than stored.

**Priority:** Must **Status:** Implemented

#### Scenario: the three states
- GIVEN a period that starts tomorrow, one running now, and one that ended yesterday
- WHEN each is read
- THEN they MUST report `upcoming`, `open` and `closed`

#### Scenario: a closed period offers no form
- GIVEN a closed period
- WHEN it is read
- THEN the reaction form MUST be absent, because a form on a closed period collects a reaction nobody is obliged to read

#### Scenario: an unreadable period refuses
- GIVEN a period whose dates cannot be read
- WHEN its state is asked for
- THEN the system MUST refuse rather than defaulting to open or closed

### Requirement: A closed period can withdraw its publication (REQ-PCP-005)
The system MUST withdraw the publication when a period that asked for it closes, and MUST NOT withdraw one that did not ask or that was already withdrawn.

**Priority:** Must **Status:** Implemented

#### Scenario: a closed period that asked for it is withdrawn
- GIVEN a closed period with automatic withdrawal on and nothing withdrawn yet
- WHEN the daily pass runs
- THEN the publication MUST be withdrawn from every channel it reached, and the period MUST record when

#### Scenario: a period that did not ask is left alone
- GIVEN a closed period with automatic withdrawal off
- WHEN the daily pass runs
- THEN nothing MUST be withdrawn

#### Scenario: a withdrawal happens once
- GIVEN a closed period that was already withdrawn
- WHEN the daily pass runs again
- THEN nothing MUST be withdrawn a second time

### Requirement: The period points at its official announcement (REQ-PCP-006)
The system MUST store an absolute https announcement url on the official announcement platform, and MUST refuse one that is not.

**Priority:** Must **Status:** Implemented

#### Scenario: an announcement is required
- GIVEN a period opened with no announcement
- WHEN it is opened
- THEN the system MUST refuse it, because a period nobody was told about is not a public comment period

#### Scenario: an internal link is refused
- GIVEN an announcement url that is not on the official announcement platform
- WHEN a period is opened
- THEN the system MUST refuse it
