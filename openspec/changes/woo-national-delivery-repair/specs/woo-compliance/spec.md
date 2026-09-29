---
status: proposed
---

# National delivery repair

## ADDED Requirements

### Requirement: A hand-over to a national channel calls the gateway with a real source (REQ-WND-001)

The hand-over to any national channel SHALL resolve the channel to an integriq source object named in the `channel_sources` setting and call the gateway with that object. A channel with no source configured SHALL fail before any call, with a message that names the channel and the setting.

#### Scenario: A configured channel is reached

- **GIVEN** the Woo index channel has a source set in the Woo settings
- **WHEN** an admin requests registration with the Woo index
- **THEN** the gateway is called with that source object and the stored answer is the platform's reply

#### Scenario: A channel with no source

- **GIVEN** the PLOOI channel has no source set
- **WHEN** a delivery to PLOOI is attempted
- **THEN** it fails with a message naming the PLOOI channel and the `channel_sources` setting
- **AND** nothing is recorded as delivered

### Requirement: Official notices travel by reference through the publication gateway (REQ-WND-002)

An official notice for the national publication platform SHALL be sent by dispatching a gateway delivery request for the `publicatie` gateway that carries a document reference and a publication instruction, and never the document itself. A request that integriq does not take SHALL be reported as unreachable and never as acknowledged.

#### Scenario: A notice is announced

- **GIVEN** a decision with a publication type and an effective date
- **WHEN** an admin announces it
- **THEN** a `publicatie` delivery request is dispatched with the reference and the instruction
- **AND** the response shows the delivery returned by integriq

#### Scenario: integriq is not installed

- **GIVEN** no integriq app answers the request
- **WHEN** an admin announces a decision
- **THEN** the response says the channel could not be reached
- **AND** the notice is not marked as sent

### Requirement: A publication that turns public is delivered to PLOOI when the catalogue asks for it (REQ-WND-003)

When a publication in a catalogue with `plooiDelivery` on becomes public, the app SHALL post its DiWoo metadata and document links to the PLOOI source and store `plooiStatus`, `plooiDeliveredAt` and `plooiIdentifier` on the publication. A failed delivery SHALL store `plooiStatus` as failed with the reason, and SHALL NOT block publishing.

#### Scenario: A publication is published

- **GIVEN** a catalogue with `plooiDelivery` on and a PLOOI source set
- **WHEN** an editor publishes a publication in it
- **THEN** the publication shows the PLOOI delivery status and the identifier returned by the platform

#### Scenario: PLOOI refuses

- **GIVEN** the PLOOI source answers with an error
- **WHEN** an editor publishes a publication
- **THEN** the publication is public
- **AND** its `plooiStatus` is failed with the platform's reason

### Requirement: The announce endpoint has a screen (REQ-WND-004)

The publication page SHALL offer an Announce action for an admin that calls `POST /api/publications/announce` and shows the delivery result per channel.

#### Scenario: An admin announces a decision

- **GIVEN** an admin on the page of a publication that is a decision
- **WHEN** the admin chooses Announce
- **THEN** the page lists each channel with its delivery result
