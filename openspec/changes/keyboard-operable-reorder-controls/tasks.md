# Tasks: keyboard-operable-reorder-controls

## 1. FAQ item reorder — keyboard alternative

- **spec_ref**: `openspec/changes/keyboard-operable-reorder-controls/specs/content-management/spec.md#requirement-keyboard-operable-reorder-for-faq-and-content-block-items-cms-036`
- **files**: `src/modals/pageContents/PageContentForm.vue`
- **acceptance_criteria**:
  - GIVEN a page with 3+ FAQ items WHEN a keyboard-only user tabs to a FAQ item's "Move up"/"Move down" buttons and presses Enter/Space THEN the item swaps position with its neighbor and the swap persists on save
  - GIVEN the first FAQ item THEN its "Move up" button is disabled (and vice versa for the last item's "Move down")
  - GIVEN a screen reader user THEN each move button has an accessible name identifying the action and the target item (e.g. "Move up: <question text or index>")
- [x] 1.1 Added `moveFaqItem(index, direction)` — splice-based swap (removes at
      `index`, re-inserts at `index + direction`), guarded against out-of-bounds.
- [x] 1.2 Added `ArrowUp`/`ArrowDown` `NcButton` (type="tertiary") icon-only
      buttons per FAQ row inside a new `.reorder-buttons` wrapper, `:disabled`
      at `index === 0` / `index === length - 1`.
- [x] 1.3 Added `aria-label`s via `moveButtonLabel('up'|'down', item.question, index)`
      — composes `"Move up: <question text>"` (or `"...: item N"` when the
      question is still empty), exceeding the base acceptance criterion of a
      bare "Move up"/"Move down" label by also identifying the target item.

## 2. Content-block reorder — keyboard alternative

- **spec_ref**: `openspec/changes/keyboard-operable-reorder-controls/specs/content-management/spec.md#requirement-keyboard-operable-reorder-for-faq-and-content-block-items-cms-036`
- **files**: `src/modals/pageContents/PageContentForm.vue`
- **acceptance_criteria**: same shape as Task 1, applied to `contentsItem.contentBlocksData`
- [x] 2.1 Added `moveContentBlock(index, direction)` (identical swap logic).
- [x] 2.2 Added the same up/down `NcButton` pair per content-block row.
- [x] 2.3 Reused the same `moveButtonLabel()` helper / `Move up`/`Move down`
      keys, keyed off `item.title` instead of `item.question`.

## 3. Make the drag handle itself keyboard-discoverable

- **spec_ref**: `openspec/changes/keyboard-operable-reorder-controls/specs/content-management/spec.md#requirement-keyboard-operable-reorder-for-faq-and-content-block-items-cms-036`
- **files**: `src/modals/pageContents/PageContentForm.vue`
- **acceptance_criteria**:
  - GIVEN a keyboard user tabs through a FAQ or content-block row THEN the `Drag` handle receives focus and its accessible name announces the keyboard alternative
- [x] 3.1 Added `tabindex="0"`, `role="button"`, and the `aria-label` to both
      `Drag` handle instances (FAQ + content-block rows).
- [x] 3.2 Added `@keydown.enter.prevent` / `@keydown.space.prevent` no-op
      guards on both handles.

## 4. i18n registration

- **spec_ref**: `openspec/changes/keyboard-operable-reorder-controls/specs/content-management/spec.md#requirement-keyboard-operable-reorder-for-faq-and-content-block-items-cms-036`
- **files**: `l10n/*.js` (via tooling only)
- **acceptance_criteria**: `npm run check:l10n` reports zero MISSING/UNUSED for the new keys
- [x] 4.1 Registered `"Move up"` across all 37 shipped locales via
      `scripts/l10n-ai.js add`.
- [x] 4.2 Registered `"Move down"` and `"Drag to reorder, or use the move
      up/down buttons"` the same way; also registered `"item {position}"`
      (the fallback target label used by `moveButtonLabel()` when a row has
      no question/title text yet), which task 4 didn't anticipate but was
      needed to keep `check:l10n` clean.
- [x] 4.3 `npm run check:l10n`: 0 MISSING, 0 UNUSED, 0 UNWRAPPED. `npm run
      find:unwrapped`: 6 pre-existing candidates, none touching this change's
      new strings.

## 5. Manual verification

- **spec_ref**: `openspec/changes/keyboard-operable-reorder-controls/specs/content-management/spec.md#requirement-keyboard-operable-reorder-for-faq-and-content-block-items-cms-036`
- **files**: n/a (manual browser test)
- **acceptance_criteria**: keyboard-only pass through the FAQ/content-block reorder UI succeeds without a mouse
- [ ] 5.1 DEFERRED — needs a running Nextcloud instance + real keyboard/browser
      pass; not exercisable from this isolated, no-deploy worktree.
      `npm run build` and `eslint` are clean, and the swap logic (index-based
      array splice) was reasoned through for boundary correctness, but an
      actual Tab-key walkthrough is outstanding.
- [ ] 5.2 DEFERRED — same reason; the change is additive (drag handle + its
      `VueDraggable` wiring untouched aside from the new a11y attributes), so
      no regression is expected, but not yet confirmed live.

## Non-goals (tracked, not implemented here)

- `src/components/GenericObjectTable.vue:210-214` column-reorder drag handle has the same
  gap (no keyboard alternative). Flagged as a follow-up given its shared-component blast
  radius; not in this change's scope.
