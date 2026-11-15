# publication-retention-lifecycle

## ADDED Requirements

### Requirement: An archive decision hands the publication to the e-depot (REQ-CRR-003)

When an officer decides to archive a publication for permanent keeping, the app
MUST hand the publication and its attachments to OpenRegister's e-Depot
transfer. The app SHALL store the transfer reference and its status on the
publication. A failed transfer MUST leave the publication archived in the app
and MUST show why it failed.

#### Scenario: A publication goes to the e-depot

- **GIVEN** a publication with retention action `archive` and an e-Depot configured in OpenRegister
- **WHEN** the officer records the archive decision
- **THEN** a transfer for the publication and its attachments is started in OpenRegister
- **AND** the publication shows the transfer reference with status `requested`

#### Scenario: The e-depot refuses

- **GIVEN** the same publication and an e-Depot that refuses the transfer
- **WHEN** the transfer ends
- **THEN** the publication stays archived in the app
- **AND** it shows status `failed` with the e-depot's reason
