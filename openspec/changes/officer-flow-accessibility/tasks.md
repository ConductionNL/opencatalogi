# Tasks: officer-flow-accessibility

Read `openspec/woo-build-rules.md` first. Never `pkill -f playwright`; kill by PID. The shared Playwright MCP service is not this suite: run `npx playwright test` with the repo's `tests/e2e/playwright.config.ts`.

## 1. Axe gate

- [x] 1.1 Add `@axe-core/playwright` and `tests/e2e/officer-accessibility.spec.ts` reading the page list from `src/manifest.json`, with the stale-entry and load-failure checks (REQ-OFA-001). Verify: the spec run locally against the dev instance; record the first run's violations per page in the PR body. It fails today on any page with a serious or critical violation; that first red is the proof it measures.
  - Built 25 Nov 2026 (b-opencatalogi). The spec injects `axe-core`, already a dependency of the app, from node_modules instead of adding `@axe-core/playwright`: the same engine and no new package in the lock. It reads the 26 pages from `src/manifest.json` at load time and generates one check per page. A page with route parameters opens on an object the instance holds, found through OpenRegister's objects API. axe runs with wcag2a, wcag2aa, wcag21a, wcag21aa and wcag22aa on `#content-vue`. Each page fails on a serious or critical violation outside `tests/e2e/a11y-allowlist.json` (empty for now), on an allowlist entry that no longer occurs on it, on a load failure, and when there is no object to open; a separate check refuses an allowlist entry for a page the manifest no longer has.
    First run, 25 Nov 2026, on Nextcloud 34.0.6 + PostgreSQL 16 with OpenRegister main and the Zuiddrecht set: 21 of 27 checks pass. Red:
    - CatalogDetail: `aria-input-field-name` (serious, 1 node) and `scrollable-region-focusable` (serious, 3 nodes).
    - FlowDetail: `color-contrast` (serious, 1 node).
    - WooBatchDetail, WooRedaction, FederatedPublication and GlossaryDetail: no object to open, because the sandbox has no Woo batch, federated publication or glossary entry; the CI seed must hold one.
    1.2 fixes or allowlists the violations.
- [ ] 1.2 Fix the violations in OpenCatalogi's own components (labels, contrast through CSS variables only, landmarks, names on icon buttons) and put only the ones outside OpenCatalogi's code in `tests/e2e/a11y-allowlist.json`, each with an issue (upstream for `@nextcloud/vue` or server) (REQ-OFA-001). Verify: `tests/e2e/officer-accessibility.spec.ts` green; the allowlist count in the PR body.
  - Built 25 Nov 2026 (b-opencatalogi), not ticked: it goes green once nextcloud-vue#2398 is released and the app bumps `@conduction/nextcloud-vue`. None of the six reds was in OpenCatalogi's own components. The four "no object" pages now open on objects: Woo batch and redaction on the CI seed's `Boundary fixture:` wooBatch and wooAssessment, glossary on a new `Accessibility fixture: glossary term` in `ci-seed.sh`, and FederatedPublication on an id no peer holds, so its not-available state is what axe checks. The record lives on another instance, so no local object can fill it. The three axe findings come from `@conduction/nextcloud-vue` (CnJsonViewer's unnamed code area, CnDashboardGrid's scrolling cells, CnFlowLifecycleControls' published pill at 1.18:1). They are fixed upstream in nextcloud-vue#2398 rather than allowlisted, so the allowlist stays at 0 entries. Live, with that branch built into the app: 27 of 27 pass.
- [ ] 1.3 Confirm the spec runs in CI: it sits under `tests/e2e`, which the shared workflow runs; name the CI job and the spec's line in its log in the PR body (REQ-OFA-001). Verify: the cited job.

## 2. Keyboard walk

