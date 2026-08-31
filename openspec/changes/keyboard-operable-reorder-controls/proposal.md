---
kind: code
depends_on: []
---

# Proposal: keyboard-operable-reorder-controls

## Why

`src/modals/pageContents/PageContentForm.vue` — the CMS-036 "Page management
UI with embedded content blocks" surface — lets an editor reorder two block
types using `vue-draggable-plus`'s `<VueDraggable>` with **no keyboard-operable
alternative**:

- FAQ items (`PageContentForm.vue:92-99`): `<VueDraggable v-model="contentsItem.faqData" ...>`
  wraps a list of question/answer rows, each with a `<Drag class="drag-handle" :size="40" />`
  icon (line 95) as the only reorder affordance. The `Drag` icon has no
  `tabindex`, no `@keydown`/`@keydown.enter`/`@keydown.space` handler, and no
  `aria-label` — it is a pointer-only drag target, invisible to keyboard
  navigation and to screen readers (it doesn't even receive focus, so a
  screen-reader user tabbing through the form has no indication a reorder
  control exists at that position).
- Content blocks (`PageContentForm.vue:121-139`): the same pattern —
  `<VueDraggable v-model="contentsItem.contentBlocksData" ...>` with a
  `Drag` handle (line 125), same gaps.
- There is no alternate "move up" / "move down" button, no keyboard shortcut,
  and no `aria-live` region announcing an in-progress reorder — an admin
  using keyboard-only navigation, a screen reader, or switch-access hardware
  cannot reorder FAQ entries or content blocks on a Page at all today, even
  though the visual affordance (the handle) is present and its absence for
  these users is invisible to a sighted reviewer clicking through the UI.

This is a WCAG 2.1 AA violation of Success Criterion 2.1.1 (Keyboard): "All
functionality of the content is operable through a keyboard interface."
Reordering FAQ/content-block entries is content-authoring functionality with
no keyboard path.

A related, lower-severity instance exists in the reusable
`src/components/GenericObjectTable.vue:210-214` — `<VueDraggable
v-if="enableColumnReorder" ... draggable="> *:not(.staticColumn)">` around
the table header row, same drag-only reorder pattern for column order. This
is a cosmetic view preference (not content-authoring), lower impact, and
`GenericObjectTable` is a shared component reused across many list views —
fixing it is noted as a follow-up but out of scope for this change's task
list (see Non-goals).

## What Changes

- Add small "move up" / "move down" `NcButton` icon-buttons (with
  `aria-label`s, e.g. `t('opencatalogi', 'Move up')` /
  `t('opencatalogi', 'Move down')`, disabled at the first/last position) next
  to each FAQ item row and each content-block item row in
  `PageContentForm.vue`, wired to swap the item with its neighbor in
  `contentsItem.faqData` / `contentsItem.contentBlocksData`. This gives
  every reorder operation a keyboard- and screen-reader-operable path
  alongside the existing drag handle (drag-and-drop is retained for pointer
  users — this is additive, not a replacement).
- Add `tabindex="0"`, `role="button"`, an `aria-label`
  (`t('opencatalogi', 'Drag to reorder, or use the move up/down buttons')`),
  and `@keydown.enter`/`@keydown.space` handlers to the existing `Drag`
  handle icons so the handle itself is at least discoverable by keyboard
  navigation and announces the buttons as the keyboard alternative (screen
  readers cannot perform the drag gesture itself, hence the buttons in the
  first bullet being the actual operable path).
- Register the new i18n keys via `scripts/l10n-ai.js add` for every locale
  (per this app's l10n tooling rules — no hand-editing `l10n/*.js`).
- Not BREAKING — additive UI only.

## Non-goals

- Not touching `GenericObjectTable.vue`'s column-reorder drag handle in this
  change (lower severity, shared component, larger blast radius — tracked as
  a follow-up, not blocking this fix).
- Not replacing `vue-draggable-plus` or removing drag-and-drop for pointer
  users.
