## ADDED Requirements

### Requirement: A notice links to the publication page of the site
The public link to a publication, in a saved-search notice, in a resident's dossier and on a shared dossier, MUST be
the publication page of portaliq's site, `/index.php/apps/portaliq/site?route=/publicatie/<id>`, made absolute with
`IURLGenerator::getAbsoluteURL()`, whenever portaliq is installed. An admin's `publication_url_template` MUST win.
Without portaliq the link MUST be opencatalogi's public publication API.

#### Scenario: A match notice
- **GIVEN** a resident with a saved search and portaliq installed
- **WHEN** a new publication matches and the notice is written
- **THEN** its body MUST link to the publication page of the site, not to the API

### Requirement: The frequency reads in words
The saved-search frequency MUST read "Direct", "Dagelijks" or "Wekelijks" in the list, on the card and in the form,
never `immediate`, `daily` or `weekly`.

#### Scenario: A daily search
- **GIVEN** a saved search with frequency `daily`
- **WHEN** the resident opens "Mijn zoekopdrachten"
- **THEN** it MUST read "Dagelijks"

### Requirement: The portal pages name their group and say their name once
"Mijn dossiers" and "Mijn zoekopdrachten" MUST declare `group: "Openbare informatie"`, and their intro text MUST NOT
repeat the page name as a heading.

#### Scenario: The resident menu
- **GIVEN** a signed-in resident
- **WHEN** the site builds the menu
- **THEN** both pages MUST sit under "Openbare informatie"