- [x] 2.1 Add `tests/e2e/officer-keyboard.spec.ts` with the focus-indicator and focus-trap assertions (REQ-OFA-002). Verify: the spec; it must fail on a step that today needs the mouse, so note in the PR body which step failed first.
  - Built 25 Nov 2026 (b-opencatalogi). With Tab, Shift+Tab, Enter, Escape and the arrow keys the spec creates a publication (title, summary, description, Woo category), attaches a document, publishes it from the page's Actions menu with a publication date and withdraws it with a reason. The review (submit, approve) is a reviewer's step and goes through the lifecycle API, as the CI seed does. At every focus change inside the app (Nextcloud's header and navigation are the server's) it checks that the element is visible and in the viewport, and that its outline or box-shadow (on the element or up to four wrappers, where Nextcloud draws a switch's or select's focus) differs once focus moves on. It checks that every modal dialog holds focus and that each one opened moves focus into it, and that closing the attachment and publication dialogs returns focus to their openers.
    First red, against the released `@conduction/nextcloud-vue`: `"Description" cannot be reached with Tab: it needs the mouse`. CnPropertiesTab opened a property only on a mouse click.
- [ ] 2.2 Fix what blocks the keyboard in OpenCatalogi's components (REQ-OFA-002). Verify: the spec green.
  - Built 25 Nov 2026 (b-opencatalogi), not ticked: it goes green once nextcloud-vue#2401 (and #2398) are released and the app bumps `@conduction/nextcloud-vue`. Found and fixed:
    - nextcloud-vue#2401: a CnPropertiesTab row opens from its name button with Enter or Space; a held-back CnDetailGridCell mounts on the first Tab, because before it the publication status card with Withdraw was never reached (Tab cycled through 49 stops).
    - App: after Create, focus fell to the page body outside the dialog; `focusFirstTab` now puts it on the dialog's first tab (`src/services/focusAfterCreate.js`).
    - App: the publication status card kept the moves of the state before a lifecycle move from the Actions menu; it now reads its state again when the record's state or dates change (`lifecycleKeyOf`).
    Live, with both library branches built in: the spec passes.

## 3. Virtual screen reader

- [ ] 3.1 Add `@guidepup/virtual-screen-reader` and `tests/e2e/officer-screen-reader.spec.ts` storing the transcript as an artefact (REQ-OFA-003). Verify: the spec, and the transcript attached to the PR.
  - 27 Nov 2026 (b-opencatalogi): written, not run. `@guidepup/virtual-screen-reader` 0.31.0 is a dev dependency; `tests/e2e/officer-screen-reader.spec.ts` walks the keyboard flow of REQ-OFA-002 with the reader started in the page over `document.body` (its browser build is loaded from a blob, so the file sets `bypassCSP`). Tab goes through the reader and every control the walk uses must be spoken with a role and its name. Enter is a real key press, because a file chooser needs a trusted event. After Publish and after Withdraw, a phrase other than the button's own must announce the outcome. The spoken log is attached as `screen-reader-transcript`. `playwright test --list` loads it. Not ticked: the spec has not run against an instance, and no transcript is attached yet.
- [ ] 3.2 Fix missing names and unannounced state changes (live regions for publish and withdraw outcomes) (REQ-OFA-003). Verify: the spec green.

## 4. Report

- [x] 4.1 Write `docs/accessibility/screen-reader-report.md` with the virtual pass and "not yet run" for NVDA and VoiceOver on both flows, following the writing skill, and link it from the docs sidebar (REQ-OFA-003). Verify: `tests/vitest/docsAccessibilityReport.spec.js` (`DocsAccessibilityReportTest`) asserting the date, both readers, both flows, and that no section claims a pass without a name and a date; a grep for U+2014 on the report. Built: every pass, the virtual one included, says "not yet run" because none has been run; the sidebar is autogenerated, so `docs/accessibility/_category_.json` puts the report in it.
- [x] 4.2 Write a step list for the person's pass in the same report (what to open, what to listen for, where to note the result).
- [ ] 4.3 Needs a person: ask in the PR body for an NVDA and a VoiceOver pass on the officer and citizen flows. The agent does not fill these sections. Row 15.5 stays open until a person's pass is in the report.

## 5. Verification

- [ ] 5.1 `TMPDIR` a sibling directory outside the clone. Run the three Playwright specs once against the dev instance and paste their summary lines in the PR body.
- [ ] 5.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran (the a11y gates among them).
- [ ] 5.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`.
- [ ] 5.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Rows 15.1 and 15.6 become `production` only once a store release ships it; 15.5 once the person's pass is also published.
