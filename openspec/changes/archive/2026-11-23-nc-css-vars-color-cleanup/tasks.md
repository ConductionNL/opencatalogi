# Tasks: nc-css-vars-color-cleanup

## Implementation Tasks

### Task 1: Theme-aware chart palette helper
- **spec_ref**: `openspec/changes/nc-css-vars-color-cleanup/specs/frontend-theming/spec.md#requirement-all-frontend-colors-come-from-nc-css-variables-thm-001`
- **files**: `src/composables/useChartColors.js` (new), `src/views/dashboard/Dashboard.vue`
- **acceptance_criteria**:
  - GIVEN the dashboard donut and traffic charts render THEN their `colors` arrays are resolved from NC CSS variables at runtime (via `getComputedStyle`), not hex literals
  - GIVEN the nldesign theme overrides `--color-primary-element` THEN the charts pick up the override on next load
- [x] Added `useCategoricalChartColors()` (7-color ordered palette) and
      `useAccentChartColor()` (single-color) in the new
      `src/composables/useChartColors.js`, resolving each color via
      `getComputedStyle(document.documentElement).getPropertyValue(...)` with
      the original hex as fallback (used only when the variable is unset,
      e.g. in a non-browser test context).
- [x] Replaced the two literals in `Dashboard.vue` (`colors: [...]` on the
      donut chart, `colors: ['#079cff']` on the traffic chart) with
      `categoricalChartColors` / `accentChartColor` computed properties that
      call the new composable.

### Task 2: Fix light-only text colors in publication views
- **spec_ref**: `openspec/changes/nc-css-vars-color-cleanup/specs/frontend-theming/spec.md#requirement-all-frontend-colors-come-from-nc-css-variables-thm-001`
- **files**: `src/views/publications/PublicationList.vue`, `src/views/publications/PublicationDetail.vue`
- **acceptance_criteria**:
  - GIVEN the affected elements render in light and dark theme THEN text remains readable (WCAG AA) and uses NC variables
- [x] Replaced `color: #EBEBEB !important` in both files with `color:
      var(--color-error-text) !important` — both instances style the delete
      button's text against a `background-color: var(--color-error)`
      backdrop, so the semantically-correct NC variable is the one that's
      guaranteed contrast-appropriate against that specific background, not
      a generic near-white literal.
- [x] Bonus: fixed pre-existing stylelint `rule-empty-line-before` /
      `indentation` errors in the same `<style>` blocks in both files (mixed
      tabs/4-space indentation, missing blank lines between rules) — these
      were pre-existing debt in files this change already touched.

### Task 3: Deduplicate the JSON syntax-highlight palette
- **spec_ref**: `openspec/changes/nc-css-vars-color-cleanup/specs/frontend-theming/spec.md#requirement-shared-json-highlight-stylesheet-thm-002`
- **files**: `src/css/json-highlight.css` (new), `src/modals/object/DownloadObject.vue`, `src/modals/object/ObjectModal.vue`, `src/modals/object/UploadObject.vue`, `src/modals/object/MergeObject.vue`, `src/modals/object/MigrationObject.vue`, `src/modals/menuItem/MenuItemForm.vue`, `src/modals/generic/UploadFiles.vue`
- **acceptance_criteria**:
  - GIVEN the seven modals render JSON THEN they all import the single shared stylesheet and contain no local hex token colors
  - GIVEN dark theme THEN token colors come from NC-variable-mapped values with adequate contrast
