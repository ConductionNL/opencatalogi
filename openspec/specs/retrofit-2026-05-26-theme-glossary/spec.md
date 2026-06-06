# retrofit-2026-05-26-theme-glossary Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-26-theme-glossary. Update Purpose after archive.

> @e2e exclude Whole-spec reverse-engineered theme/glossary modal component-logic capability — both scenarios assert modal internals (theme persisted and modal closed on save, glossary term selected for viewing). These are deterministic component-unit assertions verified by vitest over the theme/glossary modals; the user-facing equivalents are already real-UI covered under content-management::attach-a-theme-to-a-publication, ::bulk-delete-themes and ::view-a-glossary-term.

## Requirements
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

