---
status: proposed
---

# Published service and case type catalogue

## ADDED Requirements

### Requirement: A public catalogue lists everything that can be requested (REQ-PSC-101)

The app MUST publish a catalogue of the services a resident or a company
can request. Each entry MUST name what the requester gets, what it costs
and how long it takes, and MUST carry a form binding of case type,
audience and form name. The catalogue MUST be readable without an account
and MUST be searchable through the existing search surface. An entry whose
form cannot be resolved MUST be shown as unavailable rather than hidden.

Evidence: three driven passers (glpi, xxllnc-zaken, znuny). Matrix hole,
and number 8 of the sweep's twenty-five loudest.

#### Scenario: A resident finds what they can request

- **GIVEN** a catalogue with published entries
- **WHEN** an anonymous reader opens it
- **THEN** the entries are listed with their cost and their duration.

#### Scenario: An entry carries the form that starts it

- **GIVEN** an entry bound to a case type and an audience
- **WHEN** the entry is read through the API
- **THEN** the binding is in the response for the portal to resolve.

#### Scenario: An entry with no resolvable form is not hidden

- **GIVEN** an entry whose bound form is unpublished
- **WHEN** the catalogue is read
- **THEN** the entry is listed as unavailable
- **AND** the administrator's list names it.

### Requirement: A published case type links to its form and its API description (REQ-PSC-102)

A case type published in the catalogue MUST carry a link to the form that
starts it and a link to the API description of the interface behind it.

Evidence: one driven passer (xxllnc-zaken).

#### Scenario: An integrator finds the interface from the type

- **GIVEN** a published case type
- **WHEN** it is opened
- **THEN** its form and its API description are both linked from it.

### Requirement: Case types are imported from a published external catalogue and resynchronised (REQ-PSC-103)

The app MUST be able to read an external case type catalogue as a harvest
source and import definitions from it. Every imported definition MUST
record the source, the version and the moment of the import, and MUST show
that date. A resynchronisation MUST show the difference against the source
before anything is applied, and MUST flag a property that was changed
locally. Nothing MUST be applied without that step.

Evidence: one driven passer (dimpact-zac) and one documented (atabix).

#### Scenario: A definition arrives from the national catalogue

- **GIVEN** a registered external catalogue
- **WHEN** an administrator imports a definition
- **THEN** the definition exists locally with its source, version and import date.

#### Scenario: A resync shows what changed before it applies

- **GIVEN** an imported definition the source has since changed
- **WHEN** the administrator resynchronises it
- **THEN** the difference is shown and nothing is applied until it is accepted.

#### Scenario: A locally changed property is flagged

- **GIVEN** an imported definition with a property changed locally
- **WHEN** a resynchronisation would overwrite it
- **THEN** that property is flagged in the difference.

### Requirement: A reader says whether an article helped, and the count is visible (REQ-PSC-104)

A knowledge article MUST be a published record in a catalogue, searchable
and public by the rules that govern publications. A reader MUST be able to
record whether it helped, once per reader, and the count MUST be visible
on the article.

Evidence: one driven passer (frappe-helpdesk) and one documented
(youtrack).

#### Scenario: The verdict is counted and shown

- **GIVEN** an article with verdicts recorded
- **WHEN** a reader opens it
- **THEN** the count is shown on it.

#### Scenario: One reader counts once

- **GIVEN** a reader who already recorded a verdict on an article
- **WHEN** they record another
- **THEN** the count does not change.

### Requirement: The answer on a case becomes an article in one action (REQ-PSC-105)

An answer given on a case MUST be extractable into a draft knowledge
article in one action. The draft MUST link back to the case it came from,
MUST NOT be public until it is published, and the case MUST be unchanged
by the extraction.

Evidence: one driven passer (request-tracker).

#### Scenario: The extraction produces a draft, not a publication

- **GIVEN** an answer on a case
- **WHEN** it is extracted
- **THEN** a draft article exists, linked to the case, and no anonymous reader can read it.

#### Scenario: The case keeps its answer

- **GIVEN** the same extraction
- **WHEN** the case is read afterwards
- **THEN** its answer is unchanged.
