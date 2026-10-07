---
status: proposed
---

# Officer accessibility

## ADDED Requirements

### Requirement: Every officer page passes axe at WCAG 2.2 AA (REQ-OFA-001)

`tests/e2e/officer-accessibility.spec.ts` SHALL open every page declared in `src/manifest.json` (the list read from the manifest at test time, so a new page is covered by default), with the CI seed loaded and a detail page opened on a seeded object, and SHALL run axe with the tags `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa` and `wcag22aa`, scoped to OpenCatalogi's content root (`#content-vue` or the root element the app mounts). It SHALL fail on any violation of impact serious or critical that is not in `tests/e2e/a11y-allowlist.json`. Each allowlist entry SHALL name the rule, the page, the reason and an issue URL. The spec SHALL fail on an allowlist entry that no longer occurs, and on a page that does not load.

#### Scenario: Every officer page passes axe

- **GIVEN** the CI seed
- **WHEN** the gate opens each of the manifest's pages
- **THEN** no page has a serious or critical violation outside the allowlist
- **AND** the run lists how many pages it checked, equal to the manifest's page count

#### Scenario: A stale allowlist entry fails

- **GIVEN** an allowlist entry for a rule that no longer fires on its page
- **WHEN** the gate runs
- **THEN** it fails naming the entry

### Requirement: The officer flow works from the keyboard alone (REQ-OFA-002)

`tests/e2e/officer-keyboard.spec.ts` SHALL, using only Tab, Shift+Tab, Enter, Space, Escape and the arrow keys, create a publication with a title and a category, attach a document, publish it and withdraw it with a reason. At every focus change it SHALL assert that the focused element is visible, inside the viewport, and has a focus indicator (a computed outline or box-shadow differing from its unfocused style). It SHALL assert that every dialog traps focus while open and returns focus to its opener on close.

#### Scenario: An officer publishes and withdraws by keyboard alone

- **GIVEN** a signed-in officer on the publications list
- **WHEN** the officer creates, attaches, publishes and withdraws a publication with the keyboard only
- **THEN** each step completes and the publication ends withdrawn
- **AND** every focused control showed a visible focus indicator

### Requirement: A screen reader pass is run and its result published (REQ-OFA-003)

`tests/e2e/officer-screen-reader.spec.ts` SHALL walk the flow of REQ-OFA-002 with `@guidepup/virtual-screen-reader`, store the spoken transcript as a test artefact, and assert that every control used is announced with its role and name and that publish and withdraw announce their outcome. `docs/accessibility/screen-reader-report.md` SHALL hold a dated report with: the virtual transcript's summary; a section per real screen reader (NVDA with Firefox on Windows, VoiceOver with Safari on macOS) for the officer flow and the citizen flow, with the version used, who ran it, the date, what worked and what failed with an issue link; and "not yet run" for any pass a person has not done. The documentation site SHALL link the report.

#### Scenario: The virtual walk names every control
<!-- @e2e exclude This is itself the Playwright spec; proven by tests/e2e/officer-screen-reader.spec.ts, which fails on today's code where the attach control is announced without a name. -->

- **GIVEN** the officer flow
- **WHEN** the virtual screen reader walks it
- **THEN** every control used is announced with a role and a name

#### Scenario: The report is published with its date
<!-- @e2e exclude Documentation artefact; proven by DocsAccessibilityReportTest (a vitest test reading the report) asserting a date, both screen readers and both flows, and failing on a section that claims a pass without a name and date. -->

- **GIVEN** the documentation site
- **WHEN** a reader opens the accessibility report
- **THEN** it shows the date, the virtual pass and, per real screen reader and flow, either the person's result or "not yet run"
