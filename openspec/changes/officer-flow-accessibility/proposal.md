---
kind: code
depends_on: []
---

# Proposal: officer-flow-accessibility

## Why

Accessibility for government software is a legal duty for the whole product, not only for the citizen side (Besluit digitale toegankelijkheid overheid, EN 301 549, which takes WCAG 2.1 AA; the programme measures at WCAG 2.2 AA). An officer who uses a screen reader or only a keyboard has to be able to publish. Today the citizen side is measured and the officer side is not.

Rows, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`:

- **15.1** "The officer side meets WCAG 2.2 AA, not only the portal". Ours: partial, production. Evidence: "portaliq gates its CITIZEN surfaces on axe at wcag22aa (tests/e2e/site-accessibility.spec.ts, tests/e2e/portal-accessibility.spec.ts, serious and critical failing the build) ... opencatalogi carries axe-core ^4.10.0 as a dependency with no a11y spec files, and portaliq's officer dashboard (src/manifest.json, 35 pages) is covered by neither spec".
- **15.5** "A screen reader has been run over the flow, and the result is published". Ours: no. Evidence: "no published screen reader result for this stack".
- **15.6** "The whole officer flow works from the keyboard alone". Ours: partial, production. Evidence: "portaliq asserts a keyboard-only path on the CITIZEN side and not the officer side ... Nothing walks the officer flow of either app".

Read on development at 35999c296. OpenCatalogi's `src/manifest.json` declares 23 pages. Playwright runs in CI through the shared workflow with `playwright-test-path: tests/e2e` and the seed `tests/e2e/ci-seed.sh`. `axe-core` is a dev dependency; no spec uses it.

## What changes

- An axe gate over every OpenCatalogi officer page at WCAG 2.2 AA (`wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `wcag22aa` tags), scoped to the app's own content root so Nextcloud's chrome is not counted, failing on a serious or critical violation. Known violations that cannot be fixed in this change are listed by rule and page in a reviewed allowlist file, each with an issue link; the list may only shrink.
- A keyboard-only Playwright walk of the officer flow: create a publication, attach a document, publish, withdraw. It uses only Tab, Shift+Tab, Enter, Space, Escape and arrow keys, and asserts at every step that the focused control has a visible focus indicator.
- A virtual screen reader walk of the same flow (`@guidepup/virtual-screen-reader` in Playwright), whose spoken transcript is stored as an artefact and asserted to name every control and every state change.
- A dated screen reader report in `docs/accessibility/`: the virtual transcript plus a pass with NVDA on Windows and VoiceOver on macOS done by a person, on the officer flow and the citizen flow, with what failed and its issue. The agent prepares the report template and the virtual pass; a person runs the two real screen readers.
- The violations the gate and the walks find in OpenCatalogi's own components are fixed in this change, as far as they are in OpenCatalogi's code.

## Fail closed

- The axe gate fails on any serious or critical violation not on the allowlist, and on an allowlist entry that no longer occurs (so the list cannot hide a fix that was undone, nor grow quietly).
- A page that fails to load in the gate fails the gate. It is never counted as "no violations".
- The report says "not yet run" for any screen reader not run by a person. Row 15.5 is not claimed until the person's pass is published.

## Out of scope

- portaliq's officer dashboard. portaliq owns its pages and its gate.
- Fixing violations inside Nextcloud server or `@nextcloud/vue` components. They are listed with an upstream issue link.

## Dependencies

- None to build. Adds dev dependencies `@axe-core/playwright` and `@guidepup/virtual-screen-reader` (both permissively licensed; record versions in the PR body).
- A person with NVDA and VoiceOver for the real screen reader pass (task 4.3).

## Wave

Wave 1. It needs nothing new.

## Decisions

None of D1 to D13 is implemented here.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 15.1 | The officer side meets WCAG 2.2 AA, not only the portal | partial | REQ-OFA-001, scenario "Every officer page passes axe" |
| 15.5 | A screen reader has been run over the flow, and the result is published | no | REQ-OFA-003, scenario "The report is published with its date" (yes only once the person's NVDA and VoiceOver pass is in it) |
| 15.6 | The whole officer flow works from the keyboard alone | partial | REQ-OFA-002, scenario "An officer publishes and withdraws by keyboard alone" |
