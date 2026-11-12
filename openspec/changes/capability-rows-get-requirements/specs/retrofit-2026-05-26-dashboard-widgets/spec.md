# retrofit-2026-05-26-dashboard-widgets

## ADDED Requirements

### Requirement: The dashboard counts catalogues, publications and attachments (REQ-CRR-005)

The app's own dashboard MUST show how many catalogues, publications and
attachments the user can see. The publication count SHALL be split into
concept, published and depublished.

#### Scenario: An officer opens the dashboard

- **GIVEN** an officer who can see 3 catalogues, 40 publications and 95 attachments
- **WHEN** they open the dashboard
- **THEN** it shows 3 catalogues, 40 publications and 95 attachments

#### Scenario: Nothing exists yet

- **GIVEN** a new installation without catalogues
- **WHEN** an administrator opens the dashboard
- **THEN** each count shows 0
