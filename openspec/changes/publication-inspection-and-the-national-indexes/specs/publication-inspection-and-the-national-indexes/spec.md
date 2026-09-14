---
status: proposed
---

# Publication, inspection and the national indexes

## ADDED Requirements

### Requirement: A record type is readable without an account, with the visible parts chosen (REQ-PIN-101)

A publication rule MUST name a record type, the properties an anonymous
reader may read, and the conditions under which a record of that type
becomes public. Records of that type MUST follow the rule without anyone
selecting them. The anonymous permission set MUST be enforced where the
read happens, so a property outside it is absent from the API response
and from the page alike.

Evidence: one driven passer (taiga, `projects/models.py:221-226`) and one
documented (youtrack).

#### Scenario: A new record of a published type is public on arrival

- **GIVEN** a publication rule on a record type
- **WHEN** a new record of that type meets the rule's conditions
- **THEN** an anonymous reader can read it without anyone publishing it by hand.

#### Scenario: A property outside the set is absent from the API

- **GIVEN** a record whose type's anonymous set omits a property
- **WHEN** an anonymous reader reads it through the API
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

### Requirement: Publication runs as a walked process (REQ-PIN-104)

Publishing MUST run as a recorded process with four steps: choosing the
documents, the zienswijze round, the approval, and the channels. Each step
MUST record who completed it and when. A configuration MUST be able to
skip steps, so a municipality that wants one action has one.

Evidence: documented only (visma-circle, Djuma OpenInfo).

#### Scenario: Every step is recorded

- **GIVEN** a publication walked through all four steps
- **WHEN** its history is read
- **THEN** each step names who completed it and when.

#### Scenario: Steps can be configured away

- **GIVEN** a configuration that skips the zienswijze round and the approval
- **WHEN** a publication is made
- **THEN** it completes in one action and the skipped steps are recorded as configured off.

### Requirement: Interested parties are consulted before information about them is published (REQ-PIN-105)

Before a publication that carries information about an interested party,
the app MUST be able to ask that party over a channel that identifies
them, record the answer against the publication, and hold the publication
while an ask is open inside its term.

Evidence: documented only (visma-circle).

#### Scenario: The publication waits for an open ask

- **GIVEN** a publication with an unanswered zienswijze ask inside its term
- **WHEN** someone tries to advance it
- **THEN** it is held and the open ask is named.

#### Scenario: The answer is recorded against the publication

- **GIVEN** an answered ask
- **WHEN** the publication is read
- **THEN** the answer and who gave it are recorded on it.

#### Scenario: An unidentified channel is refused

- **WHEN** a zienswijze ask is sent over a channel that does not identify the recipient
- **THEN** it is refused.

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
effect without a release.

Evidence: documented only (decos-join).

#### Scenario: A collection is added without a release

- **GIVEN** a running instance
- **WHEN** an administrator adds a collection to the published set
- **THEN** its records publish under the stated conditions.

#### Scenario: A condition narrows what publishes

- **GIVEN** a published collection with a condition on a property
- **WHEN** a record fails the condition
- **THEN** it is not published.

### Requirement: A published document carries a verifiable stamp (REQ-PIN-109)

A published document MUST carry a digital stamp over the document and its
publication metadata. The organisation's verification key MUST be
published. A reader MUST be able to verify a document against that key,
and a document that has changed since publication MUST fail the check.

Evidence: documented only (visma-circle).

#### Scenario: A reader verifies the published document

- **GIVEN** a published document with its stamp
- **WHEN** a reader verifies it against the published key
- **THEN** the check passes.

#### Scenario: A changed document fails the check

- **GIVEN** a published document whose bytes were altered
- **WHEN** it is verified
- **THEN** the check fails.

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
and no more.

Evidence: documented only (visma-circle).

#### Scenario: A document is found with its dossier

- **GIVEN** a published document in a dossier
- **WHEN** an anonymous reader searches a word from it
- **THEN** the result names the dossier and links to it.

#### Scenario: Search respects the anonymous set

- **GIVEN** a record with a property outside the anonymous set
- **WHEN** an anonymous reader searches a word that occurs only in that property
- **THEN** the record is not returned.
