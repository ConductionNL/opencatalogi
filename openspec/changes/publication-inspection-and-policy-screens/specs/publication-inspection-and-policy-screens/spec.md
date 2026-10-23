# publication-inspection-and-policy-screens

## ADDED Requirements

### Requirement: The publication page shows its public inspection period (PIP-001)

The publication page SHALL show the comment periods of that publication with
their legal remedy, start, end and term, and say so when there is none.

@e2e exclude Manifest configuration over the library's record list; publicationScreensConfig.spec.js asserts the widget is scoped to the page's publication.

#### Scenario: The Kerkplein notice is open for views

- **GIVEN** "Kennisgeving ontwerpbestemmingsplan Kerkplein" with a 42-day comment period for zienswijzen
- **WHEN** an editor opens its page
- **THEN** the Public inspection card shows "Open for views (zienswijzen)" with its start, its end and "42 days"

### Requirement: An administrator manages the publication policy on one page (PIP-002)

The app SHALL have a Publication policy page for administrators listing the
publication rules and the banner, each with Add, and the collections that
publish.

@e2e exclude Manifest configuration over the library's object and record lists; publicationScreensConfig.spec.js asserts the sources.

#### Scenario: The three rules of Zuiddrecht

- **WHEN** an administrator opens Publication policy
- **THEN** the rules Raadsbesluiten, Woo-besluiten and Convenanten are listed, Convenanten off
