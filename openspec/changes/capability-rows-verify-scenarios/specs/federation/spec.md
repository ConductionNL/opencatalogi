# federation

## ADDED Requirements

### Requirement: The installation publishes a directory of its own catalogues (REQ-CVS-005)

`GET /api/directory` MUST answer without sign-in with one entry per catalogue of
this installation that a reader may see, each carrying the catalogue's title,
summary, organisation and the address of its publications, next to the
listings it learned from other directories. Another installation that adds this
address MUST get these catalogues as listings.

#### Scenario: A neighbour reads our catalogues
- **GIVEN** an installation with public catalogues "Woo-verzoeken" and "Raadsstukken"
- **WHEN** an anonymous client requests `GET /index.php/apps/opencatalogi/api/directory`
- **THEN** the answer holds an entry for each, with its title and the address of its publications

#### Scenario: Another installation adds our directory
- **GIVEN** the same installation
- **WHEN** a second installation adds its directory address
- **THEN** "Woo-verzoeken" and "Raadsstukken" appear as listings on the second installation
