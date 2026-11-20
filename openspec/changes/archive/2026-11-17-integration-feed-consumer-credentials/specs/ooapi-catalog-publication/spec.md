---
status: proposed
---

# Feed consumer credentials

## ADDED Requirements

### Requirement: An administrator issues a key to a consumer for one catalogue (REQ-FCC-001)

An administrator SHALL be able to add a consumer to a catalogue and generate a key for it. The key SHALL be shown once, in the answer that creates it, and SHALL NOT be retrievable afterwards. The stored consumer SHALL hold only a verifier of the key.

#### Scenario: An administrator onboards an education partner

- **GIVEN** an administrator on the catalogue page of `hva-onderwijs`
- **WHEN** they add the consumer "Studiekeuzeportaal" and press Generate key
- **THEN** the key is shown once with a copy button and a warning that it cannot be shown again
- **AND** reading the consumer object through the OpenRegister API afterwards shows no key

### Requirement: A key reads only its catalogue's protected feeds (REQ-FCC-002)

A request with `Authorization: Bearer` and a valid, unrevoked, unexpired key SHALL be served as the consumer, under the same schema authorization as any other reader. It SHALL be refused for any other catalogue. A wrong, revoked or expired key SHALL get 401.

#### Scenario: The partner reads its courses

- **GIVEN** a consumer with a valid key for `hva-onderwijs`
- **WHEN** the partner calls `GET /api/catalogs/hva-onderwijs/ooapi/v5/courses` with that key
- **THEN** the answer is 200 with the course list

#### Scenario: The same key on another catalogue

- **GIVEN** the same key
- **WHEN** the partner calls the OOAPI feed of catalogue `uva-onderwijs`
- **THEN** the answer is 403 and no course is returned

#### Scenario: A revoked key

- **GIVEN** a key an administrator revoked this morning
- **WHEN** it is used
- **THEN** the answer is 401

### Requirement: Keys rotate without an outage (REQ-FCC-003)

A consumer SHALL be able to hold two active keys. The catalogue page SHALL show for each key when it was created, when it expires and when it was last used, so an administrator can revoke the old key once the partner uses the new one.

#### Scenario: An administrator rotates a partner's key

- **GIVEN** a consumer with one key in use
- **WHEN** the administrator generates a second key and the partner switches to it
- **THEN** the page shows the new key's last use and no recent use of the old one
- **AND** revoking the old key does not interrupt the partner

### Requirement: Account-based access keeps working and is visible (REQ-FCC-004)

Signed-in accounts on the instance-wide consumer list SHALL keep reading the protected feeds as before. The catalogue page SHALL show that list, read-only, and say where it is changed.

#### Scenario: An existing integration with an app password

- **GIVEN** an account on the instance-wide consumer list reading with its app password
- **WHEN** this change is deployed
- **THEN** its OOAPI requests still answer 200