- [x] Created `src/css/json-highlight.css`, mapping every token/selection
      color to an app-scoped custom property (`--oc-json-*`) with the
      original hex as its `var(--x, fallback)` default — there is no
      NC-core semantic variable for "JSON boolean color", so these are new,
      app-owned tokens, not existing NC variables. Note: the file
      deliberately does NOT use `:deep()` — that pseudo-class only has
      meaning inside a Vue SFC `<style scoped>` block; a plain globally
      imported stylesheet has no scoping to pierce, so `:deep()` there would
      be an invalid selector silently dropped by the browser. Plain
      descendant selectors are used instead.
  - [x] `DownloadObject.vue`, `MenuItemForm.vue` — exact-duplicate full
        blocks (base container + all 4 token colors + selection colors),
        deleted and replaced with `import '../../css/json-highlight.css'`
        in `<script setup>`.
  - [x] `ObjectModal.vue` — had EXTRA file-specific rules interleaved with
        the duplicate block (border/border-radius/position on
        `.codeMirrorContainer`, CSS-var-based dark/light `.cm-editor`
        background overrides, tab-styling rules). Removed only the
        exact-duplicate hex-color-bearing rules, kept the file-specific
        additions in place (harmless overlap: the shared stylesheet's base
        `.codeMirrorContainer` rule and this file's own scoped rule for the
        same selector both apply; Vue's scoped-attribute gives the local
        rule higher specificity, so nothing regresses).
  - [x] `UploadObject.vue` — same as above, kept its unique
        `.prettifyButton` rule and the explanatory CodeMirror-class-mapping
        comment, removed only the duplicate palette.
  - [x] `MergeObject.vue`, `MigrationObject.vue` — these only had a PARTIAL
        copy of the palette (number-token + selection colors; missing the
        base string/boolean/null non-selection colors and the base
        container/border rules entirely). Importing the shared stylesheet
        gives both files the FULL canonical palette — this is an intentional
        consequence of deduplication (the proposal's own framing: "the
        duplication means the copies drift independently"; unifying to one
        canonical version is the fix), not an oversight. Flagging here since
        it's a visible behavior change for these two files specifically
        (previously-unstyled string/boolean/null tokens now get color) and
        hasn't been verified live.
  - [x] `UploadFiles.vue` — re-investigated: this file has NO
        `codeMirrorContainer`/JSON-highlight block at all (confirmed via
        grep; it renders a file list, not a CodeMirror JSON view). The
        proposal listed it among the "seven modals" for the dedup, but the
        only actual color issue in this file was the unrelated `#ff0000`
        (fixed as its own item below). Nothing to import/delete here for
        Task 3 — noting the discrepancy rather than silently dropping it.
  - [x] Also fixed, while in this file: `.files-table-name-wrong > span {
        color: #ff0000 !important; }` → `color: var(--color-error)
        !important` (the literal the proposal's "Why" section called out).

### Task 4: Stylelint guard against new hex literals
- **spec_ref**: `openspec/changes/nc-css-vars-color-cleanup/specs/frontend-theming/spec.md#requirement-all-frontend-colors-come-from-nc-css-variables-thm-001`
- **files**: `stylelint.config.js`
- **acceptance_criteria**:
  - GIVEN a PR adds a raw hex color in `src/**/*.vue` styles THEN stylelint fails locally and in CI
  - GIVEN a `var(--x, #fallback)` fallback THEN it is allowed
- [x] Configured `color-no-hex: true` in `stylelint.config.js`. Note:
      stylelint's built-in `color-no-hex` rule has no native "allow inside
      var() fallback" option — it flags every hex token regardless of
      context, including fallback values. The "allowed" mechanism is
      therefore explicit `stylelint-disable-next-line color-no-hex` /
      `stylelint-disable color-no-hex` comments at each fallback site (4 in
      `Dashboard.vue`, one file-level disable in `json-highlight.css` since
      every declaration there is a fallback) — this matches the proposal's
      own phrasing ("an explicit allow-list only where... demands
      literals") more literally than a rule-config exception would have.
- [x] Ran the linter over the full `src/**/*.vue` + `src/**/*.css` tree:
      0 `color-no-hex` violations. 41 pre-existing `rule-empty-line-before`
      errors remain in 7 files this change did not touch (unrelated
      spacing-style debt, confirmed present in the baseline before this
      change) — out of scope for a color-cleanup change; not fixed here.
