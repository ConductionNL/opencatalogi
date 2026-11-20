# retrofit-2026-05-26-app-shell-settings

## ADDED Requirements

### Requirement: An administrator re-imports the shipped configuration after an update (REQ-CRR-004)

After an app update the app MUST import its shipped register configuration
when the shipped version is newer than the installed one. On the settings page
an administrator SHALL be able to start that import by hand with Reimport, and
with Force import even when the versions are equal.

#### Scenario: An update brings a newer configuration

- **GIVEN** an installation whose imported configuration is older than the shipped one
- **WHEN** the app is updated
- **THEN** the shipped configuration is imported without anyone pressing a button

#### Scenario: An administrator forces the import

- **GIVEN** an administrator on the settings page and a configuration that is already current
- **WHEN** they choose Force import
- **THEN** the shipped configuration is imported again and the page reports the result
