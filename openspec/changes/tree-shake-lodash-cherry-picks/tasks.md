# Tasks: tree-shake-lodash-cherry-picks

## 1. Replace single-use `_.cloneDeep` barrel imports

- **spec_ref**: `openspec/changes/tree-shake-lodash-cherry-picks/specs/frontend-performance/spec.md#requirement-no-lodash-barrel-imports-for-single-function-use`
- **files**: `src/modals/object/ObjectModal.vue`, `src/modals/menu/ViewMenuModal.vue`, `src/modals/menuItem/MenuItemForm.vue`, `src/dialogs/page/DeletePageContentDialog.vue`
- **acceptance_criteria**:
  - GIVEN each of the four files WHEN grepped for `from 'lodash'` THEN there is no match
  - GIVEN the existing clone call site in each file THEN it uses `structuredClone(...)` (or an equivalent local zero-dependency deep-clone helper) and produces the same shape as the prior `_.cloneDeep` call for the plain JSON-shaped state being cloned
  - GIVEN `npm run build` THEN the build succeeds with no new console warnings

- [ ] 1.1 `ObjectModal.vue:226,437` — drop `import _ from 'lodash'`; replace `_.cloneDeep(...)` with `structuredClone(...)`
- [ ] 1.2 `ViewMenuModal.vue:240,351` — same replacement
- [ ] 1.3 `MenuItemForm.vue:309,903` — same replacement
- [ ] 1.4 `DeletePageContentDialog.vue:53,102` — same replacement
- [ ] 1.5 Manually smoke-test each modal/dialog's save/duplicate flow in the running app to confirm the cloned object still round-trips correctly (structuredClone rejects functions/DOM nodes present — verify none of the four cloned objects carry either)

## 2. Cherry-pick lodash imports in the dual-function file

- **spec_ref**: `openspec/changes/tree-shake-lodash-cherry-picks/specs/frontend-performance/spec.md#requirement-no-lodash-barrel-imports-for-single-function-use`
- **files**: `src/modals/pageContents/PageContentForm.vue`
- **acceptance_criteria**:
  - GIVEN `PageContentForm.vue` WHEN grepped for `from 'lodash'` THEN it imports only `lodash/cloneDeep` and `lodash/upperFirst`, not the barrel `lodash`
  - GIVEN the existing `_.cloneDeep` (line 447) and `_.upperFirst` (line 10) call sites THEN they resolve to the cherry-picked named imports with identical behavior

- [ ] 2.1 Replace `import _ from 'lodash'` with `import cloneDeep from 'lodash/cloneDeep'` and `import upperFirst from 'lodash/upperFirst'`
- [ ] 2.2 Replace the two `_.cloneDeep`/`_.upperFirst` call sites with the named imports
- [ ] 2.3 Manually smoke-test the page-content form's content-block/FAQ add/clone flow

## 3. Verify bundle impact

- **spec_ref**: `openspec/changes/tree-shake-lodash-cherry-picks/specs/frontend-performance/spec.md#requirement-no-lodash-barrel-imports-for-single-function-use`
- **files**: n/a (build output only)
- **acceptance_criteria**:
  - GIVEN a production build before and after this change THEN the emitted chunks touching the five files above no longer contain the full lodash module graph

- [ ] 3.1 Run `npm run build` before/after (or `webpack-bundle-analyzer` if available) and confirm lodash's footprint in the affected chunks drops to the two cherry-picked function modules only
- [ ] 3.2 Run `npm run check:l10n` (no i18n keys touched, but confirms nothing else broke) and existing vitest suite (`npm test` / relevant vitest files) to confirm no regression
