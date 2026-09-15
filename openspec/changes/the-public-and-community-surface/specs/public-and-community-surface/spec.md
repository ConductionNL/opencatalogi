---
status: proposed
---

# Public and community surface

## ADDED Requirements

### Requirement: A public status page says what is running (REQ-PCS-101)

The app MUST serve a public status page listing named components with
their current state and a short message. A component's state MUST be set
by an administrator or by an integration, and MUST NOT be inferred from a
health probe. Each component MUST show when its state was last set, and a
state older than the configured staleness period MUST be shown as stale
rather than as current.

#### Scenario: A reader sees what is down

- **GIVEN** a component whose state is set to unavailable with a message
- **WHEN** an anonymous reader opens the status page
- **THEN** the component is listed as unavailable with that message.

#### Scenario: A stale state does not read as green

- **GIVEN** a component whose state was last set beyond the staleness period
- **WHEN** the page is rendered
- **THEN** the component is marked stale and the date it was last set is shown.

#### Scenario: The page probes nothing

- **WHEN** the status page is rendered
- **THEN** no health probe is made to the named components.

### Requirement: A reader subscribes to changes on the status page (REQ-PCS-102)

A reader MUST be able to subscribe to a status page and be told when a
component changes state. The subscription MUST run through the
notification dialect rather than a mailing mechanism of this app. An
anonymous reader's subscription MUST require a confirmation of the
address before anything is sent to it.

#### Scenario: A change reaches a subscriber

- **GIVEN** a confirmed subscription to a status page
- **WHEN** a component changes state
- **THEN** the subscriber is notified once.

#### Scenario: An unconfirmed address receives nothing

- **GIVEN** a subscription request for an address that was never confirmed
- **WHEN** a component changes state
- **THEN** nothing is sent to that address.

### Requirement: An administrator shows a dated banner to every user (REQ-PCS-103)

An instance banner MUST carry a body, a start and end date, a severity
and a dismissable flag. It MUST be shown to every signed-in user between
those dates and to none outside them. A dismissable banner that a user
dismissed MUST NOT be shown to that user again.

#### Scenario: The banner shows inside its period

- **GIVEN** a banner whose period covers today
- **WHEN** any user opens the app
- **THEN** the banner is shown.

#### Scenario: The banner is gone after its period

- **GIVEN** a banner whose end date has passed
- **WHEN** any user opens the app
- **THEN** the banner is not shown.

#### Scenario: A dismissal is remembered

- **GIVEN** a dismissable banner a user dismissed
- **WHEN** that user opens the app again
- **THEN** the banner is not shown to them, and is still shown to others.

### Requirement: A catalogue carries a notice board (REQ-PCS-104)

A catalogue MUST be able to hold dated notices with a title, a body, a
period and an optional link to a publication. A notice MUST NOT enter the
sitemap or the DiWoo feed. Comments on a notice board MUST be off by
default, and a board that enables them MUST name a moderator.

#### Scenario: A notice is published without becoming a publication

- **GIVEN** a notice on a catalogue
- **WHEN** the catalogue's sitemap is generated
- **THEN** the notice is absent from it.

#### Scenario: Comments are off unless someone turns them on

- **GIVEN** a new notice board
- **WHEN** a reader opens a notice
- **THEN** no comment form is offered.

#### Scenario: A board with comments names a moderator

- **GIVEN** a notice board with comments enabled
- **WHEN** the board is saved without a moderator
- **THEN** the save is refused with the reason.

### Requirement: A catalogue's activity is published as a feed (REQ-PCS-105)

A catalogue MUST offer an Atom feed of its published and updated records
and its notices. The feed MUST be readable without an account and MUST
carry only what an anonymous reader may read, checked per entry against
the publication.

#### Scenario: A reader watches without an account

- **GIVEN** a catalogue with two published records
- **WHEN** an anonymous reader fetches the feed
- **THEN** both records are entries in it.

#### Scenario: A draft never reaches the feed

- **GIVEN** a catalogue with one published record and one draft
- **WHEN** the feed is fetched
- **THEN** only the published record is an entry.

### Requirement: A reader who is not staff votes on a published record (REQ-PCS-106)

A published record MUST be able to accept votes from readers who are not
staff. A reader MUST be able to vote once per record. The distribution
MUST be readable, and an individual reader's vote MUST NOT be readable by
another reader. The vote endpoint MUST be throttled.

#### Scenario: The count is readable

- **GIVEN** a published record with voting enabled and three votes cast
- **WHEN** a reader opens it
- **THEN** the distribution is shown.

#### Scenario: One reader, one vote

- **GIVEN** a reader who has already voted on a record
- **WHEN** they vote again
- **THEN** the count does not change.

#### Scenario: Who voted stays private

- **GIVEN** a record with votes
- **WHEN** a reader reads the record and its distribution
- **THEN** no individual voter is identifiable in the response.

### Requirement: A client renders our markup the way we render it (REQ-PCS-107)

The app MUST offer an endpoint that takes markup in our dialect and
returns the HTML the app itself would render. The endpoint MUST have no
side effect, MUST store nothing, and MUST be throttled.

#### Scenario: A client shows what the website shows

- **GIVEN** a body of markup
- **WHEN** it is posted to the render endpoint
- **THEN** the returned HTML is what the app renders for that body.

#### Scenario: Rendering stores nothing

- **WHEN** markup is posted to the render endpoint
- **THEN** no object is created or changed.
