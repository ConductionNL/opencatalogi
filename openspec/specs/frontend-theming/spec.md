---
status: done
retrofit_extensions:
  - THM-001
  - THM-002
---

# Frontend Theming

Color-hygiene requirements for the opencatalogi SPA, bringing frontend styling into
conformance with the fleet color rule (ADR-004 / ADR-010): all colors flow from Nextcloud
CSS variables so nldesign theming and dark mode work, and shared style blocks live in one
place. This capability was created by `nc-css-vars-color-cleanup` — theming hygiene had no
prior home in opencatalogi's spec tree.

## Requirements

### Requirement: All frontend colors come from NC CSS variables (THM-001)

Frontend styles and runtime-resolved chart palettes MUST use Nextcloud CSS variables (e.g.
`--color-primary-element`, `--color-main-text`, `--color-success`, `--color-warning`,
`--color-error`). Raw hex literals MUST NOT appear in `src/**/*.vue` styles or in JavaScript
color arrays, except as the fallback argument inside `var(--x, fallback)`. Chart libraries
that require concrete values MUST resolve them from the computed NC variables at runtime. A
stylelint rule MUST enforce this in CI (`color-no-hex` in `stylelint.config.js`); `var(--x,
#fallback)` exceptions MUST be allow-listed explicitly via a
`stylelint-disable(-next-line) color-no-hex` comment, not by weakening the rule.

**Priority:** Must **Status:** Implemented

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
- THEN the check fails, unless the line carries an explicit `stylelint-disable-next-line color-no-hex` comment for a `var(--x, #fallback)` exception

### Requirement: Shared JSON highlight stylesheet (THM-002)

The JSON syntax-highlight token styles used by object/menu modals MUST live in a single
shared stylesheet (`src/css/json-highlight.css`) mapped to app-scoped CSS custom properties
with fallback values. Components MUST import the shared stylesheet; per-component copies of
the token palette MUST NOT exist.

**Priority:** Must **Status:** Implemented

#### Scenario: one source of truth for highlight tokens
- GIVEN the JSON-rendering modals (DownloadObject, ObjectModal, UploadObject, MergeObject, MigrationObject, MenuItemForm)
- WHEN their styles are inspected
- THEN each imports the shared highlight stylesheet
- AND none contains a local hex token palette
