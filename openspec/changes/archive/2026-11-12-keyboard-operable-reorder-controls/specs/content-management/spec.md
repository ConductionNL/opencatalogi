# Content Management — Keyboard-Operable Reorder Delta

**Spec refs**: `openspec/specs/content-management/spec.md` (CMS-036 — Page management UI with
embedded content blocks)
**Standards**: WCAG 2.1 AA, Success Criterion 2.1.1 (Keyboard)

## MODIFIED Requirements

### Requirement: Keyboard-operable reorder for FAQ and content-block items (CMS-036)

The Page content form's FAQ item list and content-block item list MUST provide a
keyboard-and-screen-reader-operable way to reorder items, in addition to the existing
pointer-only drag handle. Reordering MUST NOT depend solely on a drag gesture.

**Priority:** Must **Status:** Proposed

#### Scenario: Keyboard user reorders a FAQ item

- GIVEN a page with 3 or more FAQ items in the content form
- WHEN a keyboard-only user tabs to a FAQ item's "Move up" or "Move down" button and activates
  it with Enter or Space
- THEN the item MUST swap position with its neighbor
- AND the "Move up" button on the first item, and the "Move down" button on the last item, MUST
  be disabled

#### Scenario: Keyboard user reorders a content block

- GIVEN a page with 2 or more content blocks in the content form
- WHEN a keyboard-only user tabs to a content block's "Move up"/"Move down" button and activates it
- THEN the block MUST swap position with its neighbor, matching the FAQ-item scenario above

#### Scenario: Drag handle is keyboard-discoverable

- GIVEN a keyboard user tabs through a FAQ or content-block row
- WHEN focus reaches the drag handle
- THEN the handle MUST receive visible focus and expose an accessible name that identifies it as
  a reorder control and directs the user to the move buttons as the keyboard-operable alternative

#### Scenario: Pointer drag-and-drop is unchanged

- GIVEN a mouse/touch user
- WHEN they drag a FAQ item or content block by its handle
- THEN the existing `vue-draggable-plus` reorder behavior MUST be unchanged
