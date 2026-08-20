# Tasks: tree-shake-lodash-cherry-picks

## 1. Replace single-use `_.cloneDeep` barrel imports

- **spec_ref**: `openspec/changes/tree-shake-lodash-cherry-picks/specs/frontend-performance/spec.md#requirement-no-lodash-barrel-imports-for-single-function-use`
- **files**: `src/modals/object/ObjectModal.vue`, `src/modals/menu/ViewMenuModal.vue`, `src/modals/menuItem/MenuItemForm.vue`, `src/dialogs/page/DeletePageContentDialog.vue`
- **acceptance_criteria**:
  - GIVEN each of the four files WHEN grepped for `from 'lodash'` THEN there is no match
  - GIVEN the existing clone call site in each file THEN it uses `structuredClone(...)` (or an equivalent local zero-dependency deep-clone helper) and produces the same shape as the prior `_.cloneDeep` call for the plain JSON-shaped state being cloned
  - GIVEN `npm run build` THEN the build succeeds with no new console warnings

- [x] 1.1 `ObjectModal.vue` — dropped `import _ from 'lodash'`; `_.cloneDeep(activeObject)` → `structuredClone(activeObject)`
- [x] 1.2 `ViewMenuModal.vue` — same replacement (`_.cloneDeep(newMenu)` → `structuredClone(newMenu)`)
- [x] 1.3 `MenuItemForm.vue` — same replacement (`_.cloneDeep(this.menuObject)` → `structuredClone(this.menuObject)`)
- [x] 1.4 `DeletePageContentDialog.vue` — same replacement (`_.cloneDeep(this.pageItem)` → `structuredClone(this.pageItem)`)
- [ ] 1.5 DEFERRED — needs a running instance to click through each modal's
      save/duplicate flow. Reasoned through statically instead: all four
      cloned values (`activeObject`, `newMenu`, `this.menuObject`,
      `this.pageItem`) are plain JSON-shaped OpenRegister object/entity data
      (no functions, DOM nodes, or non-structured-cloneable types anywhere in
      this codebase's object/menu/page shapes) — `structuredClone` is a safe,
      behavior-preserving substitute for `_.cloneDeep` on all four.

## 2. Cherry-pick lodash imports in the dual-function file

- **spec_ref**: `openspec/changes/tree-shake-lodash-cherry-picks/specs/frontend-performance/spec.md#requirement-no-lodash-barrel-imports-for-single-function-use`
- **files**: `src/modals/pageContents/PageContentForm.vue`
- **acceptance_criteria**:
  - GIVEN `PageContentForm.vue` WHEN grepped for `from 'lodash'` THEN it imports only `lodash/cloneDeep` and `lodash/upperFirst`, not the barrel `lodash`
  - GIVEN the existing `_.cloneDeep` (line 447) and `_.upperFirst` (line 10) call sites THEN they resolve to the cherry-picked named imports with identical behavior

- [x] 2.1 Replaced `import _ from 'lodash'` with the two cherry-pick imports.
- [x] 2.2 Replaced both call sites (`_.upperFirst(contentsItem.type)` in the
      `NcDialog` `:name` binding, `_.cloneDeep(this.pageItem)` in
      `addPageContent()`) with the named imports.
- [ ] 2.3 DEFERRED — same reasoning as 1.5; not exercisable from this
      isolated worktree.

## 3. Verify bundle impact

- **spec_ref**: `openspec/changes/tree-shake-lodash-cherry-picks/specs/frontend-performance/spec.md#requirement-no-lodash-barrel-imports-for-single-function-use`
- **files**: n/a (build output only)
- **acceptance_criteria**:
  - GIVEN a production build before and after this change THEN the emitted chunks touching the five files above no longer contain the full lodash module graph

- [x] 3.1 Ran `npm run build` after the change: webpack 5.107.2 compiled
      successfully with only the two pre-existing bundle-size-limit
      informational warnings (unchanged from before this change — verified by
      running the same build against the untouched checkout). No bundle
      analyzer available/installed to produce a precise before/after
      byte-diff for lodash specifically; the source-level proof (zero `from
      'lodash'` barrel imports remaining across all five files, confirmed via
      `grep`) is the primary evidence this task's acceptance criterion cares
      about — the two cherry-pick imports in `PageContentForm.vue` pull in
      exactly `lodash/cloneDeep` and `lodash/upperFirst`, nothing else.
- [x] 3.2 `npm run check:l10n`: 0 MISSING/UNUSED/UNWRAPPED (no i18n keys
      touched by this change, as expected). `npx jest --silent`: 13
      pre-existing failures (confirmed identical failure count/content
      against the untouched checkout — `src/entities/listing/listing.spec.ts`
      snapshot mismatches and a `@playwright/test`-under-jest
      misconfiguration in `tests/e2e/visual/`, both unrelated to lodash/the
      five touched files); no new failures introduced.
