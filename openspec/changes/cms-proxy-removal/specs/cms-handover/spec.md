# cms-handover Delta: cms-proxy-removal

**Status**: blocked — see the preconditions in the proposal
**Scope**: opencatalogi
**OpenSpec changes**:

- [cms-proxy-removal](../../)

## Purpose

Completes the handover begun in `cms-handover` by removing the deprecated
menu, page and glossary read proxies. Related: ADR-086 §3.

## REMOVED Requirements

### Requirement: The read path MUST stay behaviour-identical while deprecated

**Reason**: the deprecation window has elapsed and the proxies are removed.
The requirement described a transition, not an end state.

**Migration**: consumers read Portaliq's `/api/content/*` endpoints. The
response shapes were held identical throughout the window precisely so this
step needs no consumer change beyond the base URL.

## ADDED Requirements

### Requirement: The proxied endpoints MUST be gone, not silently broken

The three controllers, their route entries, their tests and their spec
coverage SHALL be removed together. A request to a former endpoint SHALL
return 404, never a 500.

#### Scenario: A former endpoint returns not-found

- **GIVEN** a request to a removed menu, page or glossary endpoint
- **THEN** it returns 404
- **AND** not a 500 — a route left pointing at a deleted controller is the
  usual way this half-lands

#### Scenario: Nothing references the removed controllers

- **GIVEN** the change is complete
- **WHEN** the app is searched for the controller names
- **THEN** no route, test, spec or manifest entry names them

### Requirement: Removal MUST be evidenced, not assumed

The change SHALL record the evidence that no consumer still calls the
endpoints.

#### Scenario: The log check is positive evidence, not an empty grep

- **GIVEN** the access-log check over a full release cycle
- **THEN** the record states that the endpoints were instrumented and observed
  receiving no calls
- **AND** an unlogged endpoint is treated as unknown, not as unused — the two
  produce the same empty result and mean opposite things
