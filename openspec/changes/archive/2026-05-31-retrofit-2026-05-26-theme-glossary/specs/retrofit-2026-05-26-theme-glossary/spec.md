# Capability: theme-glossary

## ADDED Requirements

### Requirement: Publication theme management (REQ-THEME-001)
The add-publication-theme and view-theme modals MUST offer theme options, list existing themes, save a theme, open the theme for editing, and close on completion.

#### Scenario: Theme saved
- **GIVEN** a theme form with valid input
- **WHEN** the save action runs
- **THEN** the theme MUST be persisted and the modal closed

### Requirement: Glossary term view (REQ-THEME-002)
The glossary view modal MUST display a glossary term, allow selecting a term, open the term for editing, and close on completion.

#### Scenario: Term selected
- **GIVEN** a glossary with multiple terms
- **WHEN** a term is selected
- **THEN** the modal MUST display the selected term
