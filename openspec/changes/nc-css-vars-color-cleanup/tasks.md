# Tasks: nc-css-vars-color-cleanup

## Implementation Tasks

### Task 1: Theme-aware chart palette helper
- **spec_ref**: `openspec/changes/nc-css-vars-color-cleanup/specs/frontend-theming/spec.md#requirement-all-frontend-colors-come-from-nc-css-variables-thm-001`
- **files**: `src/composables/useChartColors.js` (new), `src/views/dashboard/Dashboard.vue`
- **acceptance_criteria**:
  - GIVEN the dashboard donut and traffic charts render THEN their `colors` arrays are resolved from NC CSS variables at runtime (via `getComputedStyle`), not hex literals
  - GIVEN the nldesign theme overrides `--color-primary-element` THEN the charts pick up the override on next load
- [ ] Add a `useChartColors()` helper resolving an ordered categorical palette from NC variables with documented fallbacks
- [ ] Replace the literals at `Dashboard.vue:37` and `Dashboard.vue:145`

### Task 2: Fix light-only text colors in publication views
- **spec_ref**: `openspec/changes/nc-css-vars-color-cleanup/specs/frontend-theming/spec.md#requirement-all-frontend-colors-come-from-nc-css-variables-thm-001`
- **files**: `src/views/publications/PublicationList.vue`, `src/views/publications/PublicationDetail.vue`
- **acceptance_criteria**:
  - GIVEN the affected elements render in light and dark theme THEN text remains readable (WCAG AA) and uses NC variables
- [ ] Replace `color: #EBEBEB !important` (PublicationList.vue:257, PublicationDetail.vue:1159) with the correct NC variable per context

### Task 3: Deduplicate the JSON syntax-highlight palette
- **spec_ref**: `openspec/changes/nc-css-vars-color-cleanup/specs/frontend-theming/spec.md#requirement-shared-json-highlight-stylesheet-thm-002`
- **files**: `src/css/json-highlight.css` (new), `src/modals/object/DownloadObject.vue`, `src/modals/object/ObjectModal.vue`, `src/modals/object/UploadObject.vue`, `src/modals/object/MergeObject.vue`, `src/modals/object/MigrationObject.vue`, `src/modals/menuItem/MenuItemForm.vue`, `src/modals/generic/UploadFiles.vue`
- **acceptance_criteria**:
  - GIVEN the seven modals render JSON THEN they all import the single shared stylesheet and contain no local hex token colors
  - GIVEN dark theme THEN token colors come from NC-variable-mapped values with adequate contrast
- [ ] Create the shared stylesheet mapped to NC variables (fallbacks allowed only inside `var(--x, fallback)`)
- [ ] Delete the copy-pasted blocks from all seven modals and import the shared file

### Task 4: Stylelint guard against new hex literals
- **spec_ref**: `openspec/changes/nc-css-vars-color-cleanup/specs/frontend-theming/spec.md#requirement-all-frontend-colors-come-from-nc-css-variables-thm-001`
- **files**: `stylelint.config.js`
- **acceptance_criteria**:
  - GIVEN a PR adds a raw hex color in `src/**/*.vue` styles THEN stylelint fails locally and in CI
  - GIVEN a `var(--x, #fallback)` fallback THEN it is allowed
- [ ] Configure `color-no-hex` (or equivalent) with the fallback exception
- [ ] Run the linter over the cleaned tree and fix any residue
