# Tasks: keyboard-operable-reorder-controls

## 1. FAQ item reorder — keyboard alternative

- **spec_ref**: `openspec/changes/keyboard-operable-reorder-controls/specs/content-management/spec.md#requirement-keyboard-operable-reorder-for-faq-and-content-block-items-cms-036`
- **files**: `src/modals/pageContents/PageContentForm.vue`
- **acceptance_criteria**:
  - GIVEN a page with 3+ FAQ items WHEN a keyboard-only user tabs to a FAQ item's "Move up"/"Move down" buttons and presses Enter/Space THEN the item swaps position with its neighbor and the swap persists on save
  - GIVEN the first FAQ item THEN its "Move up" button is disabled (and vice versa for the last item's "Move down")
  - GIVEN a screen reader user THEN each move button has an accessible name identifying the action and the target item (e.g. "Move up: <question text or index>")
- [ ] 1.1 Add `moveFaqItem(index, direction)` method that swaps `contentsItem.faqData[index]` with `[index-1]`/`[index+1]`
- [ ] 1.2 Add two `NcButton` icon-only buttons per FAQ row (up/down), disabled at boundaries, calling `moveFaqItem`
- [ ] 1.3 Add `aria-label`s via `t('opencatalogi', 'Move up')` / `t('opencatalogi', 'Move down')`

## 2. Content-block reorder — keyboard alternative

- **spec_ref**: `openspec/changes/keyboard-operable-reorder-controls/specs/content-management/spec.md#requirement-keyboard-operable-reorder-for-faq-and-content-block-items-cms-036`
- **files**: `src/modals/pageContents/PageContentForm.vue`
- **acceptance_criteria**: same shape as Task 1, applied to `contentsItem.contentBlocksData`
- [ ] 2.1 Add `moveContentBlock(index, direction)` method (same swap logic)
- [ ] 2.2 Add the same up/down `NcButton` pair per content-block row
- [ ] 2.3 Reuse the same i18n keys added in Task 1 (`Move up`/`Move down` are shared, generic labels)

## 3. Make the drag handle itself keyboard-discoverable

- **spec_ref**: `openspec/changes/keyboard-operable-reorder-controls/specs/content-management/spec.md#requirement-keyboard-operable-reorder-for-faq-and-content-block-items-cms-036`
- **files**: `src/modals/pageContents/PageContentForm.vue`
- **acceptance_criteria**:
  - GIVEN a keyboard user tabs through a FAQ or content-block row THEN the `Drag` handle receives focus and its accessible name announces the keyboard alternative
- [ ] 3.1 Add `tabindex="0"`, `role="button"`, and `:aria-label="t('opencatalogi', 'Drag to reorder, or use the move up/down buttons')"` to both `Drag` handle instances (lines 95 and 125 at HEAD)
- [ ] 3.2 Add a no-op `@keydown.enter`/`@keydown.space` handler (or `.prevent` guard) so the handle doesn't trigger unrelated default behavior when focused and activated via keyboard

## 4. i18n registration

- **spec_ref**: `openspec/changes/keyboard-operable-reorder-controls/specs/content-management/spec.md#requirement-keyboard-operable-reorder-for-faq-and-content-block-items-cms-036`
- **files**: `l10n/*.js` (via tooling only)
- **acceptance_criteria**: `npm run check:l10n` reports zero MISSING/UNUSED for the new keys
- [ ] 4.1 `node scripts/l10n-ai.js add "Move up" --value en="Move up" --value nl="Omhoog verplaatsen" ...` for every locale reported by `list-locales` (read each locale's context before defaulting to an English literal, per this app's CLAUDE.md)
- [ ] 4.2 Same for `"Move down"` and `"Drag to reorder, or use the move up/down buttons"`
- [ ] 4.3 Run `npm run check:l10n` and `npm run find:unwrapped` to confirm clean

## 5. Manual verification

- **spec_ref**: `openspec/changes/keyboard-operable-reorder-controls/specs/content-management/spec.md#requirement-keyboard-operable-reorder-for-faq-and-content-block-items-cms-036`
- **files**: n/a (manual browser test)
- **acceptance_criteria**: keyboard-only pass through the FAQ/content-block reorder UI succeeds without a mouse
- [ ] 5.1 Tab through the Page content form using only the keyboard; confirm both new move-buttons are reachable and functional for FAQ and content-block lists
- [ ] 5.2 Confirm the existing drag-and-drop pointer flow is unchanged for mouse users

## Non-goals (tracked, not implemented here)

- `src/components/GenericObjectTable.vue:210-214` column-reorder drag handle has the same
  gap (no keyboard alternative). Flagged as a follow-up given its shared-component blast
  radius; not in this change's scope.
