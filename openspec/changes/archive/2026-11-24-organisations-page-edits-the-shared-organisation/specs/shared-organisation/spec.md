## ADDED Requirements

### Requirement: Staff record the publishing organisation and its identifiers on the shared organisation (REQ-SHO-103)

The Organizations page MUST list OpenRegister's shared organisations (`nc-organisation`), and the organisation's detail page MUST show its name, summary, OIN, TOOI, RSIN and PKI. The organisation's administrator MUST be able to edit those fields there, and the change MUST be saved on the shared organisation, so every catalogue and publication that names it shows the same identifiers. A user who is not its administrator MUST see the fields read-only. The page MUST NOT offer delete.

#### Scenario: an administrator enters the RSIN

- **GIVEN** the organisation "Gemeente Zuiddrecht", administered by the signed-in user, without an RSIN
- **WHEN** they open it from the Organizations page, enter RSIN 002220647 and save
- **THEN** the shared organisation MUST hold RSIN 002220647
- **AND** the catalogue that names this organisation MUST show RSIN 002220647 for its publisher

#### Scenario: another user reads but cannot edit

- **GIVEN** a signed-in user who is not the organisation's administrator
- **WHEN** they open the organisation's detail page
- **THEN** the identifiers MUST be shown
- **AND** no edit action MUST be offered

#### Scenario: the index lists the shared organisations

- **GIVEN** two organisations in OpenRegister
- **WHEN** a user opens the Organizations page
- **THEN** both MUST be listed
- **AND** no delete action MUST be offered
