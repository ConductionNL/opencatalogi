---
status: in-progress
---

# Woo request intake and the statutory term

## Purpose

@e2e exclude backend and API spec. Every scenario is an API call plus a term read on the consumed OpenRegister term engine; the citizen-facing form that would be browser-observable is portaliq's and is out of scope here.

This stack used to assume a Woo request already existed somewhere else. A disclosure batch carried a `caseReference`, and that reference was a foreign key to a number nothing here minted, so there was no request for a statutory term to attach to.

The request is minted here. The term is armed on OpenRegister's term engine, which already rolls a deadline off a non-working day, bounds an extension, holds elapsed time across a suspension, and recomputes a stored deadline when the working calendar changes. Nothing in this app computes a date.

## Requirements

### Requirement: A Woo request is a record of its own (REQ-WRI-001)
The system MUST store a Woo request as a record carrying the reference the requester quotes, the day it was received, what was asked for, the requester's contact details, and a state.

**Priority:** Must **Status:** Implemented

#### Scenario: a request arrives and is minted
- GIVEN a request that says what information is asked for
- WHEN it is received
- THEN the system MUST store it with a minted reference, the moment of receipt, and the state `received`

#### Scenario: a request that asks for nothing is refused
- GIVEN a request with no requested information
- WHEN it is received
- THEN the system MUST refuse it rather than storing a request a term would be counted against for nothing

### Requirement: A statutory term is armed when a request is received (REQ-WRI-002)
The system MUST arm a statutory term of four weeks from receipt on the consumed term engine, bounded at one extension, and MUST NOT compute the date itself.

**Priority:** Must **Status:** Implemented

#### Scenario: the term is armed on the engine
- GIVEN a request that has just been stored
- WHEN its term is armed
- THEN the configuration handed to the engine MUST declare 28 calendar days from the receipt moment, a legal effect of `wettelijk`, a roll to the next working day, and an extension bound of one

#### Scenario: the engine is unavailable
- GIVEN the term engine cannot be reached
- WHEN a request is received
- THEN the request MUST still be stored, and the response MUST say the term was not armed rather than quoting a due date nobody computed

### Requirement: The term is extended once and a second extension is refused (REQ-WRI-003)
The system MUST allow one extension of two weeks and MUST refuse a second, with a status code that survives the response pipeline.

**Priority:** Must **Status:** Implemented

#### Scenario: the first extension is granted
- GIVEN a request with a running term and no extension yet
- WHEN an extension is asked for with a rationale
- THEN the term MUST be extended by 14 days and the extension count MUST read 1

#### Scenario: the second extension is refused
- GIVEN a request whose term has already been extended once
- WHEN a second extension is asked for
- THEN the system MUST answer 409 Conflict and MUST NOT extend the term

#### Scenario: an extension without a rationale is refused
- GIVEN a request with a running term
- WHEN an extension is asked for with no rationale
- THEN the system MUST answer 400 Bad Request

### Requirement: The term pauses while clarification is awaited (REQ-WRI-004)
The system MUST suspend the term while clarification is awaited and resume it when the clarification arrives, using the engine's suspended state so the elapsed time is held rather than the moment.

**Priority:** Must **Status:** Implemented

#### Scenario: the term is suspended
- GIVEN a request with a running term
- WHEN clarification is asked for with a reason
- THEN the term MUST read `suspended`, the request MUST read `awaiting_clarification`, and the term MUST report no due moment while it is held

#### Scenario: the term resumes
- GIVEN a request whose term is suspended
- WHEN the clarification arrives
- THEN the term MUST read `armed` again and MUST report a due moment projected from the unconsumed remainder

#### Scenario: a suspension without a reason is refused
- GIVEN a request with a running term
- WHEN a suspension is asked for with no reason
- THEN the system MUST answer 400 Bad Request

### Requirement: The requester is told the reference and the due date at intake (REQ-WRI-005)
The system MUST answer an intake with the reference and the due date, in the same response.

**Priority:** Must **Status:** Implemented

#### Scenario: the receipt carries the clock
- GIVEN a request whose term was armed
- WHEN the intake answers
- THEN the response MUST carry the reference, the moment of receipt, the due date, the term in days, and the legal ground

#### Scenario: the receipt is honest when no term was armed
- GIVEN a request whose term could not be armed
- WHEN the receipt is read
- THEN it MUST report that the term was not armed rather than leaving the due date quietly empty

### Requirement: Terms met and missed are reported (REQ-WRI-006)
The system MUST report, over the stored requests, which terms were met, which were missed, which are still running and which are suspended.

**Priority:** Must **Status:** Implemented

#### Scenario: a decision inside the term is met
- GIVEN a request decided on or before its due date
- WHEN the report is read
- THEN that request MUST count as met

#### Scenario: an overdue undecided request is missed
- GIVEN an undecided request whose due date has passed
- WHEN the report is read
- THEN that request MUST count as missed rather than as still running

#### Scenario: nothing decided yet reports no share
- GIVEN no request has been decided
- WHEN the report is read
- THEN the met share MUST be absent rather than reading as full compliance

### Requirement: A request may produce a batch and a batch works without a request (REQ-WRI-007)
The system MUST let a request record the disclosure batch it produced, and MUST keep a batch working when no request exists.

**Priority:** Must **Status:** Implemented

#### Scenario: a batch is attached to a request
- GIVEN a request in the state `received`
- WHEN a batch is attached
- THEN the request MUST record the batch and move to `in_progress`

#### Scenario: a batch stands on its own
- GIVEN a batch created with a case reference and no request
- WHEN it is read
- THEN it MUST behave exactly as before, with no request required

### Requirement: A Woo request delivered by the portal arms its term (REQ-WRI-008)

A Woo request portaliq delivers MUST go through the same intake as the API: opencatalogi mints the reference and arms the statutory term. The answer MUST say whether the term runs, and MUST NOT carry a due date when it does not.

#### Scenario: the portal delivers a request and the term is armed
- GIVEN a citizen sent a Woo request through a portal form bound to `wooRequest`
- WHEN portaliq calls `receiveWooRequest()` with the answers and the moment they were sent
- THEN a request is stored with a minted `WOO-` reference, received at that moment
- AND its term is armed and stored on it
- AND the answer is `armed` with the due date
- @e2e exclude backend call between two apps from a background job; pinned by `WooRequestIntakeTest`

#### Scenario: the term cannot be armed
- GIVEN the term engine is unavailable or refuses
- WHEN portaliq delivers a request
- THEN the request is stored, and the answer is `not-armed` with no due date and the reason
- @e2e exclude backend call between two apps from a background job; pinned by `WooRequestIntakeTest`
