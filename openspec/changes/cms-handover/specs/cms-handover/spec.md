# cms-handover Delta: cms-handover

**Status**: in-progress
**Scope**: opencatalogi
**OpenSpec changes**:

- [cms-handover](../../)

## Purpose

Hands the website CMS — menus, pages and the begrippenlijst — from OpenCatalogi
to Portaliq, leaving a deprecated read path for one release. Implements the
OpenCatalogi side of ADR-086 §3. Related: the cross-app delta
`hydra/openspec/changes/portaliq-phase-two/specs/portaliq-cms/spec.md`.

## ADDED Requirements

### Requirement: OpenCatalogi MUST NOT retain a second editing surface

The menu, page and glossary CRUD UI SHALL be removed from OpenCatalogi. An
administrator SHALL be directed to Portaliq.

#### Scenario: There is exactly one place to edit a menu

- **GIVEN** the handover is complete
- **WHEN** the fleet is searched for a menu-editing surface
- **THEN** only Portaliq's is present

#### Scenario: An admin arriving at the old location is redirected

- **GIVEN** an administrator opening the former CMS location in OpenCatalogi
- **THEN** they are told where the capability now lives and can reach it

### Requirement: The read path MUST stay behaviour-identical while deprecated

`MenusController`, `PagesController` and `GlossaryController` SHALL delegate to
Portaliq's content API and SHALL return responses identical to those they
returned before the handover. They SHALL be marked deprecated.

#### Scenario: An existing consumer sees no change

- **GIVEN** a response recorded from the pre-handover endpoint
- **WHEN** the same request is made through the proxy
- **THEN** the response matches that recording
- **AND** the comparison is against the recording, not against what the new
  implementation considers correct

#### Scenario: Deprecation is announced in the response

- **GIVEN** a request to a proxied endpoint
- **THEN** the response is marked deprecated

#### Scenario: Portaliq being unavailable is reported, not faked

- **GIVEN** Portaliq is not installed or not reachable
- **WHEN** a proxied endpoint is called
- **THEN** it reports the failure rather than returning an empty success

### Requirement: Remaining glossary consumers MUST be identified before removal

Every OpenCatalogi reference to the glossary outside the CMS UI SHALL be
enumerated and either migrated or explicitly retained.

#### Scenario: Non-CMS consumers are accounted for

- **GIVEN** the glossary references in the manifest config listener and the
  settings service
- **WHEN** the handover completes
- **THEN** each is either migrated to Portaliq's API or documented as retained
- **AND** none is discovered after deletion

### Requirement: Proxy removal MUST be scheduled as its own change

The removal of the deprecated controllers SHALL be a separate, written change,
not a follow-up intention.

#### Scenario: The removal is in the backlog on day one

- **GIVEN** this change is merged
- **WHEN** the backlog is inspected
- **THEN** the removal change exists, naming the controllers and the release
  after which they go

#### Scenario: Catalogue capabilities are untouched

- **GIVEN** the handover is complete
- **WHEN** catalogues, publications, themes and directory federation are
  exercised
- **THEN** all behave as before — only the website CMS moved
