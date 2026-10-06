# Tasks: officer-flow-accessibility

Read `openspec/woo-build-rules.md` first. Never `pkill -f playwright`; kill by PID. The shared Playwright MCP service is not this suite: run `npx playwright test` with the repo's `tests/e2e/playwright.config.ts`.

## 1. Axe gate

- [ ] 1.1 Add `@axe-core/playwright` and `tests/e2e/officer-accessibility.spec.ts` reading the page list from `src/manifest.json`, with the stale-entry and load-failure checks (REQ-OFA-001). Verify: the spec run locally against the dev instance; record the first run's violations per page in the PR body. It fails today on any page with a serious or critical violation; that first red is the proof it measures.
- [ ] 1.2 Fix the violations in OpenCatalogi's own components (labels, contrast through CSS variables only, landmarks, names on icon buttons) and put only the ones outside OpenCatalogi's code in `tests/e2e/a11y-allowlist.json`, each with an issue (upstream for `@nextcloud/vue` or server) (REQ-OFA-001). Verify: `tests/e2e/officer-accessibility.spec.ts` green; the allowlist count in the PR body.
- [ ] 1.3 Confirm the spec runs in CI: it sits under `tests/e2e`, which the shared workflow runs; name the CI job and the spec's line in its log in the PR body (REQ-OFA-001). Verify: the cited job.

## 2. Keyboard walk

- [ ] 2.1 Add `tests/e2e/officer-keyboard.spec.ts` with the focus-indicator and focus-trap assertions (REQ-OFA-002). Verify: the spec; it must fail on a step that today needs the mouse, so note in the PR body which step failed first.
- [ ] 2.2 Fix what blocks the keyboard in OpenCatalogi's components (REQ-OFA-002). Verify: the spec green.

## 3. Virtual screen reader

- [ ] 3.1 Add `@guidepup/virtual-screen-reader` and `tests/e2e/officer-screen-reader.spec.ts` storing the transcript as an artefact (REQ-OFA-003). Verify: the spec, and the transcript attached to the PR.
- [ ] 3.2 Fix missing names and unannounced state changes (live regions for publish and withdraw outcomes) (REQ-OFA-003). Verify: the spec green.

## 4. Report

- [ ] 4.1 Write `docs/accessibility/screen-reader-report.md` with the virtual pass and "not yet run" for NVDA and VoiceOver on both flows, following the writing skill, and link it from the docs sidebar (REQ-OFA-003). Verify: `tests/vitest/docsAccessibilityReport.spec.js` (`DocsAccessibilityReportTest`) asserting the date, both readers, both flows, and that no section claims a pass without a name and a date; a grep for U+2014 on the report.
- [ ] 4.2 Write a step list for the person's pass in the same report (what to open, what to listen for, where to note the result).
- [ ] 4.3 Needs a person: ask in the PR body for an NVDA and a VoiceOver pass on the officer and citizen flows. The agent does not fill these sections. Row 15.5 stays open until a person's pass is in the report.

## 5. Verification

- [ ] 5.1 `TMPDIR` a sibling directory outside the clone. Run the three Playwright specs once against the dev instance and paste their summary lines in the PR body.
- [ ] 5.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran (the a11y gates among them).
- [ ] 5.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`.
- [ ] 5.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Rows 15.1 and 15.6 become `production` only once a store release ships it; 15.5 once the person's pass is also published.
