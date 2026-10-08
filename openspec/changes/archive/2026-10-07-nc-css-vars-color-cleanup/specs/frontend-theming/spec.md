# frontend-theming Specification (delta)

**Status**: proposed
**Scope**: opencatalogi
**OpenSpec changes**:
- nc-css-vars-color-cleanup

## Purpose

Bring opencatalogi's frontend styling into conformance with the fleet color
rule (ADR-004 / ADR-010): all colors flow from Nextcloud CSS variables so
nldesign theming and dark mode work, and shared style blocks live in one
place.

## ADDED Requirements

### Requirement: All frontend colors come from NC CSS variables (THM-001)

Frontend styles and runtime-resolved chart palettes MUST use Nextcloud CSS
variables (e.g. `--color-primary-element`, `--color-main-text`,
`--color-success`, `--color-warning`, `--color-error`). Raw hex literals MUST
NOT appear in `src/**/*.vue` styles or in JavaScript color arrays, except as
the fallback argument inside `var(--x, fallback)`. Chart libraries that
require concrete values MUST resolve them from the computed NC variables at
runtime. A stylelint rule MUST enforce this in CI.

#### Scenario: charts follow the active theme
- GIVEN the nldesign app overrides `--color-primary-element`
- WHEN the dashboard donut and traffic charts render
- THEN their palettes reflect the overridden variable values
- AND no hex literal palette is present in the component source

#### Scenario: dark mode keeps text readable
- GIVEN the user switches Nextcloud to dark theme
- WHEN publication list and detail views render
- THEN all text colors resolve from NC variables and meet WCAG AA contrast

#### Scenario: CI rejects new hex literals
- GIVEN a change introduces `color: #123456` in a Vue style block
- WHEN stylelint runs
- THEN the check fails

### Requirement: Shared JSON highlight stylesheet (THM-002)

The JSON syntax-highlight token styles used by object/menu modals MUST live in
a single shared stylesheet mapped to NC CSS variables. Components MUST import
the shared stylesheet; per-component copies of the token palette MUST NOT
exist.

#### Scenario: one source of truth for highlight tokens
- GIVEN the seven JSON-rendering modals (DownloadObject, ObjectModal,
  UploadObject, MergeObject, MigrationObject, MenuItemForm, UploadFiles)
- WHEN their styles are inspected
- THEN each imports the shared highlight stylesheet
- AND none contains a local hex token palette
