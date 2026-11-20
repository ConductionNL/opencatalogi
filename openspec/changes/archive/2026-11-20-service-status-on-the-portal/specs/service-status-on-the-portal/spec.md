# service-status-on-the-portal

## ADDED Requirements

### Requirement: The portal index carries the service status (SSP-001)

opencatalogi's portal contribution SHALL offer a public index kind
`serviceStatus` with the columns Dienst, Toelichting and Status, and SHALL
answer one item per service status component, in the components' order, with
its state in words. A state other than available MUST say since when it holds.
When the components cannot be read the index MUST be empty.

@e2e exclude Server-to-server contract read by portaliq; ServiceStatusIndexTest covers the items, portaliq's site page has its own test.

#### Scenario: A disruption on the Woo request form

- **GIVEN** the component "Woo-verzoek indienen" in state degraded with the message "Formulieren worden traag geladen." set on 8 October 09:40
- **WHEN** portaliq reads opencatalogi's public index
- **THEN** the item's cells are Dienst "Woo-verzoek indienen", Toelichting "Formulieren worden traag geladen. Sinds 8 oktober, 09:40." and Status "Verstoring"

#### Scenario: The status cannot be read

- **GIVEN** OpenRegister is not reachable
- **WHEN** portaliq reads the public index
- **THEN** it gets no items
